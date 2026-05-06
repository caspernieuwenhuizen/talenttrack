<?php
namespace TT\Modules\Mfa\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Audit\MfaAuditEvents;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Modules\Mfa\Settings\MfaSettings;

/**
 * RememberDeviceCookie — 30-day signed cookie that lets a user skip the
 * MFA challenge on a known device (#0086 Workstream B Child 1, sprint 3).
 *
 * Cookie format:
 *
 *     tt_mfa_device = "<wp_user_id>|<device_token>|<signature>"
 *
 * - `wp_user_id` lets a multi-user browser pick the right device record.
 * - `device_token` is 32 hex bytes from `random_bytes()`, persisted in
 *   the user's `tt_user_mfa.remembered_devices` JSON array alongside an
 *   `expires_at` timestamp and a human label.
 * - `signature = wp_hash( "tt_mfa_device|" . $user_id . "|" . $device_token )`
 *   re-computed on verify and compared with `hash_equals()`.
 *
 * Mimics `Modules\Authorization\Impersonation\ImpersonationContext` for
 * its cookie shape — same `httponly` + `samesite=Lax` + `secure=is_ssl()`
 * conventions.
 *
 * Verification path:
 *   1. Cookie exists → split on `|` → check signature.
 *   2. Lookup token in user's `remembered_devices` JSON.
 *   3. If found and `expires_at > NOW()` → `last_used_at = NOW()`,
 *      cookie remains valid.
 *   4. Otherwise → cookie cleared, fallthrough to challenge.
 */
final class RememberDeviceCookie {

    public const COOKIE_NAME = 'tt_mfa_device';

    private const SIGN_NAMESPACE = 'tt_mfa_device';

    /**
     * Issue a fresh device token + cookie for `$wp_user_id`. Persists the
     * token (server-side only the token is kept; the signature lives in
     * the cookie). Audit-logged.
     */
    public static function setForUser( int $wp_user_id, string $device_label = '' ): void {
        if ( $wp_user_id <= 0 ) return;

        $settings = new MfaSettings();
        $days     = $settings->rememberDeviceDays();
        $expires_at_ts = time() + ( $days * DAY_IN_SECONDS );

        $token = bin2hex( random_bytes( 16 ) );
        $repo  = new MfaSecretsRepository();
        $ok    = $repo->appendRememberedDevice( $wp_user_id, [
            'signed_token' => $token,
            'device_label' => self::deriveLabel( $device_label ),
            'expires_at'   => gmdate( 'Y-m-d H:i:s', $expires_at_ts ),
            'last_used_at' => gmdate( 'Y-m-d H:i:s' ),
        ] );
        if ( ! $ok ) return;

        $signature   = self::sign( $wp_user_id, $token );
        $cookie_val  = $wp_user_id . '|' . $token . '|' . $signature;
        $cookie_path = defined( 'COOKIEPATH' ) && COOKIEPATH !== '' ? COOKIEPATH : '/';
        $cookie_dom  = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

        setcookie(
            self::COOKIE_NAME,
            $cookie_val,
            [
                'expires'  => $expires_at_ts,
                'path'     => $cookie_path,
                'domain'   => $cookie_dom,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        MfaAuditEvents::record( MfaAuditEvents::DEVICE_REMEMBERED, $wp_user_id, [
            'device_label' => self::deriveLabel( $device_label ),
            'expires_at'   => gmdate( 'Y-m-d H:i:s', $expires_at_ts ),
        ] );
    }

    /**
     * Read the cookie and check if it grants a valid skip-challenge for
     * `$wp_user_id`. Returns true on a clean match and bumps the device's
     * `last_used_at`. Returns false (and clears the cookie) on signature
     * mismatch / unknown token / expired token / wrong user.
     */
    public static function verifyForUser( int $wp_user_id ): bool {
        if ( $wp_user_id <= 0 ) return false;
        if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) return false;

        $raw = (string) wp_unslash( $_COOKIE[ self::COOKIE_NAME ] );
        $parts = explode( '|', $raw, 3 );
        if ( count( $parts ) !== 3 ) {
            self::clear();
            return false;
        }
        [ $cookie_user_id_str, $token, $signature ] = $parts;
        $cookie_user_id = (int) $cookie_user_id_str;
        if ( $cookie_user_id !== $wp_user_id ) {
            self::clear();
            return false;
        }
        if ( ! hash_equals( self::sign( $cookie_user_id, $token ), $signature ) ) {
            self::clear();
            return false;
        }

        $repo  = new MfaSecretsRepository();
        $entry = $repo->consumeRememberedDevice( $wp_user_id, $token );
        if ( $entry === null ) {
            // Token was valid signature-wise but no matching server-side
            // record (or expired). Clear the cookie so the user doesn't
            // keep retrying it.
            self::clear();
            return false;
        }
        return true;
    }

    /**
     * Drop the cookie immediately (sets an empty value with a past expiry).
     */
    public static function clear(): void {
        $cookie_path = defined( 'COOKIEPATH' ) && COOKIEPATH !== '' ? COOKIEPATH : '/';
        $cookie_dom  = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
        setcookie(
            self::COOKIE_NAME,
            '',
            [
                'expires'  => time() - 3600,
                'path'     => $cookie_path,
                'domain'   => $cookie_dom,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
        unset( $_COOKIE[ self::COOKIE_NAME ] );
    }

    private static function sign( int $user_id, string $token ): string {
        return wp_hash( self::SIGN_NAMESPACE . '|' . $user_id . '|' . $token );
    }

    /**
     * Best-effort device label. Falls back to the trimmed UA string when
     * no explicit label is provided. The user can rename / revoke from
     * the remembered-devices list (sprint 3 follow-up if needed).
     */
    private static function deriveLabel( string $explicit ): string {
        if ( $explicit !== '' ) return substr( $explicit, 0, 80 );
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        if ( $ua === '' ) return __( 'Unnamed device', 'talenttrack' );
        return substr( $ua, 0, 80 );
    }
}
