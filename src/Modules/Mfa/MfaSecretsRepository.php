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
     * Whether a user has completed enrollment (i.e. a row exists with
     * `enrolled_at IS NOT NULL`). Used by the Account-page status tab
     * and (Sprint 3) the login filter.
     */
    public function isEnrolled( int $wp_user_id ): bool {
        $row = $this->findByUserId( $wp_user_id );
        return $row !== null && ! empty( $row['enrolled_at'] );
    }

    /**
     * Create or replace the secret row for a user. Used by the
     * enrollment wizard's first step (Sprint 2) — generate a fresh
     * TOTP secret, store it, render the QR. Verification of the user's
     * first code (wizard step 3) flips `enrolled_at` via `markEnrolled()`.
     *
     * The TOTP secret is provided plaintext (base32) and encrypted on
     * the way in. Backup codes are provided pre-hashed (the wizard
     * generates them via `BackupCodesService::generate()` and shows the
     * plaintext to the user once).
     *
     * @param list<array{hash:string,used_at:null|string}> $backup_codes
     */
    public function upsertSecret( int $wp_user_id, string $secret_plaintext, array $backup_codes ): bool {
        if ( $wp_user_id <= 0 ) return false;
        if ( $secret_plaintext === '' ) return false;

        global $wpdb;
        $now = current_time( 'mysql' );

        $existing = $this->findByUserId( $wp_user_id );
        $data = [
            'club_id'             => CurrentClub::id(),
            'wp_user_id'          => $wp_user_id,
            'secret_encrypted'    => CredentialEncryption::encrypt( $secret_plaintext ),
            'backup_codes_hashed' => wp_json_encode( $backup_codes ),
            'updated_at'          => $now,
            'failed_attempts'     => 0,
            'locked_until'        => null,
        ];

        if ( $existing !== null ) {
            $ok = $wpdb->update(
                $this->table(),
                $data,
                [ 'id' => (int) $existing['id'] ]
            );
            return $ok !== false;
        }

        $data['uuid']       = wp_generate_uuid4();
        $data['created_at'] = $now;
        $ok = $wpdb->insert( $this->table(), $data );
        return $ok !== false;
    }

    /**
     * Flip `enrolled_at` to NOW after the wizard's verify step succeeds.
     * Returns false if no row exists.
     */
    public function markEnrolled( int $wp_user_id ): bool {
        $existing = $this->findByUserId( $wp_user_id );
        if ( $existing === null ) return false;

        global $wpdb;
        $now = current_time( 'mysql' );
        $ok = $wpdb->update(
            $this->table(),
            [
                'enrolled_at'      => $now,
                'last_verified_at' => $now,
                'updated_at'       => $now,
            ],
            [ 'id' => (int) $existing['id'] ]
        );
        return $ok !== false;
    }

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
     * by the caller (Sprint 3).
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
