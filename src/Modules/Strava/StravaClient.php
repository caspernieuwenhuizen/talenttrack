<?php
namespace TT\Modules\Strava;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;

/**
 * StravaClient (#2056) — thin HTTP client for Strava's OAuth + API.
 *
 * Mirrors `Spond\SpondClient`'s shape (configurable base URL, retry
 * discipline, structured result arrays) but speaks OAuth 2.0. This
 * child ships the token *exchange* (authorization_code grant) and
 * *deauthorize* calls; the refresh-token grant and activity fetch land
 * with their consuming children (#2057, #2058) on the same
 * `tokenRequest()` / `request()` primitives.
 *
 * Every result is `['ok'=>bool, ...]` so callers never have to unwrap a
 * WP_Error — failures carry an `error_code` + `error_message`.
 */
final class StravaClient {

    public const TIMEOUT_SECONDS = 15;

    /** User-agent format; the version is interpolated in `userAgent()`. */
    private const USER_AGENT_FORMAT = 'TalentTrack/%s (+https://github.com/caspernieuwenhuizen/talenttrack)';

    /**
     * Exchange an authorization `code` for an access + refresh token set.
     *
     * @return array{ok:bool,access_token?:string,refresh_token?:string,expires_at?:int,athlete_id?:int,error_code?:string,error_message?:string}
     */
    public static function exchangeCode( string $code ): array {
        $res = self::tokenRequest( [
            'client_id'     => StravaConfig::clientId(),
            'client_secret' => StravaConfig::clientSecret(),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
        ] );
        if ( empty( $res['ok'] ) ) {
            return $res;
        }
        $body = $res['body'];
        return [
            'ok'            => true,
            'access_token'  => (string) ( $body['access_token'] ?? '' ),
            'refresh_token' => (string) ( $body['refresh_token'] ?? '' ),
            'expires_at'    => (int) ( $body['expires_at'] ?? 0 ),
            'athlete_id'    => (int) ( $body['athlete']['id'] ?? 0 ),
        ];
    }

    /**
     * Exchange a refresh token for a fresh access + refresh token pair.
     * Strava rotates the refresh token on every call and kills the old
     * one immediately, so the caller MUST persist `refresh_token` here,
     * not just the access token, or the next refresh is locked out
     * (#2057 — `ConnectionRepository::rotateTokens` does this atomically).
     *
     * @return array{ok:bool,access_token?:string,refresh_token?:string,expires_at?:int,http_code?:int,error_code?:string,error_message?:string}
     */
    public static function refreshToken( string $refresh_token ): array {
        $res = self::tokenRequest( [
            'client_id'     => StravaConfig::clientId(),
            'client_secret' => StravaConfig::clientSecret(),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ] );
        if ( empty( $res['ok'] ) ) {
            return $res;
        }
        $body = $res['body'];
        return [
            'ok'            => true,
            'access_token'  => (string) ( $body['access_token'] ?? '' ),
            'refresh_token' => (string) ( $body['refresh_token'] ?? '' ),
            'expires_at'    => (int) ( $body['expires_at'] ?? 0 ),
        ];
    }

    /**
     * Revoke an athlete's grant (Strava `/oauth/deauthorize`). Best
     * effort — a failure here is logged but the local disconnect still
     * proceeds so a player is never stuck "connected" in our UI.
     */
    public static function revoke( string $access_token ): bool {
        if ( $access_token === '' ) return false;

        $response = wp_remote_post( StravaConfig::oauthBaseUrl() . '/deauthorize', [
            'timeout'    => self::TIMEOUT_SECONDS,
            'user-agent' => self::userAgent(),
            'body'       => [ 'access_token' => $access_token ],
        ] );

        if ( is_wp_error( $response ) ) {
            Logger::warning( 'strava.revoke.transport_error', [ 'error' => $response->get_error_message() ] );
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            Logger::warning( 'strava.revoke.http_error', [ 'http_code' => $code ] );
            return false;
        }
        return true;
    }

    /**
     * POST to the OAuth token endpoint. Shared by code-exchange and the
     * refresh grant (#2057).
     *
     * @param array<string,scalar> $params
     * @return array{ok:bool,body?:array<string,mixed>,error_code?:string,error_message?:string,http_code?:int}
     */
    public static function tokenRequest( array $params ): array {
        $response = wp_remote_post( StravaConfig::oauthBaseUrl() . '/token', [
            'timeout'    => self::TIMEOUT_SECONDS,
            'user-agent' => self::userAgent(),
            'body'       => $params,
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'ok'            => false,
                'error_code'    => 'transport_error',
                'error_message' => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $body = is_array( $body ) ? $body : [];

        if ( $code < 200 || $code >= 300 ) {
            return [
                'ok'            => false,
                'http_code'     => $code,
                'error_code'    => 'token_http_' . $code,
                'error_message' => (string) ( $body['message'] ?? 'Strava token request failed.' ),
            ];
        }

        return [ 'ok' => true, 'body' => $body, 'http_code' => $code ];
    }

    /**
     * Fetch a single activity's detail with a player's Bearer token
     * (Strava `GET /api/v3/activities/{id}`). The response is the activity
     * summary; the ingest maps the non-HR fields only (#2058).
     *
     * @return array{ok:bool,body?:array<string,mixed>,http_code?:int,error_code?:string,error_message?:string}
     */
    public static function getActivity( string $access_token, int $activity_id ): array {
        if ( $access_token === '' || $activity_id <= 0 ) {
            return [ 'ok' => false, 'error_code' => 'bad_request' ];
        }

        $response = wp_remote_get(
            StravaConfig::apiBaseUrl() . '/activities/' . $activity_id,
            [
                'timeout'    => self::TIMEOUT_SECONDS,
                'user-agent' => self::userAgent(),
                'headers'    => [ 'Authorization' => 'Bearer ' . $access_token ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error_code' => 'transport_error', 'error_message' => $response->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $body = is_array( $body ) ? $body : [];

        if ( $code < 200 || $code >= 300 ) {
            return [ 'ok' => false, 'http_code' => $code, 'error_code' => 'activity_http_' . $code ];
        }
        return [ 'ok' => true, 'body' => $body, 'http_code' => $code ];
    }

    // ---- Webhook push subscription (one per application) ----------------

    /**
     * Create the single club-wide push subscription. Strava immediately
     * validates it by GETting our callback with a `hub.challenge`
     * handshake, so the webhook route must already be live. Returns the
     * created subscription id on success.
     *
     * @return array{ok:bool,id?:int,http_code?:int,error_code?:string,error_message?:string}
     */
    public static function createSubscription( string $callback_url, string $verify_token ): array {
        $response = wp_remote_post( StravaConfig::apiBaseUrl() . '/push_subscriptions', [
            'timeout'    => self::TIMEOUT_SECONDS,
            'user-agent' => self::userAgent(),
            'body'       => [
                'client_id'     => StravaConfig::clientId(),
                'client_secret' => StravaConfig::clientSecret(),
                'callback_url'  => $callback_url,
                'verify_token'  => $verify_token,
            ],
        ] );
        return self::subscriptionResult( $response );
    }

    /**
     * View the application's current push subscription. Strava allows at
     * most one per app, so `GET /push_subscriptions?client_id&client_secret`
     * returns a list of 0 or 1. Lets the operator console reconcile its
     * stored id with Strava's real state — adopt an existing subscription
     * rather than dead-ending on the one-per-app constraint, and self-heal
     * a stored id that drifted (e.g. deleted from Strava's side).
     *
     * @return array{ok:bool,id?:int,callback_url?:string,http_code?:int,error_code?:string,error_message?:string}
     */
    public static function viewSubscription(): array {
        $url = add_query_arg(
            [
                'client_id'     => StravaConfig::clientId(),
                'client_secret' => StravaConfig::clientSecret(),
            ],
            StravaConfig::apiBaseUrl() . '/push_subscriptions'
        );
        $response = wp_remote_get( $url, [
            'timeout'    => self::TIMEOUT_SECONDS,
            'user-agent' => self::userAgent(),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error_code' => 'transport_error', 'error_message' => $response->get_error_message() ];
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            return [ 'ok' => false, 'http_code' => $code, 'error_code' => 'subscription_view_http_' . $code ];
        }

        // Strava returns a JSON array (0 or 1 entries); take the first.
        $first = ( is_array( $body ) && isset( $body[0] ) && is_array( $body[0] ) ) ? $body[0] : [];
        return [
            'ok'           => true,
            'id'           => (int) ( $first['id'] ?? 0 ),
            'callback_url' => (string) ( $first['callback_url'] ?? '' ),
            'http_code'    => $code,
        ];
    }

    /**
     * Delete the push subscription by id.
     */
    public static function deleteSubscription( string $subscription_id ): bool {
        if ( $subscription_id === '' ) return false;

        $url = add_query_arg(
            [
                'client_id'     => StravaConfig::clientId(),
                'client_secret' => StravaConfig::clientSecret(),
            ],
            StravaConfig::apiBaseUrl() . '/push_subscriptions/' . rawurlencode( $subscription_id )
        );
        $response = wp_remote_request( $url, [
            'method'     => 'DELETE',
            'timeout'    => self::TIMEOUT_SECONDS,
            'user-agent' => self::userAgent(),
        ] );

        if ( is_wp_error( $response ) ) {
            Logger::warning( 'strava.subscription.delete_transport_error', [ 'error' => $response->get_error_message() ] );
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        return $code >= 200 && $code < 300;
    }

    /**
     * @param array<string,mixed>|\WP_Error $response
     * @return array{ok:bool,id?:int,http_code?:int,error_code?:string,error_message?:string}
     */
    private static function subscriptionResult( $response ): array {
        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error_code' => 'transport_error', 'error_message' => $response->get_error_message() ];
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $body = is_array( $body ) ? $body : [];

        if ( $code < 200 || $code >= 300 ) {
            return [
                'ok'            => false,
                'http_code'     => $code,
                'error_code'    => 'subscription_http_' . $code,
                'error_message' => (string) ( $body['message'] ?? 'Strava subscription request failed.' ),
            ];
        }
        return [ 'ok' => true, 'id' => (int) ( $body['id'] ?? 0 ), 'http_code' => $code ];
    }

    public static function userAgent(): string {
        $version = defined( 'TT_VERSION' ) ? TT_VERSION : '0';
        return sprintf( self::USER_AGENT_FORMAT, $version );
    }
}
