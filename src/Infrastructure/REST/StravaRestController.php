<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Strava\ConnectionRepository;
use TT\Modules\Strava\StravaClient;
use TT\Modules\Strava\StravaConfig;
use TT\Modules\Strava\StravaOAuth;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * StravaRestController (#2056, epic #2002) — OAuth connect surface for
 * the per-player Strava integration.
 *
 *   POST   /players/{id}/strava/connect  — mint a signed authorize URL
 *   DELETE /players/{id}/strava/connect  — disconnect (revoke + clear)
 *   GET    /players/{id}/strava/status   — connection status (no secrets)
 *   GET    /strava/callback              — OAuth redirect target (public)
 *   POST   /strava/app                   — operator: app id/secret
 *
 * Connect / disconnect / status gate on whether the caller may act on
 * the player: the player on their own record (self), or a coach/admin
 * with edit rights — never a role-string compare, never `__return_true`
 * (except the callback, which self-authenticates via the signed state).
 *
 * Secrets never round-trip: per-player tokens are stored encrypted by
 * `ConnectionRepository` and never returned; the app client secret is
 * write-only.
 */
final class StravaRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players/(?P<id>\d+)/strava/connect', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'connect' ],
                'permission_callback' => [ __CLASS__, 'canManagePlayerParam' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'disconnect' ],
                'permission_callback' => [ __CLASS__, 'canManagePlayerParam' ],
            ],
        ] );

        register_rest_route( self::NS, '/players/(?P<id>\d+)/strava/status', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'status' ],
            'permission_callback' => [ __CLASS__, 'canManagePlayerParam' ],
        ] );

        // Public OAuth redirect target — authenticates via the signed state.
        register_rest_route( self::NS, '/strava/callback', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'callback' ],
            'permission_callback' => '__return_true',
        ] );

        // Operator-only: register the Strava developer-app credentials.
        register_rest_route( self::NS, '/strava/app', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'saveApp' ],
            'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
        ] );
    }

    // ---- Permission helpers ------------------------------------------

    /**
     * The caller may manage a player's Strava connection when they ARE
     * that player (self) or hold edit rights on the player. The self
     * path matters: a player does not hold `players.edit` on their own
     * record (they have the `my_profile` self entity), so `canEditPlayer`
     * alone would lock the athlete out of connecting their own account.
     */
    public static function canManagePlayer( int $player_id ): bool {
        $uid = get_current_user_id();
        if ( $uid <= 0 || $player_id <= 0 ) return false;
        if ( self::currentUserPlayerId() === $player_id ) return true;
        return AuthorizationService::canEditPlayer( $uid, $player_id );
    }

    public static function canManagePlayerParam( \WP_REST_Request $r ): bool {
        return self::canManagePlayer( (int) $r['id'] );
    }

    private static function currentUserPlayerId(): int {
        global $wpdb;
        $uid = get_current_user_id();
        if ( $uid <= 0 ) return 0;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players
              WHERE wp_user_id = %d AND status = 'active' AND club_id = %d
              ORDER BY id DESC LIMIT 1",
            $uid, CurrentClub::id()
        ) );
    }

    // ---- Handlers ----------------------------------------------------

    /**
     * Mint a one-time authorize URL the browser then navigates to. The
     * signed state binds this player; the URL is not stored.
     */
    public static function connect( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];

        if ( ! StravaConfig::hasCredentials() ) {
            return RestResponse::error(
                'strava_not_configured',
                __( 'Strava is not set up for this academy yet. Ask an administrator to add the Strava app credentials.', 'talenttrack' ),
                409
            );
        }

        Logger::info( 'rest.strava.connect_initiated', [ 'player_id' => $player_id, 'user' => get_current_user_id() ] );

        return RestResponse::success( [
            'authorize_url' => StravaOAuth::authorizeUrl( $player_id ),
        ] );
    }

    public static function disconnect( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $repo      = new ConnectionRepository();

        $token = $repo->getAccessToken( $player_id );
        if ( $token !== '' ) {
            StravaClient::revoke( $token );
        }
        $repo->disconnect( $player_id );

        ( new AuditService() )->record(
            'player_strava.disconnected',
            'player_strava_connection',
            $player_id,
            [ 'player_id' => $player_id, 'by' => get_current_user_id() ]
        );
        Logger::info( 'rest.strava.disconnected', [ 'player_id' => $player_id, 'user' => get_current_user_id() ] );

        return RestResponse::success( [ 'connected' => false ] );
    }

    public static function status( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $row       = ( new ConnectionRepository() )->findByPlayerId( $player_id );

        if ( ! $row || (string) $row->status !== 'connected' ) {
            return RestResponse::success( [
                'connected'  => false,
                'status'     => $row ? (string) $row->status : 'never',
                'configured' => StravaConfig::hasCredentials(),
            ] );
        }

        return RestResponse::success( [
            'connected'    => true,
            'status'       => 'connected',
            'configured'   => StravaConfig::hasCredentials(),
            'athlete_id'   => (int) $row->strava_athlete_id,
            'connected_at' => (string) $row->connected_at,
            'last_sync_at' => (string) ( $row->last_sync_at ?? '' ),
        ] );
    }

    /**
     * OAuth redirect target. Strava calls this in the browser with
     * `?code&state` (or `?error&state` on denial). We trust nothing but
     * the signed state, exchange the code server-side, store the
     * encrypted tokens, and bounce the user back to the player profile.
     */
    public static function callback( \WP_REST_Request $r ): void {
        $state    = (string) ( $r->get_param( 'state' ) ?? '' );
        $verified = StravaOAuth::verifyState( $state );

        if ( $verified === null ) {
            Logger::warning( 'rest.strava.callback_bad_state', [] );
            self::redirectToProfile( 0, 'error' );
            return;
        }

        $player_id = (int) $verified['pid'];
        $error     = (string) ( $r->get_param( 'error' ) ?? '' );
        $code      = (string) ( $r->get_param( 'code' ) ?? '' );

        if ( $error !== '' || $code === '' ) {
            Logger::info( 'rest.strava.callback_denied', [ 'player_id' => $player_id, 'error' => $error ] );
            self::redirectToProfile( $player_id, 'denied' );
            return;
        }

        $tokens = StravaClient::exchangeCode( $code );
        if ( empty( $tokens['ok'] ) ) {
            Logger::warning( 'rest.strava.exchange_failed', [
                'player_id' => $player_id,
                'code'      => (string) ( $tokens['error_code'] ?? 'unknown' ),
            ] );
            self::redirectToProfile( $player_id, 'error' );
            return;
        }

        $repo          = new ConnectionRepository();
        $connection_id = $repo->connect( $player_id, [
            'athlete_id'    => (int) ( $tokens['athlete_id'] ?? 0 ),
            'access_token'  => (string) ( $tokens['access_token'] ?? '' ),
            'refresh_token' => (string) ( $tokens['refresh_token'] ?? '' ),
            'expires_at'    => (int) ( $tokens['expires_at'] ?? 0 ),
            'scope'         => (string) ( $r->get_param( 'scope' ) ?? StravaConfig::SCOPE ),
        ] );

        ( new AuditService() )->record(
            'player_strava.connected',
            'player_strava_connection',
            $connection_id,
            [
                'player_id'  => $player_id,
                'athlete_id' => (int) ( $tokens['athlete_id'] ?? 0 ),
                'scope'      => (string) ( $r->get_param( 'scope' ) ?? StravaConfig::SCOPE ),
            ]
        );
        Logger::info( 'rest.strava.connected', [ 'player_id' => $player_id ] );

        self::redirectToProfile( $player_id, 'connected' );
    }

    /**
     * Operator endpoint to register the Strava developer-app id +
     * secret. The secret is write-only — it is encrypted at rest and
     * never returned.
     */
    public static function saveApp( \WP_REST_Request $r ): \WP_REST_Response {
        $client_id     = trim( (string) ( $r->get_param( 'client_id' ) ?? '' ) );
        $client_secret = trim( (string) ( $r->get_param( 'client_secret' ) ?? '' ) );

        if ( $client_id === '' ) {
            return RestResponse::error( 'client_id_required', __( 'A Strava Client ID is required.', 'talenttrack' ), 422 );
        }

        StravaConfig::saveCredentials( $client_id, $client_secret );
        Logger::info( 'rest.strava.app_saved', [ 'user' => get_current_user_id() ] );

        return RestResponse::success( [
            'client_id'  => StravaConfig::clientId(),
            'configured' => StravaConfig::hasCredentials(),
        ] );
    }

    /**
     * Bounce back to the player's profile with a one-shot status flag
     * the Connect UI (#2061) reads. Always exits — this is a browser
     * redirect, not a JSON response.
     */
    private static function redirectToProfile( int $player_id, string $result ): void {
        $url = $player_id > 0
            ? RecordLink::detailUrlFor( 'players', $player_id )
            : home_url( '/' );
        $url = add_query_arg( 'tt_strava', rawurlencode( $result ), $url );
        wp_safe_redirect( $url );
        exit;
    }
}
