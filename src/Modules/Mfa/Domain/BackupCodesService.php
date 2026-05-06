<?php
namespace TT\Modules\Mfa\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BackupCodesService — single-use recovery codes (#0086 Workstream B Child 1).
 *
 * Backup codes exist for the case where the user loses their authenticator
 * device. The user prints / saves the 10 codes during enrollment; each one
 * is good for exactly one MFA verification, then it's spent.
 *
 * Format: `XXXX-XXXX-XXXX` — three groups of four uppercase alphanumerics
 * separated by dashes. 12 alphanumeric characters = ~62^12 ≈ 3 × 10^21
 * possibilities; brute-force across the ~5-attempts/5-min rate limit takes
 * ~10^15 years. Long enough.
 *
 * Storage: each code is hashed via WordPress's `wp_hash_password` (bcrypt)
 * before persisting. Verification uses `wp_check_password`. Same hash
 * choice as WordPress's own user passwords — defense in depth so a DB
 * leak doesn't expose the codes in plaintext.
 *
 * Single-use: when a code matches, its `used_at` field is set to NOW().
 * The repository persists the modified array; subsequent attempts to use
 * the same code fail. Sprint 3 wires this into the login flow.
 *
 * Storage shape (JSON, persisted in `tt_user_mfa.backup_codes_hashed`):
 *   [
 *     { "hash": "$P$B...",  "used_at": null },
 *     { "hash": "$P$B...",  "used_at": "2026-06-01 14:23:11" },
 *     ...
 *   ]
 */
final class BackupCodesService {

    /** Number of backup codes generated per enrollment. RFC-style standard. */
    public const CODE_COUNT = 10;

    /** Total length of the alphanumeric portion of each code (excluding dashes). */
    private const CODE_BODY_LENGTH = 12;

    /** Alphanumeric alphabet. Excludes I/O/0/1 to avoid display ambiguity. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Generate a fresh set of backup codes. Returns the plaintext codes
     * (caller is responsible for displaying them to the user once and then
     * never again) plus the hashed storage array.
     *
     * @return array{plaintext: list<string>, storage: list<array{hash:string, used_at:null}>}
     */
    public static function generate(): array {
        $plaintext = [];
        $storage   = [];

        for ( $i = 0; $i < self::CODE_COUNT; $i++ ) {
            $code = self::randomCode();
            $plaintext[] = $code;
            $storage[]   = [
                'hash'    => wp_hash_password( $code ),
                'used_at' => null,
            ];
        }

        return [
            'plaintext' => $plaintext,
            'storage'   => $storage,
        ];
    }

    /**
     * Verify a user-submitted backup code against the stored set.
     * Returns the index of the matching unused code, or -1 if no match.
     * Caller marks the index as used via `markUsed()` and persists the
     * modified array.
     *
     * Constant-time over the storage array — every entry is checked
     * even after a match is found, so a timing oracle can't reveal
     * which code matched (or whether any code matched at all).
     *
     * @param string $submitted The code the user typed (whitespace + dashes
     *                          tolerated; uppercased before comparison).
     * @param list<array{hash:string, used_at:null|string}> $storage The
     *                          stored hash list from `tt_user_mfa.backup_codes_hashed`.
     * @return int Index of the matching unused code, or -1 if none.
     */
    public static function verify( string $submitted, array $storage ): int {
        $submitted = self::normaliseSubmitted( $submitted );
        if ( $submitted === '' ) return -1;

        $match = -1;
        foreach ( $storage as $idx => $entry ) {
            $hash    = (string) ( $entry['hash'] ?? '' );
            $used_at = $entry['used_at'] ?? null;
            if ( $hash === '' ) continue;

            if ( $used_at === null && wp_check_password( $submitted, $hash ) && $match === -1 ) {
                $match = $idx;
                // Don't `break` — the loop continues to keep timing
                // independent of when the match occurred.
            }
        }
        return $match;
    }

    /**
     * Mark a code as used at the given timestamp. Returns the modified
     * storage array; caller persists.
     *
     * @param list<array{hash:string, used_at:null|string}> $storage
     * @return list<array{hash:string, used_at:null|string}>
     */
    public static function markUsed( array $storage, int $index, ?string $now = null ): array {
        if ( ! isset( $storage[ $index ] ) ) return $storage;
        $storage[ $index ]['used_at'] = $now ?? current_time( 'mysql' );
        return $storage;
    }

    /**
     * Count unused codes left in the storage array. Surface in the
     * Account-page MFA tab so the user knows when to regenerate.
     *
     * @param list<array{hash:string, used_at:null|string}> $storage
     */
    public static function unusedCount( array $storage ): int {
        $count = 0;
        foreach ( $storage as $entry ) {
            if ( ( $entry['used_at'] ?? null ) === null ) $count++;
        }
        return $count;
    }

    private static function randomCode(): string {
        $body = '';
        for ( $i = 0; $i < self::CODE_BODY_LENGTH; $i++ ) {
            $body .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
        }
        // Insert dashes every 4 chars: ABCD-EFGH-IJKL.
        return implode( '-', str_split( $body, 4 ) );
    }

    private static function normaliseSubmitted( string $submitted ): string {
        $stripped = preg_replace( '/[\s\-]+/', '', $submitted );
        if ( $stripped === null ) return '';
        $upper = strtoupper( $stripped );
        if ( strlen( $upper ) !== self::CODE_BODY_LENGTH ) return '';
        // Reject any character not in the alphabet — saves wp_check_password
        // calls on obviously-bad input.
        if ( strspn( $upper, self::ALPHABET ) !== strlen( $upper ) ) return '';
        // Reformat with dashes so the verify call hashes the canonical form.
        return implode( '-', str_split( $upper, 4 ) );
    }
}
