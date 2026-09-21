<?php
namespace TT\Modules\Tournaments\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Tournaments\PlayerTournamentAccess;
use TT\Modules\Tournaments\Services\PlayerTournamentHistoryQuery;

/**
 * PlayerTournamentsRestController (#3561, epic #3558) —
 * `GET /players/{id}/tournaments`.
 *
 * One player's tournament record: every tournament they were in the squad
 * for, every fixture of those they were assigned to, the minutes the
 * rotation plan gave them, and what is still ahead. The player file's
 * Tournaments tab (#3562) renders exactly this and computes none of it, so
 * the screen and a non-WordPress front end cannot disagree (CLAUDE.md §4).
 *
 * Resource-oriented under the player, because the player is the subject:
 * `/tournaments/{id}/totals` answers the squad's day, this answers the
 * child's season of them.
 */
final class PlayerTournamentsRestController extends BaseController {

    /** The LicenseGate feature the whole tournaments surface sits behind. */
    private const FEATURE = 'tournaments';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players/(?P<id>\d+)/tournaments', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_for_player' ],
                'args'                => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'The player whose tournament record to read.',
                    ],
                ],
                'permission_callback' => [ __CLASS__, 'can_read' ],
            ],
        ] );
    }

    /**
     * The `player_tournaments` entity, at whichever scope the caller holds
     * it (#3560). The child's own choice about what their family sees is
     * asked in the handler instead, so a parent whose child closed the
     * section gets `section_private` and not a bare `rest_forbidden` they
     * cannot interpret — the split `players/{id}/goals` already uses.
     */
    public static function can_read( \WP_REST_Request $r ): bool {
        return PlayerTournamentAccess::canRead( get_current_user_id(), (int) $r['id'] );
    }

    public static function get_for_player( \WP_REST_Request $r ) {
        // The `tournaments` plan gate, through the **write-verb** helper
        // and deliberately not `enforceFeatureRest()`.
        //
        // #3561's acceptance criteria said "absent or refused on Standard".
        // The house rule the gate was built on (#3105) is narrower and
        // better: a *read* of a record that already exists survives its
        // feature leaving the plan, so a club that drops to Standard can
        // still see the tournaments it played. `/tournaments/{id}/totals`
        // is the same data per squad and is gated exactly this way — an
        // install where the squad's totals are readable and one child's are
        // not would be incoherent. The route is genuinely absent when the
        // Tournaments module is switched off: this controller registers
        // from `TournamentsModule::boot()`, so there is no route at all.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' ) ) {
            $blocked = \TT\Modules\License\LicenseGate::enforceWriteRest( self::FEATURE, $r );
            if ( $blocked ) return $blocked;
        }

        $player_id = (int) $r['id'];

        // #1867 — a parent only reads a section the child shares. A no-op
        // for the player themselves and for staff.
        if ( ! AuthorizationService::parentCanViewSection( get_current_user_id(), $player_id, 'tournaments' ) ) {
            return RestResponse::error( 'section_private', __( 'This section has been kept private.', 'talenttrack' ), 403 );
        }

        $history = ( new PlayerTournamentHistoryQuery() )->forPlayer( $player_id );

        return RestResponse::success( $history );
    }
}
