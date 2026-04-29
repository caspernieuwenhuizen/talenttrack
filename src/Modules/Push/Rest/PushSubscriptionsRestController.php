<?php
namespace TT\Modules\Push\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Push\PushSubscriptionsRepository;
use WP_REST_Request;

/**
 * PushSubscriptionsRestController — register / list / revoke a user's
 * Web Push subscriptions (#0042).
 *
 * Routes (all under `talenttrack/v1`):
 *
 *   POST   /push-subscriptions          register or refresh by endpoint
 *   GET    /push-subscriptions          list own active subscriptions
 *   DELETE /push-subscriptions/{id}     revoke one device (own only)
 *
 * Auth = any logged-in user. Cap-as-contract isn't useful here — every
 * persona that lands on a dashboard can subscribe; the privacy
 * boundary is the per-user scope.
 *
 * Response payloads strip the encryption keys; the keys never leave
 * the database except into WebPushSender.
 */
final class PushSubscriptionsRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/push-subscriptions', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list' ],
                'permission_callback' => [ self::class, 'permLoggedIn' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'create' ],
                'permission_callback' => [ self::class, 'permLoggedIn' ],
            ],
        ] );

        register_rest_route( self::NS, '/push-subscriptions/(?P<id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ self::class, 'revoke' ],
                'permission_callback' => [ self::class, 'permLoggedIn' ],
                'args'                => [
                    'id' => [
                        'validate_callback' => [ self::class, 'isPositiveInt' ],
                    ],
                ],
            ],
        ] );
    }

    /**
     * List the current user's registered devices. Strips encryption
     * keys; returns id + user_agent + timestamps so the user can pick
     * which device to revoke from a settings surface.
     */
    public static function list( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $rows    = ( new PushSubscriptionsRepository() )->listForUser( $user_id );
        return RestResponse::success( $rows );
    }

    /**
     * Register or refresh a subscription. Body shape (matches the
     * `PushSubscription.toJSON()` output):
     *
     *   { endpoint, keys: { p256dh, auth }, user_agent? }
     *
     * The same endpoint twice idempotently refreshes the keys + bumps
     * `last_seen_at` rather than creating a duplicate row.
     */
    public static function create( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $body    = $request->get_json_params();
        if ( ! is_array( $body ) ) $body = [];

        $endpoint = (string) ( $body['endpoint'] ?? '' );
        $keys     = is_array( $body['keys'] ?? null ) ? $body['keys'] : [];
        $p256dh   = (string) ( $keys['p256dh'] ?? '' );
        $auth     = (string) ( $keys['auth']   ?? '' );

        if ( $endpoint === '' || $p256dh === '' || $auth === '' ) {
            return RestResponse::error( 'missing_fields', __( 'endpoint, keys.p256dh, and keys.auth are required.', 'talenttrack' ), 400 );
        }

        // Endpoint sanity. Web Push endpoints are HTTPS URLs.
        if ( ! preg_match( '#^https://#i', $endpoint ) ) {
            return RestResponse::error( 'bad_endpoint', __( 'Push endpoint must be HTTPS.', 'talenttrack' ), 400 );
        }

        $ua = (string) $request->get_header( 'User-Agent' );
        $id = ( new PushSubscriptionsRepository() )->upsert( $user_id, [
            'endpoint'   => $endpoint,
            'p256dh'     => $p256dh,
            'auth'       => $auth,
            'user_agent' => $ua,
        ] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'subscribe_failed', __( 'Could not save the subscription.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ], 201 );
    }

    /**
     * Delete one of the current user's subscriptions. Other users'
     * rows are not visible — the where-clause scopes by user_id so a
     * mistaken id silently 404s rather than leaking existence.
     */
    public static function revoke( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $id      = (int) $request['id'];
        $repo    = new PushSubscriptionsRepository();
        $row     = $repo->findOwnedById( $id, $user_id );
        if ( ! $row ) {
            return RestResponse::error( 'not_found', __( 'Subscription not found.', 'talenttrack' ), 404 );
        }
        $repo->deleteById( $id );
        return RestResponse::success( [ 'id' => $id, 'deleted' => true ] );
    }
}
