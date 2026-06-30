<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Strava\ActivityRepository;
use TT\Modules\Strava\ConnectionRepository;
use TT\Modules\Strava\StravaClient;
use TT\Modules\Strava\StravaConfig;
use TT\Modules\Strava\StravaOAuth;
use TT\Modules\Strava\WebhookService;
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
 *   GET    /strava/connections           — operator: club connection roster
 *   GET    /strava/webhook/subscription  — operator: subscription status
 *   POST   /strava/webhook/subscription  — operator: create subscription
 *   DELETE /strava/webhook/subscription  — operator: delete subscription
 *
 * Connect / disconnect / status gate on whether the caller may act on
 * the player: the player on their own record (self), or a coach/admin
 * with edit rights — never a role-string compare, never `__return_true`
 * (except the callback, which self-authenticates via the signed state).
 *
 * The operator surface (#2127) is matrix-gated, NOT `manage_options`:
 * reads on `tt_view_strava` (→ `strava_integration:read`), credential +
 * webhook mutations on `tt_edit_strava_credentials` (→ `…:change`).
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

        // Read the player's imported Strava activities (timeline source).
        register_rest_route( self::NS, '/players/(?P<id>\d+)/strava/activities', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'activities' ],
            'permission_callback' => [ __CLASS__, 'canViewPlayerParam' ],
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
            'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
        ] );

        // Operator console (#2127): read-only roster of every Strava
        // connection in the club, with sync + activity counts. Tokens are
        // never selected by the repo, so none reach the payload.
        register_rest_route( self::NS, '/strava/connections', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'connections' ],
            'permission_callback' => [ __CLASS__, 'canViewIntegration' ],
        ] );

        // Public webhook — GET is Strava's subscription handshake, POST is
        // the event push. Both self-authenticate (GET via the verify
        // token; POST is the single club-wide subscription's feed).
        register_rest_route( self::NS, '/strava/webhook', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'webhookVerify' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'webhookEvent' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        // Operator: manage the single club-wide push subscription. Reading
        // the status follows the integration read cap; mutating it follows
        // the credential change cap (matrix-gated, #2127).
        register_rest_route( self::NS, '/strava/webhook/subscription', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'subscriptionStatus' ],
                'permission_callback' => [ __CLASS__, 'canViewIntegration' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'subscribe' ],
                'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'unsubscribe' ],
                'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
            ],
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

    /**
     * Operator console read gate (#2127). Matrix cap, bridged to
     * `strava_integration:read` — never a `manage_options` role compare,
     * so a second tenant on the install is scoped correctly (CLAUDE.md §4).
     */
    public static function canViewIntegration(): bool {
        return current_user_can( 'tt_view_strava' );
    }

    /**
     * Operator console write gate (#2127) — app credentials + webhook
     * subscription mutations. Matrix cap, bridged to `strava_integration:change`.
     */
    public static function canEditCredentials(): bool {
        return current_user_can( 'tt_edit_strava_credentials' );
    }

    /**
     * Reading a player's imported activities follows the player's own
     * view gate (self / parent-child / team / global), so a coach who can
     * see the player sees their training; a stranger does not.
     */
    public static function canViewPlayerParam( \WP_REST_Request $r ): bool {
        $uid       = get_current_user_id();
        $player_id = (int) $r['id'];
        if ( $uid <= 0 || $player_id <= 0 ) return false;
        if ( self::currentUserPlayerId() === $player_id ) return true;
        return AuthorizationService::canViewPlayer( $uid, $player_id );
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
     *
     * Gate 2 — consent is enforced HERE, server-side, not just by a
     * frontend checkbox. The caller passes `consent: true` when the
     * inline acknowledgement (#2061) is ticked; we record + audit it.
     * The authorize URL is refused until a consent acknowledgement is on
     * record, so a hand-crafted request can't skip it.
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

        $repo = new ConnectionRepository();

        if ( self::truthy( $r->get_param( 'consent' ) ) && ! $repo->hasConsent( $player_id ) ) {
            $repo->recordConsent( $player_id, get_current_user_id() );
            ( new AuditService() )->record(
                'player_strava.consent_recorded',
                'player_strava_connection',
                $player_id,
                [ 'player_id' => $player_id, 'by' => get_current_user_id() ]
            );
            Logger::info( 'rest.strava.consent_recorded', [ 'player_id' => $player_id, 'user' => get_current_user_id() ] );
        }

        if ( ! $repo->hasConsent( $player_id ) ) {
            return RestResponse::error(
                'consent_required',
                __( 'Please agree to share Strava activity data before connecting.', 'talenttrack' ),
                422
            );
        }

        Logger::info( 'rest.strava.connect_initiated', [ 'player_id' => $player_id, 'user' => get_current_user_id() ] );

        return RestResponse::success( [
            'authorize_url' => StravaOAuth::authorizeUrl( $player_id ),
        ] );
    }

    /** REST params arrive as strings/bools/ints; treat all the obvious truths as true. */
    private static function truthy( $value ): bool {
        return in_array( $value, [ true, 1, '1', 'true', 'yes', 'on' ], true );
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
                'connected'   => false,
                'status'      => $row ? (string) $row->status : 'never',
                'configured'  => StravaConfig::hasCredentials(),
                'has_consent' => $row !== null && $row->consent_at !== null,
                'consent_at'  => $row ? (string) ( $row->consent_at ?? '' ) : '',
            ] );
        }

        return RestResponse::success( [
            'connected'    => true,
            'status'       => 'connected',
            'configured'   => StravaConfig::hasCredentials(),
            'has_consent'  => $row->consent_at !== null,
            'consent_at'   => (string) ( $row->consent_at ?? '' ),
            'athlete_id'   => (int) $row->strava_athlete_id,
            'connected_at' => (string) $row->connected_at,
            'last_sync_at' => (string) ( $row->last_sync_at ?? '' ),
        ] );
    }

    /**
     * List a player's imported Strava activities (non-HR fields only) —
     * the source the Connect UI renders on the player timeline (#2061).
     */
    public static function activities( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $rows      = ( new ActivityRepository() )->listForPlayer( $player_id, 50 );

        $out = array_map( static function ( $row ) {
            return [
                'id'                 => (int) $row->id,
                'external_id'        => (string) $row->external_id,
                'activity_type'      => (string) ( $row->activity_type ?? '' ),
                'name'               => (string) ( $row->name ?? '' ),
                'started_at'         => (string) ( $row->started_at ?? '' ),
                'distance_m'         => $row->distance_m !== null ? (float) $row->distance_m : null,
                'moving_time_s'      => $row->moving_time_s !== null ? (int) $row->moving_time_s : null,
                'elapsed_time_s'     => $row->elapsed_time_s !== null ? (int) $row->elapsed_time_s : null,
                'average_speed_ms'   => $row->average_speed_ms !== null ? (float) $row->average_speed_ms : null,
                'total_elevation_m'  => $row->total_elevation_gain_m !== null ? (float) $row->total_elevation_gain_m : null,
            ];
        }, $rows );

        return RestResponse::success( [ 'activities' => $out ] );
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
     * Operator console roster (#2127): every Strava connection in the club,
     * composed for a read-only table. The repo never selects token columns,
     * so this payload carries no secrets. Compose-only — all of it is plain
     * data from `ConnectionRepository::listForClub()`.
     */
    public static function connections( \WP_REST_Request $r ): \WP_REST_Response {
        $rows  = ( new ConnectionRepository() )->listForClub();
        $items = [];
        foreach ( $rows as $row ) {
            $name = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
            $items[] = [
                'player_id'        => (int) $row->player_id,
                'player_name'      => $name !== '' ? $name : __( '(unknown player)', 'talenttrack' ),
                'photo_url'        => (string) ( $row->photo_url ?? '' ),
                'athlete_id'       => (int) ( $row->strava_athlete_id ?? 0 ),
                'status'           => (string) ( $row->status ?? '' ),
                'connected_at'     => (string) ( $row->connected_at ?? '' ),
                'last_sync_at'     => (string) ( $row->last_sync_at ?? '' ),
                'last_activity_at' => (string) ( $row->last_activity_at ?? '' ),
                'activity_count'   => (int) ( $row->activity_count ?? 0 ),
            ];
        }

        return RestResponse::success( [
            'configured'      => StravaConfig::hasCredentials(),
            'subscription_id' => StravaConfig::subscriptionId(),
            'connections'     => $items,
        ] );
    }

    // ---- Webhook -----------------------------------------------------

    /**
     * Strava subscription-validation handshake. Must echo `hub.challenge`
     * as RAW JSON (NOT our success envelope) within 2s, after the verify
     * token checks out. Returns 403 on a token mismatch.
     */
    public static function webhookVerify( \WP_REST_Request $r ): \WP_REST_Response {
        // PHP rewrites the dotted `hub.*` query keys to underscores.
        $echo = ( new WebhookService() )->handshake( [
            'hub_mode'         => (string) ( $r->get_param( 'hub_mode' ) ?? '' ),
            'hub_verify_token' => (string) ( $r->get_param( 'hub_verify_token' ) ?? '' ),
            'hub_challenge'    => (string) ( $r->get_param( 'hub_challenge' ) ?? '' ),
        ] );

        if ( $echo === null ) {
            return new \WP_REST_Response( [ 'error' => 'invalid_verify_token' ], 403 );
        }
        return new \WP_REST_Response( $echo, 200 );
    }

    /**
     * Strava event push. The subscription feed is the auth; we resolve
     * the athlete to a connection, pin its club, and route the event.
     * Always answers 200 fast — Strava retries on a non-200.
     */
    public static function webhookEvent( \WP_REST_Request $r ): \WP_REST_Response {
        $payload = $r->get_json_params();
        if ( ! is_array( $payload ) ) {
            $payload = $r->get_params();
        }
        ( new WebhookService() )->handleEvent( $payload );
        return new \WP_REST_Response( [ 'received' => true ], 200 );
    }

    public static function subscriptionStatus( \WP_REST_Request $r ): \WP_REST_Response {
        // Best-effort reconcile with Strava's real state so the console shows
        // the truth and self-heals a drifted/lost local id. Only when the app
        // is configured (the lookup needs client_id/secret); a transport or
        // API failure falls back to the stored id without erroring.
        if ( StravaConfig::hasCredentials() ) {
            $live = StravaClient::viewSubscription();
            if ( ! empty( $live['ok'] ) ) {
                $live_id = (int) ( $live['id'] ?? 0 ) > 0 ? (string) (int) $live['id'] : '';
                if ( $live_id !== StravaConfig::subscriptionId() ) {
                    StravaConfig::setSubscriptionId( $live_id );
                }
            }
        }

        return RestResponse::success( [
            'subscription_id' => StravaConfig::subscriptionId(),
            'subscribed'      => StravaConfig::subscriptionId() !== '',
            'configured'      => StravaConfig::hasCredentials(),
            'callback_url'    => StravaConfig::webhookCallbackUrl(),
        ] );
    }

    public static function subscribe( \WP_REST_Request $r ): \WP_REST_Response {
        if ( ! StravaConfig::hasCredentials() ) {
            return RestResponse::error(
                'strava_not_configured',
                __( 'Strava is not set up for this academy yet. Ask an administrator to add the Strava app credentials.', 'talenttrack' ),
                409
            );
        }

        $res = StravaClient::createSubscription(
            StravaConfig::webhookCallbackUrl(),
            StravaConfig::webhookVerifyToken()
        );

        if ( empty( $res['ok'] ) ) {
            // Strava allows exactly one subscription per application. A create
            // can fail because one already exists — ours from a prior setup, or
            // one whose id we lost. Reconcile via GET and adopt it rather than
            // dead-ending the operator on "Create / re-verify".
            $existing = StravaClient::viewSubscription();
            if ( ! empty( $existing['ok'] ) && (int) ( $existing['id'] ?? 0 ) > 0 ) {
                StravaConfig::setSubscriptionId( (string) (int) $existing['id'] );
                Logger::info( 'rest.strava.subscription_adopted', [ 'subscription_id' => StravaConfig::subscriptionId() ] );
                return RestResponse::success( [
                    'subscribed'      => true,
                    'subscription_id' => StravaConfig::subscriptionId(),
                    'adopted'         => true,
                ] );
            }

            Logger::warning( 'rest.strava.subscribe_failed', [ 'code' => (string) ( $res['error_code'] ?? 'unknown' ) ] );
            return RestResponse::error(
                'subscribe_failed',
                __( 'Could not create the Strava webhook subscription.', 'talenttrack' ),
                422
            );
        }

        StravaConfig::setSubscriptionId( (string) ( $res['id'] ?? '' ) );
        Logger::info( 'rest.strava.subscribed', [ 'subscription_id' => (string) ( $res['id'] ?? '' ) ] );

        return RestResponse::success( [ 'subscribed' => true, 'subscription_id' => StravaConfig::subscriptionId() ] );
    }

    public static function unsubscribe( \WP_REST_Request $r ): \WP_REST_Response {
        StravaClient::deleteSubscription( StravaConfig::subscriptionId() );
        StravaConfig::setSubscriptionId( '' );
        Logger::info( 'rest.strava.unsubscribed', [ 'user' => get_current_user_id() ] );
        return RestResponse::success( [ 'subscribed' => false ] );
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
