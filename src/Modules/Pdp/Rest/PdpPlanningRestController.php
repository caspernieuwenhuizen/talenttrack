<?php
namespace TT\Modules\Pdp\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Modules\Pdp\Services\PdpPlanningService;

/**
 * PdpPlanningRestController (#4448) — the PDP planning matrix.
 *
 *   GET /talenttrack/v1/pdp-planning?season_id=N&team_ids[]=…
 *
 * The same cells and block windows `?tt_view=pdp-planning` shows, read
 * from `PdpPlanningService`. Gated on `tt_view_pdp_planning`, the
 * capability the page checks, and scoped the way the page is: academy-wide
 * PDP read sees every team, a team-scoped coach only the teams they are
 * assigned to. `team_ids` narrows within that scope and never widens it.
 */
class PdpPlanningRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/pdp-planning', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'matrix' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
                'args'                => [
                    'season_id' => [ 'type' => [ 'integer', 'string' ], 'description' => 'The season the matrix covers.' ],
                    'team_ids'  => [ 'type' => [ 'array', 'string' ], 'description' => 'Optional. The teams to include, as team_ids[]=N or a comma-separated list. Teams the caller may not see are left out.' ],
                ],
            ],
        ] );
    }

    public static function can_view(): bool {
        return current_user_can( 'tt_view_pdp_planning' );
    }

    public static function matrix( \WP_REST_Request $r ): \WP_REST_Response {
        $season_id = absint( $r->get_param( 'season_id' ) );
        if ( $season_id <= 0 ) {
            return RestResponse::error( 'missing_season',
                __( 'season_id is required.', 'talenttrack' ), 400 );
        }
        if ( ( new SeasonsRepository() )->find( $season_id ) === null ) {
            return RestResponse::error( 'bad_season',
                __( 'Season not found.', 'talenttrack' ), 404 );
        }

        $user_id  = get_current_user_id();
        $team_ids = PdpPlanningService::scopedTeamIds(
            $user_id,
            user_can( $user_id, 'tt_edit_settings' ),
            self::requestedTeamIds( $r->get_param( 'team_ids' ) )
        );

        $matrix  = PdpPlanningService::buildMatrix( $season_id, $team_ids );
        $windows = PdpPlanningService::blockWindows( $season_id, $team_ids );

        $teams = [];
        foreach ( $matrix['teams'] as $team_id => $team ) {
            $blocks = [];
            foreach ( $team['blocks'] as $sequence => $cell ) {
                $blocks[] = [
                    'block'        => (int) $sequence,
                    'expected'     => (int) ( $cell['expected'] ?? 0 ),
                    'planned'      => (int) ( $cell['planned'] ?? 0 ),
                    'conducted'    => (int) ( $cell['conducted'] ?? 0 ),
                    'not_held'     => (int) ( $cell['not_held'] ?? 0 ),
                    'window_start' => (string) ( $cell['window_start'] ?? '' ),
                    'window_end'   => (string) ( $cell['window_end'] ?? '' ),
                ];
            }
            $teams[] = [
                'team_id' => (int) $team_id,
                'name'    => (string) $team['name'],
                'roster'  => (int) $team['roster'],
                'blocks'  => $blocks,
            ];
        }

        $window_rows = [];
        foreach ( $windows as $team_id => $by_block ) {
            foreach ( $by_block as $sequence => $window ) {
                $window_rows[] = [
                    'team_id' => (int) $team_id,
                    'block'   => (int) $sequence,
                    'start'   => (string) $window['start'],
                    'end'     => (string) $window['end'],
                ];
            }
        }

        return RestResponse::success( [
            'season_id'  => $season_id,
            'max_blocks' => (int) $matrix['max_blocks'],
            'teams'      => $teams,
            'windows'    => $window_rows,
        ] );
    }

    /**
     * @param mixed $raw `team_ids[]=N` arrives as an array, `team_ids=1,2` as a string.
     * @return list<int>
     */
    private static function requestedTeamIds( $raw ): array {
        if ( is_string( $raw ) ) $raw = explode( ',', $raw );
        if ( ! is_array( $raw ) ) return [];
        $ids = [];
        foreach ( $raw as $id ) {
            $id = absint( is_scalar( $id ) ? $id : 0 );
            if ( $id > 0 ) $ids[] = $id;
        }
        return array_values( array_unique( $ids ) );
    }
}
