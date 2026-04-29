<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PeopleRestController — /wp-json/talenttrack/v1/people
 *
 * #0019 Sprint 4. Thin wrapper around `PeopleRepository`. The repo
 * already does the heavy lifting (sanitization, role_type validation,
 * status normalization); this controller adds the Sprint 2 list
 * contract (search/filter/sort/paginate, RestResponse envelope) and
 * the per-row "current assignments" concatenation called for in
 * Sprint 4 Q5.
 *
 * Routes:
 *   GET    /people                              — paginated list
 *   POST   /people                              — create
 *   GET    /people/{id}                         — single
 *   PUT    /people/{id}                         — update
 *   DELETE /people/{id}                         — soft-archive (status=inactive + archived_at)
 */
class PeopleRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/people', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_people' ],
                'permission_callback' => function () { return current_user_can( 'tt_view_people' ) || current_user_can( 'tt_edit_people' ); },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_person' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_people' ); },
            ],
        ] );
        register_rest_route( self::NS, '/people/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_person' ],
                'permission_callback' => function () { return current_user_can( 'tt_view_people' ) || current_user_can( 'tt_edit_people' ); },
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_person' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_people' ); },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'archive_person' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_people' ); },
            ],
        ] );
    }

    private const LIST_ORDERBY_WHITELIST = [
        'last_name'  => 'p.last_name',
        'first_name' => 'p.first_name',
        'email'      => 'p.email',
        'role_type'  => 'p.role_type',
    ];

    public static function list_people( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $page     = max( 1, absint( $r['page'] ?? 1 ) );
        $per_page = self::clamp_per_page( $r['per_page'] ?? 25 );

        $orderby_key = sanitize_key( (string) ( $r['orderby'] ?? 'last_name' ) );
        if ( ! isset( self::LIST_ORDERBY_WHITELIST[ $orderby_key ] ) ) {
            return RestResponse::error(
                'bad_orderby',
                __( 'Unknown orderby column.', 'talenttrack' ),
                400,
                [ 'allowed' => array_keys( self::LIST_ORDERBY_WHITELIST ) ]
            );
        }
        $orderby = self::LIST_ORDERBY_WHITELIST[ $orderby_key ];
        $order   = strtolower( (string) ( $r['order'] ?? 'asc' ) );
        if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) $order = 'asc';

        $where  = [ '1=1', 'p.club_id = %d' ];
        $params = [ CurrentClub::id() ];
        $scope  = QueryHelpers::apply_demo_scope( 'p', 'person' );

        $filter = is_array( $r['filter'] ?? null ) ? $r['filter'] : [];

        $archived = isset( $filter['archived'] ) ? sanitize_key( (string) $filter['archived'] ) : 'active';
        if ( $archived === 'archived' ) {
            $where[] = 'p.archived_at IS NOT NULL';
        } else {
            $where[] = 'p.archived_at IS NULL';
        }

        if ( ! empty( $filter['role_type'] ) ) {
            $where[]  = 'p.role_type = %s';
            $params[] = sanitize_text_field( (string) $filter['role_type'] );
        }
        if ( ! empty( $filter['team_id'] ) ) {
            $where[]  = "EXISTS (SELECT 1 FROM {$p}tt_team_people tp WHERE tp.person_id = p.id AND tp.team_id = %d AND tp.club_id = p.club_id)";
            $params[] = absint( $filter['team_id'] );
        } elseif ( isset( $filter['has_team'] ) ) {
            $has = sanitize_key( (string) $filter['has_team'] );
            if ( $has === 'yes' ) {
                $where[] = "EXISTS (SELECT 1 FROM {$p}tt_team_people tp WHERE tp.person_id = p.id AND tp.club_id = p.club_id)";
            } elseif ( $has === 'no' ) {
                $where[] = "NOT EXISTS (SELECT 1 FROM {$p}tt_team_people tp WHERE tp.person_id = p.id AND tp.club_id = p.club_id)";
            }
        }

        if ( ! empty( $r['search'] ) ) {
            $like = '%' . $wpdb->esc_like( (string) $r['search'] ) . '%';
            $where[]  = '(p.first_name LIKE %s OR p.last_name LIKE %s OR p.email LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where ) . ' ' . $scope;

        $list_sql = "SELECT p.*,
                            (SELECT COUNT(*) FROM {$p}tt_team_people tp WHERE tp.person_id = p.id AND tp.club_id = p.club_id) AS team_count
                     FROM {$p}tt_people p
                     WHERE {$where_sql}
                     ORDER BY {$orderby} {$order}
                     LIMIT %d OFFSET %d";
        $offset = ( $page - 1 ) * $per_page;
        $list_params = array_merge( $params, [ $per_page, $offset ] );

        $rows = $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) ) ?: [];

        $count_sql = "SELECT COUNT(*) FROM {$p}tt_people p WHERE {$where_sql}";
        $total = $params
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
            : (int) $wpdb->get_var( $count_sql );

        // Per Q5: concatenate each person's current assignments into one
        // string so the list column is honest about multi-team / multi-role
        // people. One extra query per page (cap 100) — fine.
        $repo = new PeopleRepository();
        $formatted = [];
        foreach ( $rows as $row ) {
            $formatted[] = self::fmtRow( $row, $repo );
        }

        return RestResponse::success( [
            'rows'     => $formatted,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    public static function get_person( \WP_REST_Request $r ) {
        $repo = new PeopleRepository();
        $person = $repo->find( absint( $r['id'] ) );
        if ( ! $person ) return RestResponse::error( 'not_found', __( 'Person not found.', 'talenttrack' ), 404 );
        return RestResponse::success( self::fmtRow( $person, $repo ) );
    }

    public static function create_person( \WP_REST_Request $r ) {
        $repo = new PeopleRepository();
        $payload = self::extract( $r );
        if ( $payload['first_name'] === '' || $payload['last_name'] === '' ) {
            return RestResponse::error( 'missing_fields', __( 'First name and last name are required.', 'talenttrack' ), 400 );
        }
        $id = $repo->create( $payload );
        if ( $id === false ) {
            global $wpdb;
            Logger::error( 'rest.person.create.failed', [ 'db_error' => (string) $wpdb->last_error, 'payload' => $payload ] );
            return RestResponse::error( 'db_error', __( 'The person could not be created.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => (int) $id ] );
    }

    public static function update_person( \WP_REST_Request $r ) {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid person id.', 'talenttrack' ), 400 );
        $repo = new PeopleRepository();
        $payload = self::extract( $r );
        if ( ! $repo->update( $id, $payload ) ) {
            global $wpdb;
            Logger::error( 'rest.person.update.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::error( 'db_error', __( 'The person could not be updated.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    /**
     * DELETE /people/{id} — soft-archive.
     */
    public static function archive_person( \WP_REST_Request $r ) {
        global $wpdb;
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid person id.', 'talenttrack' ), 400 );
        $ok = $wpdb->update(
            $wpdb->prefix . 'tt_people',
            [ 'archived_at' => current_time( 'mysql' ), 'archived_by' => get_current_user_id(), 'status' => 'inactive' ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        if ( $ok === false ) {
            Logger::error( 'rest.person.archive.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::error( 'db_error', __( 'The person could not be archived.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'archived' => true, 'id' => $id ] );
    }

    /**
     * @return array<string, mixed>
     */
    private static function extract( \WP_REST_Request $r ): array {
        $payload = [];
        foreach ( [ 'first_name', 'last_name', 'email', 'phone', 'role_type', 'wp_user_id', 'status' ] as $key ) {
            if ( $r->get_param( $key ) !== null ) {
                $payload[ $key ] = $r->get_param( $key );
            }
        }
        // Defaults for create.
        $payload['first_name'] = sanitize_text_field( (string) ( $payload['first_name'] ?? '' ) );
        $payload['last_name']  = sanitize_text_field( (string) ( $payload['last_name'] ?? '' ) );
        return $payload;
    }

    private static function clamp_per_page( $value ): int {
        $n = absint( $value );
        if ( ! in_array( $n, [ 10, 25, 50, 100 ], true ) ) return 25;
        return $n;
    }

    /** Compose one row of list output, including the "current roles" string per Q5. */
    private static function fmtRow( object $row, PeopleRepository $repo ): array {
        $assignments = $repo->getPersonTeams( (int) $row->id );
        $parts = [];
        foreach ( $assignments as $a ) {
            $label = self::humanRoleLabel( (string) ( $a->functional_role_key ?? $a->role_in_team ?? '' ) );
            $parts[] = trim( $label . ' @ ' . (string) ( $a->team_name ?? '' ), ' @' );
        }

        return [
            'id'              => (int) $row->id,
            'first_name'      => (string) $row->first_name,
            'last_name'       => (string) $row->last_name,
            'name'            => trim( ( (string) $row->first_name ) . ' ' . ( (string) $row->last_name ) ),
            'email'           => (string) ( $row->email ?? '' ),
            'phone'           => (string) ( $row->phone ?? '' ),
            'role_type'       => (string) ( $row->role_type ?? 'other' ),
            'wp_user_id'      => $row->wp_user_id !== null ? (int) $row->wp_user_id : null,
            'status'          => (string) ( $row->status ?? 'active' ),
            'archived_at'     => $row->archived_at ?? null,
            'team_count'      => isset( $row->team_count ) ? (int) $row->team_count : count( $assignments ),
            'current_roles'   => implode( ' · ', array_filter( $parts ) ),
        ];
    }

    private static function humanRoleLabel( string $key ): string {
        if ( $key === '' ) return '';
        // Functional role labels live in tt_functional_roles. Fall back to
        // a humanized key for legacy / orphan rows.
        global $wpdb;
        $label = $wpdb->get_var( $wpdb->prepare(
            "SELECT label FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            $key, CurrentClub::id()
        ) );
        if ( $label ) return (string) $label;
        return ucwords( str_replace( '_', ' ', $key ) );
    }
}
