<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SpondClient (#0062) — JSON HTTP client against `api.spond.com`.
 *
 * #0031 originally shipped this as an iCal fetcher; Spond never
 * published iCal, so this was rewritten in #0062 to use the
 * undocumented internal JSON API the official mobile/web clients
 * already use. Login + group + event endpoints are well-known via
 * community libraries (Olen/Spond, d3ntastic/spond-api).
 *
 *   POST /core/v1/login         { email, password } → { loginToken }
 *   GET  /core/v1/groups/                              → group[]
 *   GET  /core/v1/sponds/?groupId=…&minStartTimestamp=…&maxStartTimestamp=…
 *                                                     → event[]
 *
 * Token lives in `CredentialsManager` for ~12h; on `401` we clear,
 * re-login, and retry the original request once. Beyond that, fail
 * the call — the operator's password has probably rotated.
 *
 * 2FA is hard-fail in v1: clubs are expected to use a non-2FA
 * dedicated coach account. Detection is best-effort (response code
 * + body shape) since the upstream isn't documented.
 */
final class SpondClient {

    public const BASE_URL        = 'https://api.spond.com/core/v1';
    public const TIMEOUT_SECONDS = 15;
    public const USER_AGENT      = 'TalentTrack/%s (+https://github.com/caspernieuwenhuizen/talenttrack)';

    /**
     * Window the events query asks Spond for, in days. 30 days back +
     * 180 days forward covers the typical academy planning horizon
     * without dragging in a year of historical noise on every sync.
     */
    public const WINDOW_PAST_DAYS   = 30;
    public const WINDOW_FUTURE_DAYS = 180;

    /**
     * Authenticate against `/login`. Bypassed when a cached token is
     * still valid; called on first sync, or after a 401 forces a
     * cache flush.
     *
     * @return array{ok:bool,token:string,error_code?:string,error_message?:string,http_code?:int}
     */
    public static function login( string $email, string $password ): array {
        if ( $email === '' || $password === '' ) {
            return self::error( 'no_credentials', __( 'No Spond credentials configured.', 'talenttrack' ) );
        }

        $response = wp_remote_post( self::BASE_URL . '/login', [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => self::userAgent(),
            ],
            'body' => wp_json_encode( [ 'email' => $email, 'password' => $password ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return self::error( 'network', sprintf(
                /* translators: %s: HTTP error message */
                __( 'Could not reach Spond (%s).', 'talenttrack' ),
                $response->get_error_message()
            ) );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );

        if ( $code === 401 || $code === 403 ) {
            return self::error(
                'invalid_credentials',
                __( 'Spond rejected the email + password combination.', 'talenttrack' ),
                $code
            );
        }

        // 2FA hard-fail. Spond's response shape isn't documented; we
        // recognise it by either an explicit 2FA hint in the body or
        // a `verificationRequired` / `mfa` field.
        if ( is_array( $json ) && self::looksLike2FA( $json ) ) {
            return self::error(
                'two_factor_required',
                __( 'Spond account requires 2FA. Use a non-2FA dedicated coach account for the integration.', 'talenttrack' ),
                $code
            );
        }

        if ( $code !== 200 ) {
            return self::error(
                'bad_status',
                /* translators: %d: HTTP status code */
                sprintf( __( 'Spond login returned HTTP %d.', 'talenttrack' ), $code ),
                $code
            );
        }

        $token = is_array( $json ) ? (string) ( $json['loginToken'] ?? '' ) : '';
        if ( $token === '' ) {
            return self::error( 'no_token', __( 'Spond login did not return a token.', 'talenttrack' ), $code );
        }

        return [ 'ok' => true, 'token' => $token, 'http_code' => $code ];
    }

    /**
     * List groups visible to the logged-in account. Used by the
     * per-team picker to populate a dropdown.
     *
     * @return array{ok:bool,groups:list<array{id:string,name:string}>,error_code?:string,error_message?:string,http_code?:int}
     */
    public static function fetchGroups(): array {
        $token = self::ensureToken();
        if ( $token === null ) {
            return [ 'ok' => false, 'groups' => [], 'error_code' => 'no_credentials', 'error_message' => __( 'No Spond credentials configured.', 'talenttrack' ) ];
        }
        if ( is_array( $token ) ) {
            return [ 'ok' => false, 'groups' => [] ] + $token;
        }

        $result = self::authedGet( '/groups/', [], $token );
        if ( ! $result['ok'] ) {
            return [ 'ok' => false, 'groups' => [] ] + $result;
        }

        $groups = [];
        foreach ( (array) $result['data'] as $g ) {
            if ( ! is_array( $g ) ) continue;
            $id   = (string) ( $g['id']   ?? '' );
            $name = (string) ( $g['name'] ?? '' );
            if ( $id === '' ) continue;
            $groups[] = [ 'id' => $id, 'name' => $name ];
        }
        return [ 'ok' => true, 'groups' => $groups, 'http_code' => $result['http_code'] ?? 200 ];
    }

    /**
     * Fetch events for a single Spond group inside the rolling window.
     *
     * @return array{ok:bool,events:list<array<string,mixed>>,error_code?:string,error_message?:string,http_code?:int}
     */
    public static function fetchEvents( string $group_id ): array {
        if ( $group_id === '' ) {
            return [ 'ok' => false, 'events' => [], 'error_code' => 'empty_group_id', 'error_message' => __( 'No Spond group selected for this team.', 'talenttrack' ) ];
        }

        $token = self::ensureToken();
        if ( $token === null ) {
            return [ 'ok' => false, 'events' => [], 'error_code' => 'no_credentials', 'error_message' => __( 'No Spond credentials configured.', 'talenttrack' ) ];
        }
        if ( is_array( $token ) ) {
            return [ 'ok' => false, 'events' => [] ] + $token;
        }

        $now    = time();
        $params = [
            'groupId'            => $group_id,
            'minStartTimestamp'  => gmdate( 'Y-m-d\TH:i:s.000\Z', $now - ( self::WINDOW_PAST_DAYS   * DAY_IN_SECONDS ) ),
            'maxStartTimestamp'  => gmdate( 'Y-m-d\TH:i:s.000\Z', $now + ( self::WINDOW_FUTURE_DAYS * DAY_IN_SECONDS ) ),
            'includeHidden'      => 'true',
            'order'              => 'asc',
        ];

        $result = self::authedGet( '/sponds/', $params, $token );
        if ( ! $result['ok'] ) {
            return [ 'ok' => false, 'events' => [] ] + $result;
        }

        $events = [];
        foreach ( (array) $result['data'] as $e ) {
            if ( is_array( $e ) ) $events[] = $e;
        }
        return [ 'ok' => true, 'events' => $events, 'http_code' => $result['http_code'] ?? 200 ];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Returns a usable token, or `null` when no credentials are stored,
     * or an error array when login itself failed.
     *
     * @return string|null|array{ok:bool,error_code:string,error_message:string,http_code?:int}
     */
    private static function ensureToken() {
        $cached = CredentialsManager::getCachedToken();
        if ( $cached !== '' ) return $cached;

        $email    = CredentialsManager::getEmail();
        $password = CredentialsManager::getPassword();
        if ( $email === '' || $password === '' ) return null;

        $login = self::login( $email, $password );
        if ( ! $login['ok'] ) {
            return [
                'ok'            => false,
                'error_code'    => (string) ( $login['error_code']    ?? 'login_failed' ),
                'error_message' => (string) ( $login['error_message'] ?? '' ),
                'http_code'     => (int)    ( $login['http_code']     ?? 0 ),
            ];
        }
        CredentialsManager::cacheToken( $login['token'] );
        return $login['token'];
    }

    /**
     * @param array<string,string> $params
     * @return array{ok:bool,data:mixed,error_code?:string,error_message?:string,http_code?:int}
     */
    private static function authedGet( string $path, array $params, string $token, bool $is_retry = false ): array {
        $query = $params ? '?' . http_build_query( $params ) : '';

        $response = wp_remote_get( self::BASE_URL . $path . $query, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'User-Agent'    => self::userAgent(),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'ok'            => false,
                'data'          => null,
                'error_code'    => 'network',
                'error_message' => sprintf(
                    /* translators: %s: HTTP error message */
                    __( 'Could not reach Spond (%s).', 'talenttrack' ),
                    $response->get_error_message()
                ),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( ( $code === 401 || $code === 403 ) && ! $is_retry ) {
            CredentialsManager::clearToken();
            $email    = CredentialsManager::getEmail();
            $password = CredentialsManager::getPassword();
            if ( $email !== '' && $password !== '' ) {
                $login = self::login( $email, $password );
                if ( $login['ok'] ) {
                    CredentialsManager::cacheToken( $login['token'] );
                    return self::authedGet( $path, $params, $login['token'], true );
                }
                return [
                    'ok'            => false,
                    'data'          => null,
                    'error_code'    => (string) ( $login['error_code']    ?? 'login_failed' ),
                    'error_message' => (string) ( $login['error_message'] ?? '' ),
                    'http_code'     => (int)    ( $login['http_code']     ?? 0 ),
                ];
            }
        }

        if ( $code !== 200 ) {
            return [
                'ok'            => false,
                'data'          => null,
                'error_code'    => 'bad_status',
                /* translators: 1: API path, 2: HTTP status code */
                'error_message' => sprintf( __( 'Spond %1$s returned HTTP %2$d.', 'talenttrack' ), $path, $code ),
                'http_code'     => $code,
            ];
        }

        $body = (string) wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );
        if ( ! is_array( $json ) ) {
            return [
                'ok'            => false,
                'data'          => null,
                'error_code'    => 'bad_json',
                'error_message' => __( 'Spond response was not valid JSON.', 'talenttrack' ),
                'http_code'     => $code,
            ];
        }

        return [ 'ok' => true, 'data' => $json, 'http_code' => $code ];
    }

    private static function looksLike2FA( array $body ): bool {
        $hints = [ 'verificationRequired', 'mfa', 'twoFactor', 'requires2FA' ];
        foreach ( $hints as $hint ) {
            if ( array_key_exists( $hint, $body ) && (bool) $body[ $hint ] ) return true;
        }
        $msg = strtolower( (string) ( $body['message'] ?? $body['error'] ?? '' ) );
        return $msg !== '' && ( strpos( $msg, '2fa' ) !== false || strpos( $msg, 'two-factor' ) !== false || strpos( $msg, 'verification code' ) !== false );
    }

    private static function userAgent(): string {
        return sprintf( self::USER_AGENT, defined( 'TT_VERSION' ) ? TT_VERSION : 'dev' );
    }

    /**
     * @return array{ok:bool,token:string,error_code:string,error_message:string,http_code?:int}
     */
    private static function error( string $code, string $message, int $http_code = 0 ): array {
        return [
            'ok'            => false,
            'token'         => '',
            'error_code'    => $code,
            'error_message' => $message,
            'http_code'     => $http_code,
        ];
    }
}
