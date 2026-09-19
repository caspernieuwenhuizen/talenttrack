<?php
namespace TT\Modules\Pdp\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\PlayersRepository;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Pdp\Services\PdpFamilyReader;

/**
 * PdpFamilyRestController (#3645) — `GET /players/{id}/pdp`.
 *
 * The development plan as its family reads it. A linked parent could
 * already acknowledge a talk through `PATCH pdp-conversations/{id}` but had
 * no route on which to find the conversation: `GET pdp-files/{id}` answers
 * 403 for them, and the list comes back empty. Over the API a parent could
 * sign for something they could not read.
 *
 * ## Why a second route rather than a branch in `PdpAccess`
 *
 * `PdpAccess::canSeeFile()` also gates the coach's manage view, the verdict
 * routes and the unsigned notes on `GET pdp-files/{id}`. Letting a parent
 * through it would open all three. The files routes stay staff surfaces;
 * the family gets the projection `PdpFamilyReader` builds — the same one
 * My PDP renders — and nothing beyond it.
 *
 * ## The gate
 *
 * `canViewPlayer` (own record, own child, own team, or global) **plus**
 * `parentCanViewSection( …, 'pdp' )`, the section key the My PDP view
 * already honours, so a player who has hidden their plan from a parent has
 * hidden it here too (#1867). Staff who pass `canViewPlayer` get the same
 * family projection; their full view is `pdp-files/{id}`.
 */
final class PdpFamilyRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players/(?P<id>\d+)/pdp', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_for_player' ],
                'permission_callback' => [ __CLASS__, 'can_read' ],
                'args'                => [
                    'id' => [
                        'description'       => __( 'The player whose development plan to read.', 'talenttrack' ),
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ] );
    }

    public static function can_read( \WP_REST_Request $request ): bool {
        $user_id   = get_current_user_id();
        $player_id = absint( $request['id'] ?? 0 );
        if ( $user_id <= 0 || $player_id <= 0 ) return false;

        return AuthorizationService::canViewPlayer( $user_id, $player_id );
    }

    public static function get_for_player( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id   = get_current_user_id();
        $player_id = absint( $request['id'] ?? 0 );

        $player = ( new PlayersRepository() )->find( $player_id );
        if ( $player === null ) {
            return RestResponse::error(
                'player_not_found',
                __( 'Player not found.', 'talenttrack' ),
                404
            );
        }

        // #1867 — a child may keep their development plan from a linked
        // parent. The check is a no-op for the player themselves and for
        // staff, so it sits beside the visibility gate rather than in it.
        if ( ! AuthorizationService::parentCanViewSection( $user_id, $player_id, 'pdp' ) ) {
            return RestResponse::error(
                'section_private',
                __( 'This section has been kept private.', 'talenttrack' ),
                403
            );
        }

        return RestResponse::success( ( new PdpFamilyReader() )->forPlayer( $player_id ) );
    }
}
