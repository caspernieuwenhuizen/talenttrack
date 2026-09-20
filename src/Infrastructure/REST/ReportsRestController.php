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
        // #3780, #3790 — every report filter is taken under its plain name
        // and under the nested `filter[...]` form the rest of the list API
        // uses, and both spellings are declared so route discovery shows
        // them. The descriptions are API documentation for integrators, not
        // UI copy, so they are not translated.
        $team_arg = [
            'type'              => [ 'integer', 'string' ],
            'description'       => 'Only this team\'s players. Same as filter[team_id]. A value that is not a usable team id is refused with 400 bad_filter rather than dropped.',
            'sanitize_callback' => 'sanitize_text_field',
            'required'          => false,
        ];
        register_rest_route( self::NS, '/reports/coach-evaluation-quality', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'coachEvalQuality' ],
                'permission_callback' => static function (): bool {
                    return current_user_can( 'tt_view_reports' )
                        && \TT\Modules\Authorization\AllTeamsScope::canSeeAllTeamsReports( get_current_user_id() );
                },
                'args'                => [
                    'team_id'   => $team_arg,
                    'date_from' => [ 'type' => 'string', 'description' => 'Earliest evaluation date as YYYY-MM-DD. Same as filter[date_from] or filter[from].', 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'date_to'   => [ 'type' => 'string', 'description' => 'Latest evaluation date as YYYY-MM-DD. Same as filter[date_to] or filter[to].', 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'filter'    => [ 'description' => 'Nested filters: team_id, date_from (or from), date_to (or to). A nested value wins over the plain parameter of the same name.' ],
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
        // #3780 — every one of these is also taken nested as `filter[...]`.
        $window = 'as YYYY-MM-DD. Anything else falls back to the season window, which the response echoes.';
        $attendance_args = [
            'team_id'           => $team_arg,
            'from'              => [ 'type' => 'string', 'description' => 'Window start ' . $window . ' Same as filter[from] or filter[date_from].', 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
            'to'                => [ 'type' => 'string', 'description' => 'Window end ' . $window . ' Same as filter[to] or filter[date_to].', 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
            // #2136 — optional activity-type narrowing, threaded into the
            // shared AttendanceRankingQuery so render + REST stay in lockstep.
            'activity_type_key' => [ 'type' => 'string', 'description' => 'Only activities of this type. Same as filter[activity_type_key].', 'sanitize_callback' => 'sanitize_key', 'required' => false ],
            'filter'            => [ 'description' => 'Nested filters: team_id, from (or date_from), to (or date_to), activity_type_key. A nested value wins over the plain parameter of the same name.' ],
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
        // #3412 — a squad's potential bands, the same filtered set the
        // rendered report shows the same caller (CLAUDE.md §4). Three gates
        // in one callback because all three govern the rendered view too:
        // the analytics cap, the player-status cap, and the squad-level
        // visibility policy that keeps a player or parent out whatever the
        // dot toggle says. The team set is narrowed to what the caller may
        // read inside the query itself, so `scope` can never widen access.
        register_rest_route( self::NS, '/reports/potential-overview', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'potentialOverview' ],
                'permission_callback' => static function (): bool {
                    if ( ! current_user_can( 'tt_view_analytics' ) ) return false;
                    if ( ! current_user_can( 'tt_view_player_status' ) ) return false;
                    if ( ! \TT\Core\FeatureRegistry::isEnabled( 'analytics_potential_overview' ) ) return false;
                    return \TT\Modules\Players\Frontend\PlayerStatusVisibility::squadVisibleTo( get_current_user_id() );
                },
                'args'                => [
                    'scope'     => [ 'type' => 'string', 'description' => 'Which set the report covers: team or age_group.', 'sanitize_callback' => 'sanitize_key', 'required' => false ],
                    'team_id'   => $team_arg,
                    'age_group' => [ 'type' => 'string', 'description' => 'Only players in this age group. Same as filter[age_group].', 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    // #3790 — `bands` was read by the callback but never
                    // declared, so WP dropped it and the band filter did
                    // nothing over REST. Left untyped: it is taken as a
                    // repeated parameter or a comma-separated list, and the
                    // query sanitises the values against the band vocabulary.
                    'bands'     => [ 'description' => 'Only these potential bands, as a repeated parameter or a comma-separated list. Unknown bands are ignored.', 'required' => false ],
                    'sort'      => [ 'type' => 'string', 'description' => 'Column to order by.', 'sanitize_callback' => 'sanitize_key', 'required' => false ],
                    'dir'       => [ 'type' => 'string', 'description' => 'Order direction: asc or desc.', 'sanitize_callback' => 'sanitize_key', 'required' => false ],
                    'filter'    => [ 'description' => 'Nested filters: team_id, age_group. A nested value wins over the plain parameter of the same name.' ],
                ],
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
                    // #3790 — not `required`, because WP would refuse the
                    // request before the callback could read the nested
                    // spelling. A team is still mandatory: absent in both
                    // spellings is refused with 400 bad_filter.
                    'team_id' => array_merge( $team_arg, [ 'description' => 'Required: the team whose matrix to read. Same as filter[team_id]. Absent in both spellings, or not a usable team id, is refused with 400 bad_filter rather than answered with an empty matrix.' ] ),
                    'from'    => [ 'type' => 'string', 'description' => 'Window start ' . $window . ' Same as filter[from] or filter[date_from].', 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'to'      => [ 'type' => 'string', 'description' => 'Window end ' . $window . ' Same as filter[to] or filter[date_to].', 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'type'    => [ 'type' => 'string', 'description' => 'Only matches of this type: League, Cup or Friendly. Anything else reads every match type. Same as filter[type].', 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'filter'  => [ 'description' => 'Nested filters: team_id, from (or date_from), to (or date_to), type. A nested value wins over the plain parameter of the same name.' ],
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
    /**
     * #3412 — every player in a team or age group with their current
     * potential band, the movement that produced it, and when it was set.
     *
     * The same `PotentialOverviewQuery` the rendered report calls, with the
     * same caller id, so a non-WordPress front end gets the same rows —
     * including the players with nothing recorded, which are the ones the
     * report exists to find. `bands` narrows the set; it is read as a
     * repeated query parameter or a comma-separated list, because both
     * shapes reach a WordPress REST route depending on the client.
     */
    public static function potentialOverview( WP_REST_Request $req ): \WP_REST_Response {
        $user_id = get_current_user_id();
        $query   = new \TT\Modules\Analytics\Reports\PotentialOverviewQuery();

        $scope = \TT\Modules\Analytics\Reports\PotentialOverviewQuery::sanitizeScope(
            (string) $req->get_param( 'scope' )
        );

        // #3790 — the squad filters are taken under either spelling.
        $read = self::filterValues( $req, [
            'team_id'   => [ 'team_id' ],
            'age_group' => [ 'age_group' ],
        ] );
        if ( $read['error'] !== null ) return $read['error'];
        $team = self::filterTeamId( $read );
        if ( $team['error'] !== null ) return $team['error'];

        $team_id   = $team['team_id'];
        $age_group = sanitize_text_field( $read['values']['age_group'] );

        $raw_bands = $req->get_param( 'bands' );
        if ( is_string( $raw_bands ) ) {
            $raw_bands = $raw_bands === '' ? [] : explode( ',', $raw_bands );
        }
        $bands = \TT\Modules\Analytics\Reports\PotentialOverviewQuery::sanitizeBands(
            array_map( 'strval', is_array( $raw_bands ) ? $raw_bands : [] )
        );

        $rows = $query->rows(
            $user_id,
            $scope,
            $team_id,
            $age_group,
            $bands,
            (string) $req->get_param( 'sort' ),
            (string) $req->get_param( 'dir' )
        );

        // The summary describes the scope, not the filtered slice — the
        // same choice the rendered report makes, so the two cannot report
        // different coverage for the same squad.
        $all = $bands === [] ? $rows : $query->rows( $user_id, $scope, $team_id, $age_group );

        return RestResponse::success( [
            'scope'     => $scope,
            'team_id'   => $team_id,
            'age_group' => $age_group,
            'bands'     => $bands,
            'teams'     => $query->teamsInScope( $user_id, $scope, $team_id, $age_group ),
            'summary'   => \TT\Modules\Analytics\Reports\PotentialOverviewQuery::summarise( $all ),
            'rows'      => $rows,
        ] );
    }

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
        // #3790 — either spelling, nested wins, and a team nobody can
        // resolve is refused rather than dropped. Dropping it used to mean
        // an empty matrix that read as "this team played nothing".
        $read = self::filterValues( $req, [
            'team_id' => [ 'team_id' ],
            'from'    => [ 'from', 'date_from' ],
            'to'      => [ 'to', 'date_to' ],
            'type'    => [ 'type' ],
        ] );
        if ( $read['error'] !== null ) return $read['error'];
        $team = self::filterTeamId( $read, true );
        if ( $team['error'] !== null ) return $team['error'];

        $team_id = $team['team_id'];
        [ $from, $to ] = self::resolveWindow( $read['values']['from'], $read['values']['to'] );
        $type = $read['values']['type'];
        if ( ! in_array( $type, [ 'League', 'Cup', 'Friendly' ], true ) ) $type = 'all';

        $allowed = self::attendanceScope( $team_id );
        if ( $allowed['blocked'] ) {
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

    public static function attendanceRows( WP_REST_Request $req ): \WP_REST_Response {
        $query = self::attendanceQuery( $req );
        if ( $query['error'] !== null ) return $query['error'];
        $from          = $query['from'];
        $to            = $query['to'];
        $team_id       = $query['team_id'];
        $type_key      = $query['activity_type_key'];
        $allowed       = self::attendanceScope( $team_id );
        // #2893 — a permission block is not an empty result. Returning
        // success([]) made the client print "No player attendance in this
        // window", which asserts something false about the data; the
        // caller cannot tell the two apart from a 200 with an empty array.
        if ( $allowed['blocked'] ) return self::attendanceForbidden();

        $players = ( new AttendanceRankingQuery() )->rows( $from, $to, $team_id, $allowed['team_ids'], $type_key );
        return RestResponse::success( [
            'players'   => $players,
            'threshold' => AttendanceFlagService::threshold(),
            // #3717 — the window the rows were read over. Without it an
            // empty list cannot be read in context, and a caller that let
            // the default resolve cannot label the period it got.
            'from'      => $from,
            'to'        => $to,
        ] );
    }

    public static function attendanceLeaderboard( WP_REST_Request $req ): \WP_REST_Response {
        $query           = self::attendanceQuery( $req );
        if ( $query['error'] !== null ) return $query['error'];
        $from            = $query['from'];
        $to              = $query['to'];
        $team_id         = $query['team_id'];
        // #2205 — unset/blank `n` means all players in the window; a
        // supplied positive number narrows each column.
        $n               = (int) $req->get_param( 'n' );
        $type_key        = $query['activity_type_key'];
        $allowed         = self::attendanceScope( $team_id );
        if ( $allowed['blocked'] ) return self::attendanceForbidden();

        $board = ( new AttendanceRankingQuery() )->leaderboard( $from, $to, $n, $team_id, $allowed['team_ids'], $type_key );
        // #3717 — `top` / `bottom` / `total` plus the window they describe.
        $board['from'] = $from;
        $board['to']   = $to;
        return RestResponse::success( $board );
    }

    public static function attendanceAtRisk( WP_REST_Request $req ): \WP_REST_Response {
        $query           = self::attendanceQuery( $req );
        if ( $query['error'] !== null ) return $query['error'];
        $from            = $query['from'];
        $to              = $query['to'];
        $team_id         = $query['team_id'];
        $type_key        = $query['activity_type_key'];
        $allowed         = self::attendanceScope( $team_id );
        if ( $allowed['blocked'] ) return self::attendanceForbidden();

        $players = ( new AttendanceRankingQuery() )->atRisk( $from, $to, $team_id, $allowed['team_ids'], $type_key );
        return RestResponse::success( [
            'players'   => $players,
            'threshold' => AttendanceFlagService::threshold(),
            // #3717 — "nobody is at risk" is only an answer once the reader
            // knows over which period nobody was.
            'from'      => $from,
            'to'        => $to,
        ] );
    }

    /**
     * Resolve + validate a report's `from`/`to` window.
     *
     * #3717 — the default is the season window
     * (`ReportFilters::seasonDefaultWindow()`), the same helper the three
     * attendance screens and the sibling minutes-audit route already seed
     * from. It used to be a rolling 90 days, so the same report answered
     * with a different set of players over REST than on screen — a player
     * read as at risk for an unlabelled period nobody had picked. The
     * helper keeps the 90-day rolling window as its own fallback when no
     * current season is configured.
     *
     * #3790 — the minutes audit resolved its window with an identical
     * private copy of this; one helper now, so the two reports cannot drift
     * into answering for different periods.
     *
     * @return array{0:string,1:string}
     */
    private static function resolveWindow( string $from, string $to ): array {
        $default = \TT\Modules\Analytics\Reports\ReportFilters::seasonDefaultWindow();
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = $default['from'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = $default['to'];
        return [ $from, $to ];
    }

    /**
     * #3780 — resolve the three attendance readers' parameters from either
     * spelling.
     *
     * @return array{team_id:int, from:string, to:string, activity_type_key:string, error:\WP_REST_Response|null}
     */
    private static function attendanceQuery( WP_REST_Request $req ): array {
        $read = self::filterValues( $req, [
            'team_id'           => [ 'team_id' ],
            'from'              => [ 'from', 'date_from' ],
            'to'                => [ 'to', 'date_to' ],
            'activity_type_key' => [ 'activity_type_key' ],
        ] );
        if ( $read['error'] !== null ) return self::attendanceRefusal( $read['error'] );

        $team = self::filterTeamId( $read );
        if ( $team['error'] !== null ) return self::attendanceRefusal( $team['error'] );

        // A malformed date is not refused: #3717 settled that it falls back
        // to the season window and the response echoes the window actually
        // read, so the caller can see which period they got. An unknown
        // activity type narrows to nothing, which is also not a widening.
        [ $window_from, $window_to ] = self::resolveWindow( $read['values']['from'], $read['values']['to'] );

        return [
            'team_id'           => $team['team_id'],
            'from'              => $window_from,
            'to'                => $window_to,
            'activity_type_key' => sanitize_key( $read['values']['activity_type_key'] ),
            'error'             => null,
        ];
    }

    /**
     * @return array{team_id:int, from:string, to:string, activity_type_key:string, error:\WP_REST_Response}
     */
    private static function attendanceRefusal( \WP_REST_Response $error ): array {
        return [
            'team_id'           => 0,
            'from'              => '',
            'to'                => '',
            'activity_type_key' => '',
            'error'             => $error,
        ];
    }

    /**
     * #3780, #3790 — read a report route's filters from either the plain
     * parameter names or the nested `filter[...]` form the rest of the list
     * API uses. The nested value wins when both are sent, the same
     * precedence #3584, #3607, #3668 and #3765 settled on.
     *
     * `filter[team_id]` used to be discarded — WP REST drops a query
     * parameter no route declared, without a word — so an administrator
     * asking for one squad's at-risk list, minutes audit or potential
     * overview got every team they may read back, each row carrying a real
     * team name that made the answer look deliberate. A player from another
     * age group could end up named in a team's absence or selection
     * conversation. A filter that is sent but cannot be resolved is
     * therefore refused rather than dropped: dropping it is exactly what
     * widened the read.
     *
     * Widening access is still impossible either way — the scope checks run
     * on the resolved team, after this, so a team outside the caller's scope
     * answers the same way through both spellings.
     *
     * @param array<string, list<string>> $spec plain name => nested keys, in precedence order
     * @return array{values:array<string,string>, names:array<string,string>, error:\WP_REST_Response|null}
     */
    private static function filterValues( WP_REST_Request $req, array $spec ): array {
        $nested = $req->get_param( 'filter' );
        $nested = is_array( $nested ) ? $nested : [];

        $values = [];
        $names  = [];
        foreach ( $spec as $flat => $keys ) {
            $flat  = (string) $flat;
            $param = self::filterParam( $req, $nested, $flat, $keys );
            if ( ! $param['usable'] ) {
                return [ 'values' => [], 'names' => [], 'error' => self::badFilter( $param['name'] ) ];
            }
            $values[ $flat ] = $param['value'];
            $names[ $flat ]  = $param['name'];
        }

        return [ 'values' => $values, 'names' => $names, 'error' => null ];
    }

    /**
     * One filter, read from the nested `filter[...]` form first and the
     * plain name second, carrying the spelling it was read from so a
     * refusal can name it.
     *
     * `usable` is false when a nested key holds an array or an object where
     * a value belongs. That is not a filter anybody can act on, and reading
     * it as absent is the widening this whole fix is about.
     *
     * @param array<mixed> $nested
     * @param list<string> $keys the nested keys, in precedence order
     * @return array{value:string, name:string, usable:bool}
     */
    private static function filterParam( WP_REST_Request $req, array $nested, string $flat, array $keys ): array {
        foreach ( $keys as $key ) {
            if ( ! array_key_exists( $key, $nested ) ) continue;
            $candidate = $nested[ $key ];
            if ( ! is_scalar( $candidate ) ) {
                return [ 'value' => '', 'name' => 'filter[' . $key . ']', 'usable' => false ];
            }
            if ( (string) $candidate === '' ) continue;
            return [ 'value' => (string) $candidate, 'name' => 'filter[' . $key . ']', 'usable' => true ];
        }
        $plain = $req->get_param( $flat );
        return [
            'value'  => is_scalar( $plain ) ? (string) $plain : '',
            'name'   => $flat,
            'usable' => true,
        ];
    }

    /**
     * The team filter every report route shares, resolved from whichever
     * spelling carried it. `$required` is the minutes audit, whose matrix
     * is meaningless without a team: absent is refused there rather than
     * answered with an empty grid that reads as "this team played nothing".
     *
     * @param array{values:array<string,string>, names:array<string,string>, error:\WP_REST_Response|null} $read
     * @return array{team_id:int, error:\WP_REST_Response|null}
     */
    private static function filterTeamId( array $read, bool $required = false ): array {
        $raw  = $read['values']['team_id'] ?? '';
        $name = $read['names']['team_id'] ?? 'team_id';

        if ( $raw === '' ) {
            return [ 'team_id' => 0, 'error' => $required ? self::badFilter( $name ) : null ];
        }
        $team_id = absint( $raw );
        if ( $team_id <= 0 ) {
            return [ 'team_id' => 0, 'error' => self::badFilter( $name ) ];
        }
        return [ 'team_id' => $team_id, 'error' => null ];
    }

    /** The one refusal a filter nobody can resolve earns, naming the spelling at fault. */
    private static function badFilter( string $parameter ): \WP_REST_Response {
        return RestResponse::error(
            'bad_filter',
            __( 'That filter value is not a valid id.', 'talenttrack' ),
            400,
            [ 'parameter' => $parameter ]
        );
    }

    /**
     * The one refusal the three attendance readers share (#2893).
     *
     * A distinct code so the client can say "you cannot see this team's
     * players" rather than "this team has no attendance" — the second is
     * a claim about the data, and it was the wrong one.
     */
    private static function attendanceForbidden(): \WP_REST_Response {
        return RestResponse::error(
            'forbidden_team',
            __( 'You do not have access to this team’s players.', 'talenttrack' ),
            403
        );
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
        // #2893 — the `tt_edit_settings` fallback is what the render side
        // and `MinutesRestController::…` both use; this controller was the
        // outlier. Without it a user holding `tt_edit_settings` but no
        // persona carrying global read on `activities` saw every team in
        // the rendered table and was blocked on every drill-down — the
        // table and its own expansion contradicting each other.
        $is_scope_admin = current_user_can( 'tt_edit_settings' )
            || \TT\Modules\Authorization\AllTeamsScope::canSeeAllTeamsActivities( get_current_user_id() );
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
        // #3790 — either spelling, nested wins. The dates keep their
        // pass-through behaviour: a blank one means "no bound", and the
        // query binds whatever is supplied.
        $read = self::filterValues( $req, [
            'team_id'   => [ 'team_id' ],
            'date_from' => [ 'date_from', 'from' ],
            'date_to'   => [ 'date_to', 'to' ],
        ] );
        if ( $read['error'] !== null ) return $read['error'];
        $team = self::filterTeamId( $read );
        if ( $team['error'] !== null ) return $team['error'];

        $rows = ( new CoachEvalQualityQuery() )->rows( [
            'team_id'   => $team['team_id'],
            'date_from' => sanitize_text_field( $read['values']['date_from'] ),
            'date_to'   => sanitize_text_field( $read['values']['date_to'] ),
        ] );
        return RestResponse::success( [
            'rows'                   => $rows,
            'low_variance_threshold' => CoachEvalQualityQuery::LOW_VARIANCE_THRESHOLD,
            'min_ratings_for_flag'   => CoachEvalQualityQuery::MIN_RATINGS_FOR_FLAG,
        ] );
    }
}
