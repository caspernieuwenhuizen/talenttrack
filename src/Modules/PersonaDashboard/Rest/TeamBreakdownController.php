<?php
namespace TT\Modules\PersonaDashboard\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Repositories\TeamOverviewRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * TeamBreakdownController (#0073) — GET /persona-dashboard/team-breakdown
 *
 * Powers the team-overview-grid card expand AJAX. Returns the team's
 * player roster with attendance % and avg rating over the requested
 * window. Capability gate is `tt_view_team_overview` which the matrix
 * grants to HoD + Academy Admin via existing team-R-global rules; we
 * also accept explicit `tt_view_players` as a forward-compat fallback.
 */
final class TeamBreakdownController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route(
            'talenttrack/v1',
            '/persona-dashboard/team-breakdown',
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get' ],
                'permission_callback' => [ self::class, 'permission' ],
                'args'                => [
                    'team_id' => [ 'sanitize_callback' => 'absint', 'required' => true ],
                    'days'    => [ 'sanitize_callback' => 'absint', 'required' => false ],
                ],
            ]
        );
    }

    public static function permission(): bool {
        if ( ! is_user_logged_in() ) return false;
        // HoD + Admin hold one of these in stock seeds; the underlying
        // matrix grant for `team R global` covers both.
        return current_user_can( 'tt_view_players' )
            || current_user_can( 'tt_manage_trials' )
            || current_user_can( 'tt_manage_authorization' );
    }

    public static function get( WP_REST_Request $req ): WP_REST_Response {
        $team_id = (int) $req->get_param( 'team_id' );
        $days    = (int) ( $req->get_param( 'days' ) ?: 30 );
        $days    = max( 1, min( 365, $days ) );

        $repo    = new TeamOverviewRepository();
        $players = $repo->teamPlayerBreakdown( $team_id, $days );
        return new WP_REST_Response( [ 'players' => $players ], 200 );
    }
}
