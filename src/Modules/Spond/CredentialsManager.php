<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\CredentialEncryption;

/**
 * CredentialsManager (#0062) — per-club Spond account storage.
 *
 * The JSON-API switchover replaces per-team iCal URLs with a single
 * club-wide Spond login. Email is stored in plaintext in `tt_config`
 * (it's not secret on its own); password is stored encrypted at rest
 * via `CredentialEncryption`, mirroring how `Push/VapidKeyManager`
 * handles the VAPID private key.
 *
 * Both keys live under `spond.credentials.*` in `tt_config`, which is
 * `club_id`-scoped per the SaaS-readiness baseline (#0052).
 *
 * The token cache (also in `tt_config`) is short-lived; rotating
 * `wp_salt('auth')` invalidates both the password and any cached
 * token (decrypt returns an empty string), forcing re-login.
 */
final class CredentialsManager {

    public const KEY_EMAIL          = 'spond.credentials.email';
    public const KEY_PASSWORD       = 'spond.credentials.password_enc';
    public const KEY_TOKEN          = 'spond.credentials.token_enc';
    public const KEY_TOKEN_EXPIRES  = 'spond.credentials.token_expires_at';

    /**
     * 12h cache TTL — defensive against Spond silently shortening
     * its own token validity. The 401-retry-once path in `SpondClient`
     * handles staler-than-expected expiry from the upstream side.
     */
    public const TOKEN_CACHE_SECONDS = 12 * HOUR_IN_SECONDS;

    public static function hasCredentials(): bool {
        return self::getEmail() !== '' && self::getPassword() !== '';
    }

    public static function getEmail(): string {
        return QueryHelpers::get_config( self::KEY_EMAIL );
    }

    public static function getPassword(): string {
        $stored = QueryHelpers::get_config( self::KEY_PASSWORD );
        if ( $stored === '' ) return '';
        return (string) CredentialEncryption::decrypt( $stored );
    }

    /**
     * Persist or rotate. Empty values clear the slot. Token cache is
     * cleared on every credential write so a password change forces a
     * fresh login on the next sync tick.
     */
    public static function save( string $email, string $password ): void {
        QueryHelpers::set_config( self::KEY_EMAIL, $email );
        QueryHelpers::set_config(
            self::KEY_PASSWORD,
            $password === '' ? '' : (string) CredentialEncryption::encrypt( $password )
        );
        self::clearToken();
    }

    public static function clear(): void {
        QueryHelpers::set_config( self::KEY_EMAIL,    '' );
        QueryHelpers::set_config( self::KEY_PASSWORD, '' );
        self::clearToken();
    }

    // ---- Token cache --------------------------------------------------

    public static function getCachedToken(): string {
        $expires = (int) QueryHelpers::get_config( self::KEY_TOKEN_EXPIRES, '0' );
        if ( $expires <= time() ) return '';
        $stored = QueryHelpers::get_config( self::KEY_TOKEN );
        if ( $stored === '' ) return '';
        return (string) CredentialEncryption::decrypt( $stored );
    }

    public static function cacheToken( string $token, int $ttl_seconds = self::TOKEN_CACHE_SECONDS ): void {
        QueryHelpers::set_config(
            self::KEY_TOKEN,
            $token === '' ? '' : (string) CredentialEncryption::encrypt( $token )
        );
        QueryHelpers::set_config(
            self::KEY_TOKEN_EXPIRES,
            (string) ( time() + max( 60, $ttl_seconds ) )
        );
    }

    public static function clearToken(): void {
        QueryHelpers::set_config( self::KEY_TOKEN,         '' );
        QueryHelpers::set_config( self::KEY_TOKEN_EXPIRES, '0' );
    }
}
