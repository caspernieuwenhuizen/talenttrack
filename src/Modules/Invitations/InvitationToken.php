<?php
namespace TT\Modules\Invitations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * InvitationToken — secure random token generator for invitation URLs.
 *
 * 32-char URL-safe (base64url-encoded 24-byte random) — ~192 bits of
 * entropy, well above any practical brute-force threshold, and short
 * enough to not word-wrap in WhatsApp share previews.
 *
 * `random_bytes()` is preferred (PHP 7+ built-in). On hosts without
 * a CSPRNG it falls back to `wp_generate_password()` which uses the
 * OS source via WordPress's own CSPRNG.
 */
final class InvitationToken {

    private const TOKEN_BYTES = 24;

    public static function generate(): string {
        if ( function_exists( 'random_bytes' ) ) {
            try {
                $bytes = random_bytes( self::TOKEN_BYTES );
                return self::base64UrlEncode( $bytes );
            } catch ( \Throwable $e ) {
                // Fall through to WP's CSPRNG.
            }
        }
        return wp_generate_password( 32, false, false );
    }

    public static function isValidShape( string $token ): bool {
        // 32-char URL-safe alphabet only.
        return (bool) preg_match( '/^[A-Za-z0-9_\-]{16,64}$/', $token );
    }

    private static function base64UrlEncode( string $bytes ): string {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }
}
