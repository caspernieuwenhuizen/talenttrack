<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\AccountPlayerLinks;

/**
 * MeRestController — GET /me (#3568).
 *
 * The logged-in account's own links to player records: the player it is,
 * and the children it is a guardian of. It is the first call a
 * non-WordPress client makes, because every per-player route needs an id
 * and nothing else tells a player or a parent theirs.
 *
 * Login is the only gate. The route returns the caller's own links and
 * nothing about anybody else, so there is no capability to ask for, and a
 * player account holds none of the staff `tt_view_*` caps anyway.
 *
 * An account linked to nothing gets 200 with `reason: "no_linked_player"`
 * rather than a 403, so the client can say "your account isn't linked to a
 * player yet" instead of "not allowed".
 *
 * The collection routes (`GET players`, `GET evaluations`, …) stay staff
 * surfaces on purpose: each `my_*` matrix entity is its own grant, and
 * `me` mirrors that split rather than widening a collection.
 */
class MeRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/me', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_me' ],
                'permission_callback' => static function (): bool {
                    return is_user_logged_in() && get_current_user_id() > 0;
                },
            ],
        ] );
    }

    public static function get_me( \WP_REST_Request $r ): \WP_REST_Response {
        return RestResponse::success( AccountPlayerLinks::forUser( get_current_user_id() ) );
    }
}
