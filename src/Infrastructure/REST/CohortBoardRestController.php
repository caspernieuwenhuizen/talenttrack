<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\CohortBoardService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * CohortBoardRestController (#1383) — REST surface for the end-of-season
 * cohort decision board.
 *
 *   GET /cohort-board?team_id=N
 *       returns one row per active player on the team with rolling
 *       rating + trend, attendance %, PDP conversation count, and the
 *       current PDP verdict.
 *
 * Read-only. Cap-gated on `tt_view_analytics`; results are additionally
 * narrowed to the caller's team scope so a coach can't read a team they
 * don't coach (academy-wide roles see every team).
 */
final class CohortBoardRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/cohort-board', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'board' ],
                'permission_callback' => self::permCan( 'tt_view_analytics' ),
                'args'                => [
                    'team_id' => [ 'sanitize_callback' => 'absint', 'required' => true ],
                ],
            ],
        ] );
    }

    public static function board( WP_REST_Request $req ): WP_REST_Response {
        $team_id = (int) $req->get_param( 'team_id' );
        if ( $team_id <= 0 ) {
            return RestResponse::error(
                'invalid_team',
                __( 'A team is required.', 'talenttrack' ),
                400
            );
        }
        if ( ! self::callerCanReadTeam( $team_id ) ) {
            return RestResponse::error(
                'team_forbidden',
                __( 'You do not have access to this team.', 'talenttrack' ),
                403
            );
        }

        $rows = ( new CohortBoardService() )->rowsForTeam( $team_id );
        return RestResponse::success( [
            'team_id' => $team_id,
            'rows'    => $rows,
        ] );
    }

    /**
     * Academy-wide roles (global-scope read on `activities`, via
     * `AllTeamsScope` — #1942) read any team; everyone else is narrowed
     * to the teams they coach. Mirrors the scope rule the analytics
     * report controllers + views apply.
     */
    public static function callerCanReadTeam( int $team_id ): bool {
        // #3154 — the rule moved to AllTeamsScope so a second controller
        // asking the same question does not mean a second implementation of
        // it. Behaviour is unchanged apart from two widenings that bring it
        // in line with every other scope check: a WordPress settings admin
        // now passes without needing a matrix row, and an archived team the
        // caller coaches still resolves.
        return \TT\Modules\Authorization\AllTeamsScope::canReadTeamFor(
            get_current_user_id(),
            $team_id,
            'activities'
        );
    }
}
