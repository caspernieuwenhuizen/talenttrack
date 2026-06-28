<?php
namespace TT\Modules\Strava;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\CredentialEncryption;

/**
 * StravaConfig (#2056) — per-club Strava *application* settings.
 *
 * These are the developer-app credentials registered once with Strava
 * (one OAuth app, one webhook subscription per app), not per-player
 * tokens — those live encrypted in `tt_player_strava_connections` via
 * `ConnectionRepository`. The client secret is stored encrypted at rest
 * via `CredentialEncryption`, mirroring `Spond\CredentialsManager`; the
 * client id is not secret on its own and is stored in plaintext.
 *
 * All keys live under `strava.*` in `tt_config`, which is `club_id`-
 * scoped per the SaaS-readiness baseline (CLAUDE.md §4).
 */
final class StravaConfig {

    public const KEY_CLIENT_ID     = 'strava.client_id';
    public const KEY_CLIENT_SECRET = 'strava.client_secret_enc';
    public const KEY_API_BASE      = 'strava.api_base_url';
    public const KEY_OAUTH_BASE    = 'strava.oauth_base_url';

    public const DEFAULT_API_BASE   = 'https://www.strava.com/api/v3';
    public const DEFAULT_OAUTH_BASE = 'https://www.strava.com/oauth';

    /**
     * Read scope — Gate 1 (no HR): `activity:read_all` returns the
     * non-biometric activity summary fields the ingest maps (distance,
     * moving/elapsed time, speed, elevation). Heart-rate streams are a
     * separate concern we never request, store, or surface.
     */
    public const SCOPE = 'activity:read_all';

    public static function clientId(): string {
        return QueryHelpers::get_config( self::KEY_CLIENT_ID );
    }

    public static function clientSecret(): string {
        $stored = QueryHelpers::get_config( self::KEY_CLIENT_SECRET );
        if ( $stored === '' ) return '';
        return (string) CredentialEncryption::decrypt( $stored );
    }

    public static function hasCredentials(): bool {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    /**
     * Persist or rotate the app credentials. A blank secret keeps the
     * stored one; a blank id clears both. The secret is encrypted at
     * rest and never echoed back from any REST response.
     */
    public static function saveCredentials( string $client_id, string $client_secret ): void {
        QueryHelpers::set_config( self::KEY_CLIENT_ID, $client_id );
        if ( $client_secret !== '' ) {
            QueryHelpers::set_config(
                self::KEY_CLIENT_SECRET,
                (string) CredentialEncryption::encrypt( $client_secret )
            );
        }
        if ( $client_id === '' ) {
            QueryHelpers::set_config( self::KEY_CLIENT_SECRET, '' );
        }
    }

    public static function apiBaseUrl(): string {
        $override = QueryHelpers::get_config( self::KEY_API_BASE );
        return $override !== '' ? untrailingslashit( $override ) : self::DEFAULT_API_BASE;
    }

    public static function oauthBaseUrl(): string {
        $override = QueryHelpers::get_config( self::KEY_OAUTH_BASE );
        return $override !== '' ? untrailingslashit( $override ) : self::DEFAULT_OAUTH_BASE;
    }

    /**
     * The single fixed callback Strava redirects the browser to after
     * consent. Strava matches the host of this against the app's
     * Authorization Callback Domain, so it must stay stable — the
     * connecting player id rides in the signed `state`, never the path.
     */
    public static function redirectUri(): string {
        return rest_url( 'talenttrack/v1/strava/callback' );
    }
}
