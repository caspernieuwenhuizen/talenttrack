<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * SessionsRestController — /wp-json/talenttrack/v1/sessions
 *
 * #0019 Sprint 1 — replaces the legacy `tt_fe_save_session` admin-ajax
 * path. Attendance is a nested sub-resource handled inline on create
 * and update because the UI posts the full attendance matrix with the
 * session form. Fail-loud: every $wpdb write return value is checked
 * and failures land in the Logger.
 */
class SessionsRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/sessions', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_sessions' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_session' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
        register_rest_route( self::NS, '/sessions/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_session' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_session' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
    }

    public static function can_view(): bool {
        return current_user_can( 'tt_view_sessions' ) || current_user_can( 'tt_edit_sessions' );
    }

    public static function can_edit(): bool {
        return current_user_can( 'tt_edit_sessions' );
    }

    /** Whitelist of columns the `orderby` query param accepts. */
    private const ORDERBY_WHITELIST = [
        'session_date' => 's.session_date',
        'title'        => 's.title',
        'team_name'    => 't.name',
        'attendance'   => 'attendance_count',
    ];

    /**
     * GET /sessions — paginated list with search, filters, sort.
     *
     * Query params (Sprint 2 contract):
     *   ?search=<text>            — title / location / team name LIKE
     *   ?filter[team_id]=<int>
     *   ?filter[date_from]=<YYYY-MM-DD>
     *   ?filter[date_to]=<YYYY-MM-DD>
     *   ?filter[attendance]=complete|partial|none
     *   ?orderby=session_date|title|team_name|attendance
     *   ?order=asc|desc                                   (default: desc on session_date, asc otherwise)
     *   ?page=<int>                                       (default 1)
     *   ?per_page=10|25|50|100                            (default 25)
     *   ?include_archived=1                               (default off)
     *
     * Coach-scoping: non-admin users only see sessions for teams they
     * head-coach. Admins (`tt_edit_settings`) see all.
     *
     * Attendance-completeness is computed on the fly per row (Q2 in the
     * Sprint 2 plan): roster size = active players on the team;
     * attendance_count = rows in tt_attendance for the session;
     * complete = count >= roster (and roster > 0); partial = 0 < count < roster;
     * none = count = 0.
     *
     * @return \WP_REST_Response
     */
    public static function list_sessions( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $page     = max( 1, absint( $r['page'] ?? 1 ) );
        $per_page = self::clamp_per_page( $r['per_page'] ?? 25 );
        $offset   = ( $page - 1 ) * $per_page;

        $orderby_key = sanitize_key( (string) ( $r['orderby'] ?? 'session_date' ) );
        if ( ! isset( self::ORDERBY_WHITELIST[ $orderby_key ] ) ) {
            return RestResponse::error(
                'bad_orderby',
                __( 'Unknown orderby column.', 'talenttrack' ),
                400,
                [ 'allowed' => array_keys( self::ORDERBY_WHITELIST ) ]
            );
        }
        $orderby = self::ORDERBY_WHITELIST[ $orderby_key ];
        $order   = strtolower( (string) ( $r['order'] ?? ( $orderby_key === 'session_date' ? 'desc' : 'asc' ) ) );
        if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) $order = 'desc';

        $where  = [ '1=1' ];
        $params = [];

        $scope = QueryHelpers::apply_demo_scope( 's', 'session' );

        // Archived filter — default hides archived rows.
        if ( empty( $r['include_archived'] ) ) {
            $where[] = 's.archived_at IS NULL';
        }

        // Coach-scoping for non-admins.
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            $coach_teams = QueryHelpers::get_teams_for_coach( get_current_user_id() );
            if ( ! $coach_teams ) {
                // No accessible teams → empty list (don't expose sessions).
                return RestResponse::success( [
                    'rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page,
                ] );
            }
            $team_ids = array_map( static function ( $t ) { return (int) $t->id; }, $coach_teams );
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            $where[] = "s.team_id IN ($placeholders)";
            $params = array_merge( $params, $team_ids );
        }

        // Filters.
        $filter = is_array( $r['filter'] ?? null ) ? $r['filter'] : [];

        if ( ! empty( $filter['team_id'] ) ) {
            $where[]  = 's.team_id = %d';
            $params[] = absint( $filter['team_id'] );
        }
        if ( ! empty( $filter['date_from'] ) ) {
            $where[]  = 's.session_date >= %s';
            $params[] = sanitize_text_field( (string) $filter['date_from'] );
        }
        if ( ! empty( $filter['date_to'] ) ) {
            $where[]  = 's.session_date <= %s';
            $params[] = sanitize_text_field( (string) $filter['date_to'] );
        }

        // Search across title, location, team name.
        if ( ! empty( $r['search'] ) ) {
            $like = '%' . $wpdb->esc_like( (string) $r['search'] ) . '%';
            $where[]  = '(s.title LIKE %s OR s.location LIKE %s OR t.name LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where ) . ' ' . $scope;

        // Attendance computed columns via correlated subqueries. Sized
        // OK for the 100-row max; if perf becomes a problem we revisit
        // (Q2 in the Sprint 2 plan accepts that risk).
        $select_cols = "s.*, t.name AS team_name,
            (SELECT COUNT(*) FROM {$p}tt_attendance a WHERE a.session_id = s.id) AS attendance_count,
            (SELECT COUNT(*) FROM {$p}tt_players pl WHERE pl.team_id = s.team_id) AS roster_size";

        $having = '';
        $att_filter = isset( $filter['attendance'] ) ? sanitize_key( (string) $filter['attendance'] ) : '';
        if ( $att_filter === 'complete' ) {
            $having = 'HAVING attendance_count >= roster_size AND roster_size > 0';
        } elseif ( $att_filter === 'partial' ) {
            $having = 'HAVING attendance_count > 0 AND attendance_count < roster_size';
        } elseif ( $att_filter === 'none' ) {
            $having = 'HAVING attendance_count = 0';
        }

        $list_sql = "SELECT {$select_cols}
                     FROM {$p}tt_sessions s
                     LEFT JOIN {$p}tt_teams t ON t.id = s.team_id
                     WHERE {$where_sql}
                     {$having}
                     ORDER BY {$orderby} {$order}
                     LIMIT %d OFFSET %d";

        $list_params = array_merge( $params, [ $per_page, $offset ] );
        $rows = $list_params
            ? $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) )
            : $wpdb->get_results( $list_sql );

        // Total count — same WHERE + HAVING, but COUNT(*) over the
        // grouped result. With HAVING we wrap in a subquery so the
        // total reflects the post-HAVING row count.
        if ( $having !== '' ) {
            $count_sql = "SELECT COUNT(*) FROM (
                SELECT s.id,
                    (SELECT COUNT(*) FROM {$p}tt_attendance a WHERE a.session_id = s.id) AS attendance_count,
                    (SELECT COUNT(*) FROM {$p}tt_players pl WHERE pl.team_id = s.team_id) AS roster_size
                FROM {$p}tt_sessions s
                LEFT JOIN {$p}tt_teams t ON t.id = s.team_id
                WHERE {$where_sql}
                {$having}
            ) AS sub";
        } else {
            $count_sql = "SELECT COUNT(*) FROM {$p}tt_sessions s
                          LEFT JOIN {$p}tt_teams t ON t.id = s.team_id
                          WHERE {$where_sql}";
        }
        $total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
                         : (int) $wpdb->get_var( $count_sql );

        return RestResponse::success( [
            'rows'     => array_map( [ __CLASS__, 'format_row' ], $rows ?: [] ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    /** Per-page values the client may request. Defaults to 25. */
    private static function clamp_per_page( $value ): int {
        $n = absint( $value );
        if ( ! in_array( $n, [ 10, 25, 50, 100 ], true ) ) return 25;
        return $n;
    }

    /** Shape one row for the JSON response. */
    private static function format_row( $row ): array {
        $attendance_pct = null;
        $roster = (int) ( $row->roster_size ?? 0 );
        $count  = (int) ( $row->attendance_count ?? 0 );
        if ( $roster > 0 ) $attendance_pct = (int) round( ( $count / $roster ) * 100 );

        return [
            'id'               => (int) $row->id,
            'title'            => (string) $row->title,
            'session_date'     => (string) $row->session_date,
            'location'         => (string) ( $row->location ?? '' ),
            'team_id'          => (int) ( $row->team_id ?? 0 ),
            'team_name'        => (string) ( $row->team_name ?? '' ),
            'coach_id'         => (int) ( $row->coach_id ?? 0 ),
            'attendance_count' => $count,
            'roster_size'      => $roster,
            'attendance_pct'   => $attendance_pct,
            'archived_at'      => $row->archived_at ?? null,
        ];
    }

    public static function create_session( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $data = self::extract( $r );
        $data['coach_id'] = get_current_user_id();

        if ( $data['title'] === '' || $data['session_date'] === '' ) {
            return RestResponse::error( 'missing_fields', __( 'Title and date are required.', 'talenttrack' ), 400 );
        }

        $ok = $wpdb->insert( "{$p}tt_sessions", $data );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'session.save.failed', [ 'db_error' => $err, 'payload' => $data ] );
            return RestResponse::error(
                'db_error',
                __( 'The session could not be saved. The database rejected the operation.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }
        $session_id = (int) $wpdb->insert_id;

        $att_failures = self::write_attendance( $session_id, self::attendance_from_request( $r ) );
        if ( $att_failures ) {
            Logger::error( 'session.attendance.save.failed', [ 'session_id' => $session_id, 'failures' => $att_failures ] );
            return RestResponse::error(
                'partial_save',
                __( 'The session was saved, but some attendance rows could not be stored.', 'talenttrack' ),
                500,
                [ 'session_id' => $session_id, 'failures' => $att_failures ]
            );
        }

        return RestResponse::success( [ 'id' => $session_id ] );
    }

    public static function update_session( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $session_id = absint( $r['id'] );
        if ( $session_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid session id.', 'talenttrack' ), 400 );
        }

        $data = self::extract( $r );
        // Preserve original coach on update.
        unset( $data['coach_id'] );

        $ok = $wpdb->update( "{$p}tt_sessions", $data, [ 'id' => $session_id ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'session.update.failed', [ 'db_error' => $err, 'session_id' => $session_id ] );
            return RestResponse::error(
                'db_error',
                __( 'The session could not be updated. The database rejected the operation.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }

        if ( self::request_has_attendance( $r ) ) {
            $wpdb->delete( "{$p}tt_attendance", [ 'session_id' => $session_id ] );
            $att_failures = self::write_attendance( $session_id, self::attendance_from_request( $r ) );
            if ( $att_failures ) {
                Logger::error( 'session.attendance.update.failed', [ 'session_id' => $session_id, 'failures' => $att_failures ] );
                return RestResponse::error(
                    'partial_save',
                    __( 'The session was updated, but some attendance rows could not be stored.', 'talenttrack' ),
                    500,
                    [ 'session_id' => $session_id, 'failures' => $att_failures ]
                );
            }
        }

        return RestResponse::success( [ 'id' => $session_id ] );
    }

    public static function delete_session( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $session_id = absint( $r['id'] );
        if ( $session_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid session id.', 'talenttrack' ), 400 );
        }

        $wpdb->delete( "{$p}tt_attendance", [ 'session_id' => $session_id ] );
        $ok = $wpdb->delete( "{$p}tt_sessions", [ 'id' => $session_id ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'session.delete.failed', [ 'db_error' => $err, 'session_id' => $session_id ] );
            return RestResponse::error(
                'db_error',
                __( 'The session could not be deleted.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }

        return RestResponse::success( [ 'deleted' => true, 'id' => $session_id ] );
    }

    /**
     * @return array<string, mixed>
     */
    private static function extract( \WP_REST_Request $r ): array {
        return [
            'title'        => sanitize_text_field( (string) ( $r['title'] ?? '' ) ),
            'session_date' => sanitize_text_field( (string) ( $r['session_date'] ?? '' ) ),
            'team_id'      => absint( $r['team_id'] ?? 0 ),
            'coach_id'     => get_current_user_id(),
            'location'     => sanitize_text_field( (string) ( $r['location'] ?? '' ) ),
            'notes'        => sanitize_textarea_field( (string) ( $r['notes'] ?? '' ) ),
        ];
    }

    /**
     * Accept attendance under either `attendance` (new name) or the
     * legacy `att` key that the pre-REST form used.
     *
     * @return array<int, array{status:string, notes:string}>
     */
    private static function attendance_from_request( \WP_REST_Request $r ): array {
        $raw = $r['attendance'] ?? $r['att'] ?? [];
        if ( ! is_array( $raw ) ) return [];
        $out = [];
        foreach ( $raw as $player_id => $fields ) {
            if ( ! is_array( $fields ) ) continue;
            $pid = absint( $player_id );
            if ( $pid <= 0 ) continue;
            $out[ $pid ] = [
                'status' => sanitize_text_field( (string) ( $fields['status'] ?? 'Present' ) ),
                'notes'  => sanitize_text_field( (string) ( $fields['notes'] ?? '' ) ),
            ];
        }
        return $out;
    }

    private static function request_has_attendance( \WP_REST_Request $r ): bool {
        return isset( $r['attendance'] ) || isset( $r['att'] );
    }

    /**
     * @param array<int, array{status:string, notes:string}> $rows
     * @return array<int, array{player_id:int, db_error:string}>
     */
    private static function write_attendance( int $session_id, array $rows ): array {
        if ( ! $rows ) return [];
        global $wpdb; $p = $wpdb->prefix;
        $failures = [];
        foreach ( $rows as $pid => $fields ) {
            $ok = $wpdb->insert( "{$p}tt_attendance", [
                'session_id' => $session_id,
                'player_id'  => (int) $pid,
                'status'     => $fields['status'],
                'notes'      => $fields['notes'],
            ] );
            if ( $ok === false ) {
                $failures[] = [ 'player_id' => (int) $pid, 'db_error' => (string) $wpdb->last_error ];
            }
        }
        return $failures;
    }
}
