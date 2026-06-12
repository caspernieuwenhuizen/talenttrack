<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\CoachEvalQualityQuery;
use TT\Modules\Analytics\Reports\PlayerRadarQuery;
use WP_REST_Request;

/**
 * ReportsRestController (#1367) — REST surface for standard reports
 * that need a non-WordPress consumer per CLAUDE.md §4.
 *
 *   GET /reports/coach-evaluation-quality
 *       filters: team_id, date_from, date_to (Y-m-d)
 *
 * Permission: `tt_view_reports` PLUS academy-wide scope
 * (`tt_view_all_teams` or the settings-admin roll-up) — this is the
 * HoD's coach-quality lens; coaches must not read each other's
 * stats. Mirrors the scope gate `FrontendStandardReportsView` applies
 * to the same renderer.
 */
final class ReportsRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/reports/coach-evaluation-quality', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'coachEvalQuality' ],
                'permission_callback' => static function (): bool {
                    return current_user_can( 'tt_view_reports' )
                        && ( current_user_can( 'tt_view_all_teams' ) || current_user_can( 'tt_edit_settings' ) );
                },
                'args'                => [
                    'team_id'   => [ 'sanitize_callback' => 'absint',              'required' => false ],
                    'date_from' => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'date_to'   => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                ],
            ],
        ] );
        // #1369 — radar datasets behind the Player · Progress & radar
        // report. tt_view_reports holders only; player/team ids are
        // additionally narrowed to the caller's team scope below.
        register_rest_route( self::NS, '/reports/player-radar', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'playerRadar' ],
                'permission_callback' => self::permCan( 'tt_view_reports' ),
                'args'                => [
                    'mode'       => [ 'sanitize_callback' => 'sanitize_key',        'default' => 'progress' ],
                    'player_ids' => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                ],
            ],
        ] );
    }

    public static function playerRadar( WP_REST_Request $req ): \WP_REST_Response {
        $mode = (string) $req->get_param( 'mode' );
        if ( ! in_array( $mode, [ 'progress', 'comparison', 'team_avg' ], true ) ) $mode = 'progress';
        $ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $req->get_param( 'player_ids' ) ) ) ) );

        // Scope: mirror FrontendStandardReportsView — non-scope-admins
        // are narrowed to their own teams' players / teams.
        $is_scope_admin = current_user_can( 'tt_view_all_teams' ) || current_user_can( 'tt_edit_settings' );
        $allowed_team_ids = null;
        if ( ! $is_scope_admin ) {
            $allowed_team_ids = array_values( array_map(
                'intval',
                array_column( \TT\Infrastructure\Query\QueryHelpers::get_teams_for_coach( get_current_user_id() ), 'id' )
            ) );
            $allowed_players = [];
            foreach ( \TT\Infrastructure\Query\QueryHelpers::get_players() as $pl ) {
                if ( in_array( (int) ( $pl->team_id ?? 0 ), $allowed_team_ids, true ) ) {
                    $allowed_players[] = (int) $pl->id;
                }
            }
            $ids = array_values( array_intersect( $ids, $allowed_players ) );
        }

        $query = new PlayerRadarQuery();
        if ( $mode === 'comparison' ) {
            $payload = $query->comparison( $ids );
        } elseif ( $mode === 'team_avg' ) {
            $payload = $query->teamAverages( $allowed_team_ids );
        } else {
            $pids = $ids ?: $query->defaultProgressPlayerIds( $allowed_team_ids );
            $players = [];
            foreach ( $pids as $pid ) {
                $pl = \TT\Infrastructure\Query\QueryHelpers::get_player( $pid );
                if ( ! $pl ) continue;
                $players[] = array_merge(
                    [
                        'player_id' => $pid,
                        'name'      => \TT\Infrastructure\Query\QueryHelpers::player_display_name( $pl ),
                    ],
                    $query->progressForPlayer( $pid, 5 )
                );
            }
            $payload = [ 'players' => $players ];
        }
        $payload['mode']       = $mode;
        $payload['rating_max'] = (float) \TT\Infrastructure\Query\QueryHelpers::get_config( 'rating_max', '10' );
        return RestResponse::success( $payload );
    }

    public static function coachEvalQuality( WP_REST_Request $req ): \WP_REST_Response {
        $rows = ( new CoachEvalQualityQuery() )->rows( [
            'team_id'   => (int) $req->get_param( 'team_id' ),
            'date_from' => (string) $req->get_param( 'date_from' ),
            'date_to'   => (string) $req->get_param( 'date_to' ),
        ] );
        return RestResponse::success( [
            'rows'                   => $rows,
            'low_variance_threshold' => CoachEvalQualityQuery::LOW_VARIANCE_THRESHOLD,
            'min_ratings_for_flag'   => CoachEvalQualityQuery::MIN_RATINGS_FOR_FLAG,
        ] );
    }
}
