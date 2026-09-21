<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;

/**
 * BroadcastsRestController (#3499) — the current user's operator broadcasts.
 *
 * Routes:
 *   GET  /me/broadcasts               — active broadcasts this user has not dismissed
 *   POST /me/broadcasts/{id}/dismiss  — hide one for this user
 *
 * Both answer for the logged-in user only; a broadcast is shown to every
 * persona, and a dismissal only ever touches the caller's own user meta.
 * The logic lives in `Broadcasts`; this is the transport a non-WordPress
 * front end would use.
 */
final class BroadcastsRestController {

    public const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        $can = static fn(): bool => is_user_logged_in() && get_current_user_id() > 0;

        register_rest_route( self::NS, '/me/broadcasts', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'list' ],
            'permission_callback' => $can,
        ] );
        register_rest_route( self::NS, '/me/broadcasts/(?P<id>\d+)/dismiss', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'dismiss' ],
            'permission_callback' => $can,
            'args'                => self::dismissArgs(),
        ] );
    }

    /**
     * #3819 — the dismiss route takes no body of its own: the broadcast is
     * the one in the URL and the user is the caller.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function dismissArgs(): array {
        return [ 'id' => [
            'type'        => [ 'integer', 'string' ],
            'description' => 'The broadcast to hide, from the URL. A copy in the body is accepted and ignored.',
        ] ];
    }

    public static function list( \WP_REST_Request $r ): \WP_REST_Response {
        return RestResponse::success( [ 'broadcasts' => Broadcasts::forUser( get_current_user_id() ) ] );
    }

    public static function dismiss( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = \TT\Infrastructure\REST\BaseController::checkBody( $r, self::dismissArgs() );
        if ( $refused !== null ) return $refused;

        $id = absint( $r['id'] );
        if ( ! Broadcasts::dismiss( get_current_user_id(), $id ) ) {
            return RestResponse::error( 'not_dismissable', __( 'There is no broadcast to dismiss with this id.', 'talenttrack' ), 404 );
        }
        return RestResponse::success( [ 'dismissed' => $id ] );
    }
}
