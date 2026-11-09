<?php
namespace TT\Modules\Mfa;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\CredentialEncryption;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MfaSecretsRepository — CRUD on `tt_user_mfa` (#0086 Workstream B Child 1).
 *
 * One row per (`wp_user_id`, `club_id`) pair. The shared TOTP secret
 * is encrypted at rest via `CredentialEncryption` (AES-256-GCM under
 * `wp_salt('auth')`); backup codes and remembered devices are stored
 * as JSON.
 *
 * Sprint 1 ships the read + write surface used by the Account-page
 * status tab and (in Sprint 2) the enrollment wizard. Sprint 3 wires
 * the rate-limit + lockout fields (`failed_attempts`, `locked_until`)
 * into the login flow.
 *
 * Read shape returned to callers (decrypted secret, JSON parsed):
 *   [
 *     'id' => int,
 *     'wp_user_id' => int,
 *     'club_id' => int,
 *     'uuid' => string,
 *     'secret' => string,                       // base32, decrypted (or '' if not enrolled)
 *     'backup_codes' => list<{hash, used_at}>,  // empty array when not enrolled
 *     'remembered_devices' => list<...>,        // empty array when none
 *     'enrolled_at' => string|null,             // 'Y-m-d H:i:s' or null
 *     'last_verified_at' => string|null,
 *     'failed_attempts' => int,
 *     'locked_until' => string|null,
 *   ]
 */
final class MfaSecretsRepository {

    /** A staged secret older than this is treated as absent (the wizard's own TTL). */
    public const PENDING_TTL = HOUR_IN_SECONDS;

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_user_mfa';
    }

    /**
     * Find the row for a WP user in the current club. Returns null when
     * the user has never enrolled (no row exists).
     *
     * @return array<string,mixed>|null
     */
    public function findByUserId( int $wp_user_id ): ?array {
        if ( $wp_user_id <= 0 ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE wp_user_id = %d AND club_id = %d",
            $wp_user_id,
            CurrentClub::id()
        ), ARRAY_A );
        if ( ! is_array( $row ) ) return null;
        return $this->hydrate( $row );
    }

    /**
     * #1392 — every enrollment row for the current club, for the
     * `wp tt mfa status` diagnostic. Narrow shape: user id, enrolled
     * timestamp, lockout state, failed-attempt counter.
     *
     * @return list<array{wp_user_id:int, enrolled_at:?string, locked_until:?string, failed_attempts:int}>
     */
    public function listEnrollments(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT wp_user_id, enrolled_at, locked_until, failed_attempts
               FROM {$this->table()}
              WHERE club_id = %d
              ORDER BY wp_user_id ASC",
            CurrentClub::id()
        ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'wp_user_id'      => (int) $r['wp_user_id'],
                'enrolled_at'     => $r['enrolled_at'] !== null ? (string) $r['enrolled_at'] : null,
                'locked_until'    => $r['locked_until'] !== null ? (string) $r['locked_until'] : null,
                'failed_attempts' => (int) ( $r['failed_attempts'] ?? 0 ),
            ];
        }
        return $out;
    }

    /**
     * Whether a user has completed enrollment (i.e. a row exists with
     * `enrolled_at IS NOT NULL`). Used by the Account-page status tab
     * and (Sprint 3) the login filter.
     */
    public function isEnrolled( int $wp_user_id ): bool {
        $row = $this->findByUserId( $wp_user_id );
        return $row !== null && ! empty( $row['enrolled_at'] );
    }

    /**
     * Stage a new secret beside the working one. Only the pending columns
     * are written; the working secret, backup codes, `enrolled_at` and the
     * lockout counters stay as they are. Any staged backup codes are
     * dropped, because they belonged to the previous staged secret. A
     * first enrolment creates the row with only the pending columns set.
     */
    public function stagePendingSecret( int $wp_user_id, string $secret_plaintext ): bool {
        if ( $wp_user_id <= 0 || $secret_plaintext === '' ) return false;

        global $wpdb;
        $now  = current_time( 'mysql' );
        $data = [
            'pending_secret_encrypted'    => CredentialEncryption::encrypt( $secret_plaintext ),
            'pending_backup_codes_hashed' => null,
            'pending_created_at'          => gmdate( 'Y-m-d H:i:s' ),
            'updated_at'                  => $now,
        ];

        $existing = $this->findByUserId( $wp_user_id );
        if ( $existing !== null ) {
            return $wpdb->update( $this->table(), $data, [ 'id' => (int) $existing['id'] ] ) !== false;
        }

        $data['club_id']    = CurrentClub::id();
        $data['wp_user_id'] = $wp_user_id;
        $data['uuid']       = wp_generate_uuid4();
        $data['created_at'] = $now;
        return $wpdb->insert( $this->table(), $data ) !== false;
    }

    /**
     * Stage the hashed backup codes that go with the staged secret.
     * Refused when no secret is staged.
     *
     * @param list<array{hash:string,used_at:null|string}> $backup_codes
     */
    public function stagePendingBackupCodes( int $wp_user_id, array $backup_codes ): bool {
        $existing = $this->findByUserId( $wp_user_id );
        if ( $existing === null ) return false;
        global $wpdb;
        $ok = $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table()}
                SET pending_backup_codes_hashed = %s, updated_at = %s
              WHERE id = %d AND pending_secret_encrypted IS NOT NULL",
            (string) wp_json_encode( $backup_codes ),
            current_time( 'mysql' ),
            (int) $existing['id']
        ) );
        return is_int( $ok ) && $ok > 0;
    }

    /**
     * The staged secret, decrypted, or '' when none is staged or it is
     * older than `PENDING_TTL`. Kept out of `hydrate()` on purpose: only
     * the enrolment wizard reads it.
     */
    public function pendingSecret( int $wp_user_id ): string {
        $existing = $this->findByUserId( $wp_user_id );
        if ( $existing === null ) return '';
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT pending_secret_encrypted FROM {$this->table()}
              WHERE id = %d
                AND pending_secret_encrypted IS NOT NULL
                AND pending_created_at >= %s",
            (int) $existing['id'],
            self::pendingCutoff()
        ), ARRAY_A );
        if ( ! is_array( $row ) ) return '';
        return CredentialEncryption::decrypt( (string) $row['pending_secret_encrypted'] );
    }

    /**
     * Promote the staged secret and backup codes into the working columns
     * and mark the row enrolled, in one statement. Nothing happens unless
     * a secret and its codes are staged and the secret is inside
     * `PENDING_TTL`. Returns false when no row changed.
     *
     * Remembered devices are dropped with the old secret: a device trusted
     * under the setup being replaced is asked for a code again.
     *
     * There is no separate "clear pending": an abandoned staging is inert
     * after `PENDING_TTL` and is overwritten by the next one, and every
     * reset goes through `disable()`, which deletes the row.
     */
    public function promotePending( int $wp_user_id ): bool {
        $existing = $this->findByUserId( $wp_user_id );
        if ( $existing === null ) return false;
        global $wpdb;
        $now = current_time( 'mysql' );
        $ok  = $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table()}
                SET secret_encrypted = pending_secret_encrypted,
                    backup_codes_hashed = pending_backup_codes_hashed,
                    remembered_devices = NULL,
                    enrolled_at = %s,
                    last_verified_at = %s,
                    updated_at = %s,
                    pending_secret_encrypted = NULL,
                    pending_backup_codes_hashed = NULL,
                    pending_created_at = NULL
              WHERE id = %d
                AND pending_secret_encrypted IS NOT NULL
                AND pending_backup_codes_hashed IS NOT NULL
                AND pending_created_at >= %s",
            $now,
            $now,
            $now,
            (int) $existing['id'],
            self::pendingCutoff()
        ) );
        return is_int( $ok ) && $ok > 0;
    }

    private static function pendingCutoff(): string {
        return gmdate( 'Y-m-d H:i:s', time() - self::PENDING_TTL );
    }

    /*
     * There is no direct write of the working secret. An enrolment, first
     * or replacement, is staged and then promoted (`stagePendingSecret()`,
     * `stagePendingBackupCodes()`, `promotePending()`), so the wizard's
     * checks are the only way to it.
     */

    /**
     * Persist a verification success — bumps `last_verified_at`,
     * resets `failed_attempts`, clears any `locked_until`.
     */
    public function recordVerification( int $wp_user_id ): bool {
        $existing = $this->findByUserId( $wp_user_id );
        if ( $existing === null ) return false;

        global $wpdb;
        $now = current_time( 'mysql' );
        $ok = $wpdb->update(
            $this->table(),
            [
                'last_verified_at' => $now,
                'failed_attempts'  => 0,
                'locked_until'     => null,
                'updated_at'       => $now,
            ],
            [ 'id' => (int) $existing['id'] ]
        );
        return $ok !== false;
    }

    /**
     * Persist the modified backup-codes array after a successful
     * single-use redemption. Caller derives the new array via
     * `BackupCodesService::markUsed()`.
     *
     * @param list<array{hash:string,used_at:null|string}> $backup_codes
     */
    public function updateBackupCodes( int $wp_user_id, array $backup_codes ): bool {
        $existing = $this->findByUserId( $wp_user_id );
        if ( $existing === null ) return false;

        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            [
                'backup_codes_hashed' => wp_json_encode( $backup_codes ),
                'updated_at'          => current_time( 'mysql' ),
            ],
            [ 'id' => (int) $existing['id'] ]
        );
        return $ok !== false;
    }

    /**
     * Sprint 3 — record a failed verification attempt. Increments
     * `failed_attempts`; if the count reaches `$max_attempts`, sets
     * `locked_until = NOW() + lockout_minutes`. The caller (RateLimiter)
     * decides which thresholds to apply.
     *
     * Returns the updated `failed_attempts` count, or 0 if the row is
     * missing (caller should treat this as "not enrolled").
     */
    public function recordFailedAttempt( int $wp_user_id, int $max_attempts, int $lockout_minutes ): int {
        $existing = $this->findByUserId( $wp_user_id );
        if ( $existing === null ) return 0;

        global $wpdb;
        $now = current_time( 'mysql' );
        $new_count = (int) ( $existing['failed_attempts'] ?? 0 ) + 1;

        $update = [
            'failed_attempts' => $new_count,
            'updated_at'      => $now,
        ];
        if ( $new_count >= $max_attempts ) {
            $update['locked_until'] = gmdate( 'Y-m-d H:i:s', time() + ( $lockout_minutes * MINUTE_IN_SECONDS ) );
        }
        $wpdb->update(
            $this->table(),
            $update,
            [ 'id' => (int) $existing['id'] ]
        );
        return $new_count;
    }

    /**
     * Sprint 3 — is the user currently in lockout? Compares stored
     * `locked_until` against now (UTC). Returns the timestamp string
     * when locked, null otherwise.
     */
    public function lockoutUntil( int $wp_user_id ): ?string {
        $existing = $this->findByUserId( $wp_user_id );
        if ( $existing === null ) return null;
        $until = (string) ( $existing['locked_until'] ?? '' );
        if ( $until === '' ) return null;
        if ( strtotime( $until . ' UTC' ) <= time() ) return null;
        return $until;
    }

    /**
     * Sprint 3 — append a remembered-device entry. The `signed_token`
     * is the HMAC-signed random token issued by `RememberDeviceCookie`;
     * server-side only the token + metadata are kept (the signature in
     * the cookie is recomputed on each verify).
     *
     * @param array{signed_token:string, device_label:string, expires_at:string, last_used_at:string} $device
     */
    public function appendRememberedDevice( int $wp_user_id, array $device ): bool {
        $existing = $this->findByUserId( $wp_user_id );
        if ( $existing === null ) return false;

        $remembered = (array) ( $existing['remembered_devices'] ?? [] );
        // Drop any expired entries on the way in so the JSON doesn't grow forever.
        $now_ts = time();
        $remembered = array_values( array_filter( $remembered, static function ( $entry ) use ( $now_ts ) {
            $exp = (string) ( $entry['expires_at'] ?? '' );
            return $exp !== '' && strtotime( $exp . ' UTC' ) > $now_ts;
        } ) );
        $remembered[] = $device;

        global $wpdb;
        $wpdb->update(
            $this->table(),
            [
                'remembered_devices' => wp_json_encode( $remembered ),
                'updated_at'         => current_time( 'mysql' ),
            ],
            [ 'id' => (int) $existing['id'] ]
        );
        return true;
    }

    /**
     * Sprint 3 — find a remembered-device entry by its token. Returns the
     * entry shape if found and unexpired, null otherwise. Used by the
     * cookie-verification path to decide whether to skip the challenge.
     *
     * Has a side-effect on success: bumps `last_used_at` on the matching
     * entry. Audit-logged by the caller.
     *
     * @return array<string,mixed>|null
     */
    public function consumeRememberedDevice( int $wp_user_id, string $token ): ?array {
        $existing = $this->findByUserId( $wp_user_id );
        if ( $existing === null ) return null;

        $remembered = (array) ( $existing['remembered_devices'] ?? [] );
        if ( empty( $remembered ) ) return null;

        $now_ts = time();
        $matched_idx = -1;
        foreach ( $remembered as $idx => $entry ) {
            if ( ! is_array( $entry ) ) continue;
            if ( ! hash_equals( (string) ( $entry['signed_token'] ?? '' ), $token ) ) continue;
            $exp = (string) ( $entry['expires_at'] ?? '' );
            if ( $exp === '' || strtotime( $exp . ' UTC' ) <= $now_ts ) continue;
            $matched_idx = $idx;
            break;
        }
        if ( $matched_idx === -1 ) return null;

        $remembered[ $matched_idx ]['last_used_at'] = gmdate( 'Y-m-d H:i:s' );

        global $wpdb;
        $wpdb->update(
            $this->table(),
            [
                'remembered_devices' => wp_json_encode( $remembered ),
                'updated_at'         => current_time( 'mysql' ),
            ],
            [ 'id' => (int) $existing['id'] ]
        );
        return $remembered[ $matched_idx ];
    }

    /**
     * Sprint 3 — revoke a single remembered-device entry, or all entries
     * when `$token` is empty. Returns the number of entries removed.
     */
    public function revokeRememberedDevices( int $wp_user_id, string $token = '' ): int {
        $existing = $this->findByUserId( $wp_user_id );
        if ( $existing === null ) return 0;

        $remembered = (array) ( $existing['remembered_devices'] ?? [] );
        if ( empty( $remembered ) ) return 0;

        $before = count( $remembered );
        if ( $token === '' ) {
            $remembered = [];
        } else {
            $remembered = array_values( array_filter(
                $remembered,
                static function ( $entry ) use ( $token ) {
                    return ! is_array( $entry )
                        || ! hash_equals( (string) ( $entry['signed_token'] ?? '' ), $token );
                }
            ) );
        }
        $removed = $before - count( $remembered );

        global $wpdb;
        $wpdb->update(
            $this->table(),
            [
                'remembered_devices' => wp_json_encode( $remembered ),
                'updated_at'         => current_time( 'mysql' ),
            ],
            [ 'id' => (int) $existing['id'] ]
        );
        return $removed;
    }

    /**
     * Disable MFA for a user — deletes the row entirely. Used when an
     * admin needs to recover a user who's locked themselves out
     * (lost their authenticator + lost their backup codes). Audit-logged
     * by the caller (Sprint 3). The operator reset, the user's own "turn
     * off" and `wp tt mfa reset` all come through here, so a staged
     * secret and its codes go with the row.
     */
    public function disable( int $wp_user_id ): bool {
        if ( $wp_user_id <= 0 ) return false;
        global $wpdb;
        $ok = $wpdb->delete(
            $this->table(),
            [
                'wp_user_id' => $wp_user_id,
                'club_id'    => CurrentClub::id(),
            ]
        );
        return $ok !== false;
    }

    /**
     * Decrypt the stored row + parse JSON columns into PHP arrays. The
     * single shape consumers see — they never deal with the raw row.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate( array $row ): array {
        $secret_envelope = (string) ( $row['secret_encrypted'] ?? '' );
        $secret = $secret_envelope === ''
            ? ''
            : CredentialEncryption::decrypt( $secret_envelope );

        $backup_codes = [];
        $backup_json  = (string) ( $row['backup_codes_hashed'] ?? '' );
        if ( $backup_json !== '' ) {
            $decoded = json_decode( $backup_json, true );
            if ( is_array( $decoded ) ) $backup_codes = $decoded;
        }

        $remembered = [];
        $remembered_json = (string) ( $row['remembered_devices'] ?? '' );
        if ( $remembered_json !== '' ) {
            $decoded = json_decode( $remembered_json, true );
            if ( is_array( $decoded ) ) $remembered = $decoded;
        }

        return [
            'id'                 => (int) $row['id'],
            'wp_user_id'         => (int) $row['wp_user_id'],
            'club_id'            => (int) $row['club_id'],
            'uuid'               => (string) ( $row['uuid'] ?? '' ),
            'secret'             => $secret,
            'backup_codes'       => $backup_codes,
            'remembered_devices' => $remembered,
            'enrolled_at'        => $row['enrolled_at'] ?? null,
            'last_verified_at'   => $row['last_verified_at'] ?? null,
            'failed_attempts'    => (int) ( $row['failed_attempts'] ?? 0 ),
            'locked_until'       => $row['locked_until'] ?? null,
            'created_at'         => $row['created_at'] ?? null,
            'updated_at'         => $row['updated_at'] ?? null,
        ];
    }
}
