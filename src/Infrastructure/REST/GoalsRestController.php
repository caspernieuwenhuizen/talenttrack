<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Repositories\GoalLinksRepository;

/**
 * GoalsRestController — /wp-json/talenttrack/v1/goals
 *
 * #0019 Sprint 1 — replaces the legacy `tt_fe_save_goal`,
 * `tt_fe_update_goal_status`, and `tt_fe_delete_goal` admin-ajax
 * handlers. The PATCH `/goals/{id}/status` route matches the inline
 * status-select dropdown flow; the main PUT `/goals/{id}` is for
 * future edit-goal views.
 */
class GoalsRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/goals', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_goals' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_goal' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
        register_rest_route( self::NS, '/goals/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_goal' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_goal' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
        register_rest_route( self::NS, '/goals/(?P<id>\d+)/status', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_status' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
    }

    public static function can_view(): bool {
        return current_user_can( 'tt_view_goals' ) || current_user_can( 'tt_edit_goals' );
    }

    public static function can_edit(): bool {
        return current_user_can( 'tt_edit_goals' );
    }

    /** Whitelist of columns the `orderby` query param accepts. */
    private const ORDERBY_WHITELIST = [
        'due_date'    => 'g.due_date',
        'created_at'  => 'g.created_at',
        'status'      => 'g.status',
        'priority'    => 'g.priority',
        'title'       => 'g.title',
        'player_name' => 'pl.last_name',
    ];

    /**
     * GET /goals — paginated list with search, filters, sort.
     *
     * Query params (Sprint 2 contract):
     *   ?search=<text>           — title / description / player name LIKE
     *   ?filter[team_id]=<int>   — via the player → team join
     *   ?filter[player_id]=<int>
     *   ?filter[status]=<string>
     *   ?filter[priority]=<string>
     *   ?filter[due_from]=<YYYY-MM-DD>
     *   ?filter[due_to]=<YYYY-MM-DD>
     *   ?orderby=due_date|created_at|status|priority|title|player_name
     *   ?order=asc|desc                                   (default: asc on due_date, desc otherwise)
     *   ?page=<int>                                       (default 1)
     *   ?per_page=10|25|50|100                            (default 25)
     *   ?include_archived=1                               (default off)
     *
     * Coach-scoping: non-admin users only see goals for players on
     * teams they head-coach.
     */
    public static function list_goals( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $page     = max( 1, absint( $r['page'] ?? 1 ) );
        $per_page = self::clamp_per_page( $r['per_page'] ?? 25 );
        $offset   = ( $page - 1 ) * $per_page;

        $orderby_key = sanitize_key( (string) ( $r['orderby'] ?? 'due_date' ) );
        if ( ! isset( self::ORDERBY_WHITELIST[ $orderby_key ] ) ) {
            return RestResponse::error(
                'bad_orderby',
                __( 'Unknown orderby column.', 'talenttrack' ),
                400,
                [ 'allowed' => array_keys( self::ORDERBY_WHITELIST ) ]
            );
        }
        $orderby = self::ORDERBY_WHITELIST[ $orderby_key ];
        $order   = strtolower( (string) ( $r['order'] ?? ( $orderby_key === 'due_date' ? 'asc' : 'desc' ) ) );
        if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) $order = 'asc';

        $where  = [ '1=1', 'g.club_id = %d' ];
        $params = [ CurrentClub::id() ];

        $scope = QueryHelpers::apply_demo_scope( 'g', 'goal' );

        if ( empty( $r['include_archived'] ) ) {
            $where[] = 'g.archived_at IS NULL';
        }

        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            $coach_teams = QueryHelpers::get_teams_for_coach( get_current_user_id() );
            if ( ! $coach_teams ) {
                return RestResponse::success( [
                    'rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page,
                ] );
            }
            $team_ids = array_map( static function ( $t ) { return (int) $t->id; }, $coach_teams );
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            $where[] = "pl.team_id IN ($placeholders)";
            $params  = array_merge( $params, $team_ids );
        }

        $filter = is_array( $r['filter'] ?? null ) ? $r['filter'] : [];

        if ( ! empty( $filter['team_id'] ) ) {
            $where[]  = 'pl.team_id = %d';
            $params[] = absint( $filter['team_id'] );
        }
        if ( ! empty( $filter['player_id'] ) ) {
            $where[]  = 'g.player_id = %d';
            $params[] = absint( $filter['player_id'] );
        }
        if ( ! empty( $filter['status'] ) ) {
            $where[]  = 'g.status = %s';
            $params[] = sanitize_text_field( (string) $filter['status'] );
        }
        if ( ! empty( $filter['priority'] ) ) {
            $where[]  = 'g.priority = %s';
            $params[] = sanitize_text_field( (string) $filter['priority'] );
        }
        if ( ! empty( $filter['due_from'] ) ) {
            $where[]  = 'g.due_date >= %s';
            $params[] = sanitize_text_field( (string) $filter['due_from'] );
        }
        if ( ! empty( $filter['due_to'] ) ) {
            $where[]  = 'g.due_date <= %s';
            $params[] = sanitize_text_field( (string) $filter['due_to'] );
        }

        if ( ! empty( $r['search'] ) ) {
            $like = '%' . $wpdb->esc_like( (string) $r['search'] ) . '%';
            $where[]  = "(g.title LIKE %s OR g.description LIKE %s OR pl.first_name LIKE %s OR pl.last_name LIKE %s)";
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where ) . ' ' . $scope;

        $list_sql = "SELECT g.*,
                            pl.first_name, pl.last_name, pl.team_id,
                            t.name AS team_name
                     FROM {$p}tt_goals g
                     LEFT JOIN {$p}tt_players pl ON pl.id = g.player_id AND pl.club_id = g.club_id
                     LEFT JOIN {$p}tt_teams   t  ON t.id = pl.team_id AND t.club_id = pl.club_id
                     WHERE {$where_sql}
                     ORDER BY {$orderby} {$order}
                     LIMIT %d OFFSET %d";

        $list_params = array_merge( $params, [ $per_page, $offset ] );
        $rows = $list_params
            ? $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) )
            : $wpdb->get_results( $list_sql );

        $count_sql = "SELECT COUNT(*) FROM {$p}tt_goals g
                      LEFT JOIN {$p}tt_players pl ON pl.id = g.player_id AND pl.club_id = g.club_id
                      LEFT JOIN {$p}tt_teams   t  ON t.id = pl.team_id AND t.club_id = pl.club_id
                      WHERE {$where_sql}";

        $total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
                         : (int) $wpdb->get_var( $count_sql );

        return RestResponse::success( [
            'rows'     => array_map( [ __CLASS__, 'format_row' ], $rows ?: [] ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    private static function clamp_per_page( $value ): int {
        $n = absint( $value );
        if ( ! in_array( $n, [ 10, 25, 50, 100 ], true ) ) return 25;
        return $n;
    }

    private static function format_row( $row ): array {
        $first = (string) ( $row->first_name ?? '' );
        $last  = (string) ( $row->last_name ?? '' );
        $player_name = trim( $first . ' ' . $last );
        $player_id   = (int) $row->player_id;
        $title       = (string) $row->title;
        $goal_id     = (int) $row->id;

        // #0063 — pre-rendered RecordLink HTML so FrontendListTable can
        // render player + goal cells as drillable links via render: html.
        $player_link_html = '';
        if ( $player_name !== '' && $player_id > 0 ) {
            $player_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
                $player_name,
                \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'players', $player_id )
            );
        }
        $title_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
            $title,
            add_query_arg( [ 'tt_view' => 'my-goals', 'id' => $goal_id ], home_url( '/' ) )
        );
        $status_pill_html = \TT\Infrastructure\Query\LookupPill::render( 'goal_status', (string) ( $row->status ?? '' ) );

        return [
            'id'                => $goal_id,
            'player_id'         => $player_id,
            'player_name'       => $player_name,
            'player_link_html'  => $player_link_html,
            'team_id'           => (int) ( $row->team_id ?? 0 ),
            'team_name'         => (string) ( $row->team_name ?? '' ),
            'title'             => $title,
            'title_link_html'   => $title_link_html,
            'description'       => (string) ( $row->description ?? '' ),
            'status'            => (string) ( $row->status ?? '' ),
            'status_pill_html'  => $status_pill_html,
            'priority'          => (string) ( $row->priority ?? '' ),
            'due_date'          => $row->due_date,
            'created_at'        => $row->created_at,
            'created_by'        => (int) ( $row->created_by ?? 0 ),
            'archived_at'       => $row->archived_at ?? null,
        ];
    }

    public static function create_goal( \WP_REST_Request $r ) {
        global $wpdb;

        $data = [
            'club_id'     => CurrentClub::id(),
            'player_id'   => absint( $r['player_id'] ?? 0 ),
            'title'       => sanitize_text_field( (string) ( $r['title'] ?? '' ) ),
            'description' => sanitize_textarea_field( (string) ( $r['description'] ?? '' ) ),
            'status'      => sanitize_text_field( (string) ( $r['status'] ?? 'pending' ) ),
            'priority'    => sanitize_text_field( (string) ( $r['priority'] ?? 'medium' ) ),
            'due_date'    => ! empty( $r['due_date'] ) ? sanitize_text_field( (string) $r['due_date'] ) : null,
            'created_by'  => get_current_user_id(),
        ];

        if ( $data['player_id'] <= 0 || $data['title'] === '' ) {
            return RestResponse::error( 'missing_fields', __( 'Player and title are required.', 'talenttrack' ), 400 );
        }

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_goals', $data );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'goal.save.failed', [ 'db_error' => $err, 'payload' => $data ] );
            return RestResponse::error(
                'db_error',
                __( 'The goal could not be saved. The database rejected the operation.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }

        $goal_id = (int) $wpdb->insert_id;
        // #0025 — detect source language for the new free-text fields.
        \TT\Modules\Translations\TranslationLayer::detectAndCache( 'goal', $goal_id, 'title',       (string) $data['title'] );
        \TT\Modules\Translations\TranslationLayer::detectAndCache( 'goal', $goal_id, 'description', (string) $data['description'] );

        // #0053 — journey event hook so subscribers (currently
        // JourneyEventSubscriber) can emit `goal_set` exactly once per
        // goal creation. uk_natural keeps re-fires from multiplying.
        do_action( 'tt_goal_saved', (int) $data['player_id'], $goal_id, $data );

        // #0044 — polymorphic links to methodology principles, football
        // actions, positions, player values. Optional; absent on legacy
        // goal save flows.
        if ( isset( $r['links'] ) && is_array( $r['links'] ) ) {
            ( new GoalLinksRepository() )->sync( $goal_id, self::extractLinks( $r['links'] ) );
        }

        return RestResponse::success( [ 'id' => $goal_id ] );
    }

    /**
     * Normalise a free-form links array into the shape GoalLinksRepository
     * expects. Accepts either:
     *   - [ ['type' => 'principle', 'id' => 12], ... ]
     *   - [ 'principle' => [12, 13], 'value' => [4] ]    (grouped form)
     *
     * @param array<mixed> $raw
     * @return list<array{type:string, id:int}>
     */
    private static function extractLinks( array $raw ): array {
        $out = [];
        foreach ( $raw as $key => $value ) {
            // Grouped form: { principle: [1,2], value: [3] }
            if ( is_string( $key ) && is_array( $value ) ) {
                foreach ( $value as $id ) {
                    $out[] = [ 'type' => $key, 'id' => (int) $id ];
                }
                continue;
            }
            // List form: [ {type, id}, ... ]
            if ( is_array( $value ) && isset( $value['type'], $value['id'] ) ) {
                $out[] = [ 'type' => (string) $value['type'], 'id' => (int) $value['id'] ];
            }
        }
        return $out;
    }

    public static function update_goal( \WP_REST_Request $r ) {
        global $wpdb;
        $goal_id = absint( $r['id'] );
        if ( $goal_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid goal id.', 'talenttrack' ), 400 );
        }

        $data = [];
        foreach ( [ 'title', 'description', 'status', 'priority' ] as $k ) {
            if ( isset( $r[ $k ] ) ) {
                $data[ $k ] = $k === 'description'
                    ? sanitize_textarea_field( (string) $r[ $k ] )
                    : sanitize_text_field( (string) $r[ $k ] );
            }
        }
        if ( isset( $r['due_date'] ) ) {
            $data['due_date'] = ! empty( $r['due_date'] ) ? sanitize_text_field( (string) $r['due_date'] ) : null;
        }
        if ( ! $data ) {
            return RestResponse::error( 'empty_update', __( 'No fields to update.', 'talenttrack' ), 400 );
        }

        $ok = $wpdb->update( $wpdb->prefix . 'tt_goals', $data, [ 'id' => $goal_id, 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'goal.update.failed', [ 'db_error' => $err, 'goal_id' => $goal_id ] );
            return RestResponse::error(
                'db_error',
                __( 'The goal could not be updated.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }
        // #0025 — re-detect source language for any updated free-text
        // fields. detectAndCache is idempotent on unchanged content.
        if ( isset( $data['title'] ) ) {
            \TT\Modules\Translations\TranslationLayer::detectAndCache( 'goal', $goal_id, 'title', (string) $data['title'] );
        }
        if ( isset( $data['description'] ) ) {
            \TT\Modules\Translations\TranslationLayer::detectAndCache( 'goal', $goal_id, 'description', (string) $data['description'] );
        }

        // #0044 — replace the link set when the client sends one. Absent
        // `links` means "leave existing links alone."
        if ( isset( $r['links'] ) && is_array( $r['links'] ) ) {
            ( new GoalLinksRepository() )->sync( $goal_id, self::extractLinks( $r['links'] ) );
        }

        return RestResponse::success( [ 'id' => $goal_id ] );
    }

    public static function update_status( \WP_REST_Request $r ) {
        global $wpdb;
        $goal_id = absint( $r['id'] );
        if ( $goal_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid goal id.', 'talenttrack' ), 400 );
        }
        $status = sanitize_text_field( (string) ( $r['status'] ?? '' ) );
        if ( $status === '' ) {
            return RestResponse::error( 'missing_fields', __( 'Status is required.', 'talenttrack' ), 400 );
        }
        $ok = $wpdb->update( $wpdb->prefix . 'tt_goals', [ 'status' => $status ], [ 'id' => $goal_id, 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'goal.status.update.failed', [ 'db_error' => $err, 'goal_id' => $goal_id ] );
            return RestResponse::error(
                'db_error',
                __( 'Status update failed.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }
        // #0028 — fire so the threads system-message subscriber can
        // record the status change to the goal's conversation.
        do_action( 'tt_goal_status_changed', $goal_id, $status, (int) get_current_user_id() );
        return RestResponse::success( [ 'id' => $goal_id, 'status' => $status ] );
    }

    public static function delete_goal( \WP_REST_Request $r ) {
        global $wpdb;
        $goal_id = absint( $r['id'] );
        if ( $goal_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid goal id.', 'talenttrack' ), 400 );
        }
        $ok = $wpdb->delete( $wpdb->prefix . 'tt_goals', [ 'id' => $goal_id, 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'goal.delete.failed', [ 'db_error' => $err, 'goal_id' => $goal_id ] );
            return RestResponse::error(
                'db_error',
                __( 'Goal delete failed.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }
        return RestResponse::success( [ 'deleted' => true, 'id' => $goal_id ] );
    }
}
