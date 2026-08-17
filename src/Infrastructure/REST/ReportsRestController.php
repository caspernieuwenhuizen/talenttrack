<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\AttendanceRankingQuery;
use TT\Modules\Analytics\Reports\CoachEvalQualityQuery;
use TT\Modules\Analytics\Reports\PlayerRadarQuery;
use TT\Modules\Analytics\Domain\AttendanceFlagService;
use WP_REST_Request;

/**
 * ReportsRestController (#1367) — REST surface for standard reports
 * that need a non-WordPress consumer per CLAUDE.md §4.
 *
 *   GET /reports/coach-evaluation-quality
 *       filters: team_id, date_from, date_to (Y-m-d)
 *
 * Permission: `tt_view_reports` PLUS academy-wide scope (global-scope
 * read on `reports`, via `AllTeamsScope` — #1942) — this is the HoD's
 * coach-quality lens; coaches must not read each other's stats. Mirrors
 * the scope gate `FrontendStandardReportsView` applies to the same
 * renderer.
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
                        && \TT\Modules\Authorization\AllTeamsScope::canSeeAllTeamsReports( get_current_user_id() );
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
        // #1488 — attendance ranking surfaces. Gated on `tt_view_analytics`
        // (the same cap the PHP-rendered report + leaderboard check);
        // results are additionally narrowed to the caller's team scope
        // below, so coaches never read other teams' rows.
        $attendance_args = [
            'team_id'           => [ 'sanitize_callback' => 'absint',              'required' => false ],
            'from'              => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
            'to'                => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
            // #2136 — optional activity-type narrowing, threaded into the
            // shared AttendanceRankingQuery so render + REST stay in lockstep.
            'activity_type_key' => [ 'sanitize_callback' => 'sanitize_key',        'required' => false ],
        ];
        register_rest_route( self::NS, '/reports/attendance-leaderboard', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'attendanceLeaderboard' ],
                'permission_callback' => self::permCan( 'tt_view_analytics' ),
                'args'                => $attendance_args + [
                    'n' => [ 'sanitize_callback' => 'absint', 'required' => false ],
                ],
            ],
        ] );
        register_rest_route( self::NS, '/reports/attendance-at-risk', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'attendanceAtRisk' ],
                'permission_callback' => self::permCan( 'tt_view_analytics' ),
                'args'                => $attendance_args,
            ],
        ] );
        // #2137 — per-player attendance rows for one window, used by the
        // team report's inline drill-down accordion (and any SaaS consumer
        // that needs the same per-player slice). `team_id` narrows to one
        // team; scope is still enforced by attendanceScope().
        register_rest_route( self::NS, '/reports/attendance', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'attendanceRows' ],
                'permission_callback' => self::permCan( 'tt_view_analytics' ),
                'args'                => $attendance_args,
            ],
        ] );
        // #2368 — read-only minutes-audit matrix (games × players) for a
        // team + window. Gated on `tt_view_analytics` (the same cap the
        // PHP-rendered view dispatch checks); results are additionally
        // narrowed to the caller's team scope below.
        register_rest_route( self::NS, '/reports/minutes-audit', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'minutesAudit' ],
                'permission_callback' => self::permCanFeature( 'tt_view_analytics', 'report_minutes_audit' ),
                'args'                => [
                    'team_id' => [ 'sanitize_callback' => 'absint',              'required' => true ],
                    'from'    => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'to'      => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'type'    => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                ],
            ],
        ] );
        // #2367 — per-match minutes editor read model: the squad + each
        // player's effective / derived / override minutes + roster
        // attendance-row id + whether a match-execution owns the activity
        // (the arbiter, so the client can route each write). Gated on the
        // SAME `tt_edit_activities` capability the two write paths enforce
        // (PATCH /match-execution/{activity}/minutes and PATCH /attendance),
        // so this read never surfaces an editor a viewer-only user can't
        // commit. Team scope is enforced on the activity's team below.
        register_rest_route( self::NS, '/reports/minutes-audit/(?P<activity_id>\d+)/editor', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'minutesAuditEditor' ],
                'permission_callback' => self::permCanFeature( 'tt_edit_activities', 'report_minutes_audit' ),
                'args'                => [
                    'activity_id' => [ 'validate_callback' => [ self::class, 'isPositiveInt' ] ],
                ],
            ],
        ] );
    }

    /**
     * #2367 — the per-match minutes editor read model. Returns the squad
     * with each player's effective / derived / override minutes + the
     * roster attendance-row id, plus `owned_by_execution` so the client can
     * route each write:
     *   - owned  → PATCH /match-execution/{activity}/minutes  (override)
     *   - not    → PATCH /attendance/{attendance_id} {minutes_played}
     *
     * Cap: `tt_edit_activities` (the write-through cap). Team scope is
     * enforced on the activity's own team — a coach who deep-links to a
     * match on a team they don't coach gets a 403, not another team's roster.
     */
    public static function minutesAuditEditor( WP_REST_Request $req ): \WP_REST_Response {
        $activity_id = (int) $req->get_param( 'activity_id' );
        $data = ( new \TT\Modules\Analytics\Reports\MinutesAuditQuery() )->editorRows( $activity_id );
        if ( $data === null ) {
            return RestResponse::error( 'not_found', __( 'Match not found.', 'talenttrack' ), 404 );
        }

        $scope = self::attendanceScope( (int) $data['activity']['team_id'] );
        if ( $scope['blocked'] ) {
            return RestResponse::error(
                'forbidden_team',
                __( 'You do not coach this match’s team.', 'talenttrack' ),
                403
            );
        }

        return RestResponse::success( $data );
    }

    /**
     * #2368 — the minutes-audit matrix for one team + window, narrowed to
     * one match-type when `type` is a game_subtype_key (else all). Reads
     * the same persisted `record_type='actual'` minutes as the minutes
     * report (#2193), so the two reconcile exactly. Team scope is enforced
     * via {@see attendanceScope()} — a coach who passes a team they don't
     * coach gets an empty matrix, not another team's data.
     */
    public static function minutesAudit( WP_REST_Request $req ): \WP_REST_Response {
        $team_id  = (int) $req->get_param( 'team_id' );
        [ $from, $to ] = self::minutesAuditWindow( $req );
        $type     = (string) $req->get_param( 'type' );
        if ( ! in_array( $type, [ 'League', 'Cup', 'Friendly' ], true ) ) $type = 'all';

        $allowed = self::attendanceScope( $team_id );
        if ( $allowed['blocked'] || $team_id <= 0 ) {
            return RestResponse::success( [
                'games'         => [],
                'players'       => [],
                'column_totals' => [],
                'grand_total'   => 0,
                'summary'       => [ 'total_games' => 0, 'complete' => 0, 'partial' => 0, 'none' => 0 ],
            ] );
        }

        $matrix = ( new \TT\Modules\Analytics\Reports\MinutesAuditQuery() )->matrix( $team_id, $from, $to, $type );
        return RestResponse::success( $matrix );
    }

    /**
     * Resolve the audit window: season default unless valid `from`/`to`
     * are supplied, matching the PHP view's default.
     *
     * @return array{0:string,1:string}
     */
    private static function minutesAuditWindow( WP_REST_Request $req ): array {
        $from = (string) $req->get_param( 'from' );
        $to   = (string) $req->get_param( 'to' );
        $default = \TT\Modules\Analytics\Reports\ReportFilters::seasonDefaultWindow();
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = $default['from'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = $default['to'];
        return [ $from, $to ];
    }

    public static function attendanceRows( WP_REST_Request $req ): \WP_REST_Response {
        [ $from, $to ] = self::attendanceWindow( $req );
        $team_id       = (int) $req->get_param( 'team_id' );
        $type_key      = (string) $req->get_param( 'activity_type_key' );
        $allowed       = self::attendanceScope( $team_id );
        if ( $allowed['blocked'] ) return RestResponse::success( [ 'players' => [], 'threshold' => AttendanceFlagService::threshold() ] );

        $players = ( new AttendanceRankingQuery() )->rows( $from, $to, $team_id, $allowed['team_ids'], $type_key );
        return RestResponse::success( [
            'players'   => $players,
            'threshold' => AttendanceFlagService::threshold(),
        ] );
    }

    public static function attendanceLeaderboard( WP_REST_Request $req ): \WP_REST_Response {
        [ $from, $to ]   = self::attendanceWindow( $req );
        $team_id         = (int) $req->get_param( 'team_id' );
        // #2205 — unset/blank `n` means all players in the window; a
        // supplied positive number narrows each column.
        $n               = (int) $req->get_param( 'n' );
        $type_key        = (string) $req->get_param( 'activity_type_key' );
        $allowed         = self::attendanceScope( $team_id );
        if ( $allowed['blocked'] ) return RestResponse::success( [ 'top' => [], 'bottom' => [], 'total' => 0 ] );

        $board = ( new AttendanceRankingQuery() )->leaderboard( $from, $to, $n, $team_id, $allowed['team_ids'], $type_key );
        return RestResponse::success( $board );
    }

    public static function attendanceAtRisk( WP_REST_Request $req ): \WP_REST_Response {
        [ $from, $to ]   = self::attendanceWindow( $req );
        $team_id         = (int) $req->get_param( 'team_id' );
        $type_key        = (string) $req->get_param( 'activity_type_key' );
        $allowed         = self::attendanceScope( $team_id );
        if ( $allowed['blocked'] ) return RestResponse::success( [ 'players' => [], 'threshold' => AttendanceFlagService::threshold() ] );

        $players = ( new AttendanceRankingQuery() )->atRisk( $from, $to, $team_id, $allowed['team_ids'], $type_key );
        return RestResponse::success( [
            'players'   => $players,
            'threshold' => AttendanceFlagService::threshold(),
        ] );
    }

    /**
     * Resolve + validate the `from`/`to` window (default last 90 days).
     *
     * @return array{0:string,1:string}
     */
    private static function attendanceWindow( WP_REST_Request $req ): array {
        $from = (string) $req->get_param( 'from' );
        $to   = (string) $req->get_param( 'to' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = gmdate( 'Y-m-d' );
        return [ $from, $to ];
    }

    /**
     * Mirror the analytics views' team-scope rule: academy-wide roles
     * (global-scope read on `activities`, via `AllTeamsScope` — #1942)
     * read the whole club; everyone else is narrowed to the teams they
     * coach. A coach who passes a team they don't coach — or coaches
     * nothing — is blocked.
     *
     * @return array{team_ids:list<int>|null, blocked:bool}
     */
    private static function attendanceScope( int $team_id ): array {
        $is_scope_admin = \TT\Modules\Authorization\AllTeamsScope::canSeeAllTeamsActivities( get_current_user_id() );
        if ( $is_scope_admin ) {
            return [ 'team_ids' => null, 'blocked' => false ];
        }
        $team_ids = array_values( array_map(
            'intval',
            array_column( \TT\Infrastructure\Query\QueryHelpers::get_teams_for_coach( get_current_user_id() ), 'id' )
        ) );
        if ( $team_ids === [] ) {
            return [ 'team_ids' => [], 'blocked' => true ];
        }
        if ( $team_id > 0 && ! in_array( $team_id, $team_ids, true ) ) {
            return [ 'team_ids' => $team_ids, 'blocked' => true ];
        }
        return [ 'team_ids' => $team_ids, 'blocked' => false ];
    }

    public static function playerRadar( WP_REST_Request $req ): \WP_REST_Response {
        $mode = (string) $req->get_param( 'mode' );
        if ( ! in_array( $mode, [ 'progress', 'comparison', 'team_avg' ], true ) ) $mode = 'progress';
        $ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $req->get_param( 'player_ids' ) ) ) ) );

        // Scope: mirror FrontendStandardReportsView — non-scope-admins
        // are narrowed to their own teams' players / teams. #1942 — the
        // all-teams lens is global-scope read on `reports`.
        $is_scope_admin = \TT\Modules\Authorization\AllTeamsScope::canSeeAllTeamsReports( get_current_user_id() );
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
