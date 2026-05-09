<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Authorization\FunctionalRolesRepository;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\I18n\TranslatableFieldRegistry;
use TT\Modules\I18n\TranslationsRepository;

/**
 * FunctionalRolesRestController — /wp-json/talenttrack/v1/functional-roles
 *
 * #0019 Sprint 4. Two distinct concerns under one controller:
 *
 *   - **Role types** — definitions of "what is this person's job" (head
 *     coach, assistant coach, physio, manager, other). CRUD + reorder.
 *   - **Assignments** — which person holds which role on which team.
 *     CRUD; the list endpoint matches the Sprint 2 contract so
 *     `FrontendListTable` can consume it.
 *
 * Routes:
 *   GET    /functional-roles                                — list role types (no pagination; few rows)
 *   POST   /functional-roles                                — create role type
 *   PUT    /functional-roles/{id}                           — update role type
 *   DELETE /functional-roles/{id}                           — delete role type (rejected if any assignments reference it)
 *   POST   /functional-roles/{id}/move                      — reorder ({ direction: 'up'|'down' })
 *
 *   GET    /functional-roles/assignments                    — paginated list of team-staff assignments
 *   POST   /functional-roles/assignments                    — create assignment
 *   DELETE /functional-roles/assignments/{assignment_id}    — delete assignment
 *
 * Capability gates:
 *   - role types: `tt_manage_functional_roles` (already exists)
 *   - assignments: read = `tt_view_people` OR `tt_edit_people`;
 *                   write = `tt_edit_people`
 */
class FunctionalRolesRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        // Role types
        register_rest_route( self::NS, '/functional-roles', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_role_types' ],
                'permission_callback' => function () { return current_user_can( 'tt_view_people' ) || current_user_can( 'tt_manage_functional_roles' ); },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_role_type' ],
                'permission_callback' => function () { return current_user_can( 'tt_manage_functional_roles' ); },
            ],
        ] );
        register_rest_route( self::NS, '/functional-roles/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_role_type' ],
                'permission_callback' => function () { return current_user_can( 'tt_manage_functional_roles' ); },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_role_type' ],
                'permission_callback' => function () { return current_user_can( 'tt_manage_functional_roles' ); },
            ],
        ] );
        register_rest_route( self::NS, '/functional-roles/(?P<id>\d+)/move', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'move_role_type' ],
                'permission_callback' => function () { return current_user_can( 'tt_manage_functional_roles' ); },
            ],
        ] );

        // Assignments
        register_rest_route( self::NS, '/functional-roles/assignments', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_assignments' ],
                'permission_callback' => function () { return current_user_can( 'tt_view_people' ) || current_user_can( 'tt_edit_people' ); },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_assignment' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_people' ); },
            ],
        ] );
        register_rest_route( self::NS, '/functional-roles/assignments/(?P<assignment_id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_assignment' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_people' ); },
            ],
        ] );
    }

    // Role types

    public static function list_role_types( \WP_REST_Request $r ) {
        $repo = new FunctionalRolesRepository();
        return RestResponse::success( [
            'rows' => array_map( [ __CLASS__, 'fmtType' ], $repo->listRoles() ),
        ] );
    }

    public static function create_role_type( \WP_REST_Request $r ) {
        global $wpdb;
        $key   = sanitize_key( (string) ( $r['role_key'] ?? '' ) );
        $label = sanitize_text_field( (string) ( $r['label'] ?? '' ) );
        $desc  = sanitize_textarea_field( (string) ( $r['description'] ?? '' ) );

        if ( $key === '' || $label === '' ) {
            return RestResponse::error( 'missing_fields', __( 'A role key and label are required.', 'talenttrack' ), 400 );
        }
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s AND club_id = %d", $key, CurrentClub::id() ) ) ) {
            return RestResponse::error( 'duplicate_key', __( 'A role with this key already exists.', 'talenttrack' ), 409 );
        }

        $next_sort = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$wpdb->prefix}tt_functional_roles WHERE club_id = %d", CurrentClub::id() ) );
        $ok = $wpdb->insert( $wpdb->prefix . 'tt_functional_roles', [
            'club_id'     => CurrentClub::id(),
            'role_key'    => $key,
            'label'       => $label,
            'description' => $desc,
            'is_system'   => 0,
            'sort_order'  => $next_sort,
        ] );
        if ( $ok === false ) {
            Logger::error( 'rest.functional_role.create.failed', [ 'db_error' => (string) $wpdb->last_error ] );
            return RestResponse::error( 'db_error', __( 'The role type could not be created.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => (int) $wpdb->insert_id ] );
    }

    public static function update_role_type( \WP_REST_Request $r ) {
        global $wpdb;
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid role id.', 'talenttrack' ), 400 );
        $existing = ( new FunctionalRolesRepository() )->findRole( $id );
        if ( ! $existing ) return RestResponse::error( 'not_found', __( 'Role not found.', 'talenttrack' ), 404 );

        $data = [];
        if ( $r->get_param( 'label' ) !== null ) {
            $data['label'] = sanitize_text_field( (string) $r['label'] );
            if ( $data['label'] === '' ) {
                return RestResponse::error( 'missing_fields', __( 'A label is required.', 'talenttrack' ), 400 );
            }
        }
        if ( $r->get_param( 'description' ) !== null ) {
            $data['description'] = sanitize_textarea_field( (string) $r['description'] );
        }
        // role_key is intentionally not editable — it's referenced by
        // tt_team_people.role_in_team and the auth-role mapping table.
        // is_system flag is not exposed on the frontend either; stays
        // admin-only to protect built-in roles.

        if ( ! $data ) return RestResponse::error( 'empty_update', __( 'No fields to update.', 'talenttrack' ), 400 );

        $ok = $wpdb->update( $wpdb->prefix . 'tt_functional_roles', $data, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) {
            Logger::error( 'rest.functional_role.update.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::error( 'db_error', __( 'The role type could not be updated.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function delete_role_type( \WP_REST_Request $r ) {
        global $wpdb;
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid role id.', 'talenttrack' ), 400 );
        $existing = ( new FunctionalRolesRepository() )->findRole( $id );
        if ( ! $existing ) return RestResponse::error( 'not_found', __( 'Role not found.', 'talenttrack' ), 404 );
        if ( ! empty( $existing->is_system ) ) {
            return RestResponse::error( 'protected_system', __( 'System role types cannot be deleted.', 'talenttrack' ), 403 );
        }
        $assignments = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_team_people WHERE functional_role_id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        if ( $assignments > 0 ) {
            return RestResponse::error(
                'in_use',
                __( 'This role type is in use by current assignments. Reassign or remove those first.', 'talenttrack' ),
                409,
                [ 'assignments' => $assignments ]
            );
        }
        $ok = $wpdb->delete( $wpdb->prefix . 'tt_functional_roles', [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) {
            Logger::error( 'rest.functional_role.delete.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::error( 'db_error', __( 'The role type could not be deleted.', 'talenttrack' ), 500 );
        }

        // #0090 Phase 4 — cascade-delete `tt_translations` rows so the
        // new store does not retain orphans pointing at a vanished id.
        ( new TranslationsRepository() )->deleteAllFor( TranslatableFieldRegistry::ENTITY_FUNCTIONAL_ROLE, $id );

        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    /**
     * POST /functional-roles/{id}/move — reorder by swapping sort_order
     * with the adjacent row (Q2: arrow buttons rather than DragReorder).
     */
    public static function move_role_type( \WP_REST_Request $r ) {
        global $wpdb;
        $id = absint( $r['id'] );
        $direction = sanitize_key( (string) ( $r['direction'] ?? '' ) );
        if ( $id <= 0 || ! in_array( $direction, [ 'up', 'down' ], true ) ) {
            return RestResponse::error( 'bad_request', __( 'Invalid move parameters.', 'talenttrack' ), 400 );
        }

        $existing = ( new FunctionalRolesRepository() )->findRole( $id );
        if ( ! $existing ) return RestResponse::error( 'not_found', __( 'Role not found.', 'talenttrack' ), 404 );
        $current_sort = (int) $existing->sort_order;

        $compare = $direction === 'up' ? '<' : '>';
        $order   = $direction === 'up' ? 'DESC' : 'ASC';
        $neighbor = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, sort_order FROM {$wpdb->prefix}tt_functional_roles
             WHERE sort_order {$compare} %d AND club_id = %d
             ORDER BY sort_order {$order}, id {$order}
             LIMIT 1",
            $current_sort, CurrentClub::id()
        ) );
        if ( ! $neighbor ) {
            // Already at edge; idempotent no-op.
            return RestResponse::success( [ 'id' => $id, 'no_op' => true ] );
        }

        // Swap sort_order values.
        $wpdb->update( $wpdb->prefix . 'tt_functional_roles', [ 'sort_order' => (int) $neighbor->sort_order ], [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        $wpdb->update( $wpdb->prefix . 'tt_functional_roles', [ 'sort_order' => $current_sort ], [ 'id' => (int) $neighbor->id, 'club_id' => CurrentClub::id() ] );

        return RestResponse::success( [ 'id' => $id, 'swapped_with' => (int) $neighbor->id ] );
    }

    // Assignments

    private const ASSIGNMENT_ORDERBY_WHITELIST = [
        'team_name'   => 't.name',
        'person_name' => 'p.last_name',
        'role'        => 'fr.label',
        'start_date'  => 'tp.start_date',
    ];

    public static function list_assignments( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $page     = max( 1, absint( $r['page'] ?? 1 ) );
        $per_page = self::clamp_per_page( $r['per_page'] ?? 25 );

        $orderby_key = sanitize_key( (string) ( $r['orderby'] ?? 'team_name' ) );
        if ( ! isset( self::ASSIGNMENT_ORDERBY_WHITELIST[ $orderby_key ] ) ) {
            return RestResponse::error(
                'bad_orderby',
                __( 'Unknown orderby column.', 'talenttrack' ),
                400,
                [ 'allowed' => array_keys( self::ASSIGNMENT_ORDERBY_WHITELIST ) ]
            );
        }
        $orderby = self::ASSIGNMENT_ORDERBY_WHITELIST[ $orderby_key ];
        $order   = strtolower( (string) ( $r['order'] ?? 'asc' ) );
        if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) $order = 'asc';

        $where  = [ 't.archived_at IS NULL', 'p.archived_at IS NULL', 'tp.club_id = %d' ];
        $params = [ CurrentClub::id() ];
        $scope  = QueryHelpers::apply_demo_scope( 't', 'team' );

        $filter = is_array( $r['filter'] ?? null ) ? $r['filter'] : [];
        if ( ! empty( $filter['team_id'] ) ) {
            $where[]  = 'tp.team_id = %d';
            $params[] = absint( $filter['team_id'] );
        }
        if ( ! empty( $filter['functional_role_id'] ) ) {
            $where[]  = 'tp.functional_role_id = %d';
            $params[] = absint( $filter['functional_role_id'] );
        }

        if ( ! empty( $r['search'] ) ) {
            $like = '%' . $wpdb->esc_like( (string) $r['search'] ) . '%';
            $where[]  = '(t.name LIKE %s OR p.first_name LIKE %s OR p.last_name LIKE %s OR fr.label LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where ) . ' ' . $scope;

        $list_sql = "SELECT tp.id AS assignment_id,
                            tp.start_date, tp.end_date, tp.role_in_team,
                            tp.team_id, tp.person_id, tp.functional_role_id,
                            t.name AS team_name, t.age_group,
                            p.first_name, p.last_name, p.email,
                            fr.label AS role_label, fr.role_key
                     FROM {$p}tt_team_people tp
                     INNER JOIN {$p}tt_teams t ON t.id = tp.team_id AND t.club_id = tp.club_id
                     INNER JOIN {$p}tt_people p ON p.id = tp.person_id AND p.club_id = tp.club_id
                     LEFT  JOIN {$p}tt_functional_roles fr ON fr.id = tp.functional_role_id AND fr.club_id = tp.club_id
                     WHERE {$where_sql}
                     ORDER BY {$orderby} {$order}
                     LIMIT %d OFFSET %d";
        $offset = ( $page - 1 ) * $per_page;
        $list_params = array_merge( $params, [ $per_page, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) ) ?: [];

        $count_sql = "SELECT COUNT(*) FROM {$p}tt_team_people tp
                      INNER JOIN {$p}tt_teams t ON t.id = tp.team_id AND t.club_id = tp.club_id
                      INNER JOIN {$p}tt_people p ON p.id = tp.person_id AND p.club_id = tp.club_id
                      LEFT  JOIN {$p}tt_functional_roles fr ON fr.id = tp.functional_role_id AND fr.club_id = tp.club_id
                      WHERE {$where_sql}";
        $total = $params
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
            : (int) $wpdb->get_var( $count_sql );

        return RestResponse::success( [
            'rows'     => array_map( [ __CLASS__, 'fmtAssignment' ], $rows ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    public static function create_assignment( \WP_REST_Request $r ) {
        $team_id   = absint( $r['team_id'] ?? 0 );
        $person_id = absint( $r['person_id'] ?? 0 );
        $role_id   = absint( $r['functional_role_id'] ?? 0 );
        $start     = sanitize_text_field( (string) ( $r['start_date'] ?? '' ) ) ?: null;
        $end       = sanitize_text_field( (string) ( $r['end_date'] ?? '' ) ) ?: null;

        if ( $team_id <= 0 || $person_id <= 0 || $role_id <= 0 ) {
            return RestResponse::error( 'missing_fields', __( 'Team, person, and functional role are all required.', 'talenttrack' ), 400 );
        }

        $repo = new PeopleRepository();
        $ok = $repo->assignToTeam( $team_id, $person_id, $role_id, $start, $end );
        if ( ! $ok ) {
            global $wpdb;
            $err = (string) $wpdb->last_error;
            Logger::error( 'rest.functional_role.assign.failed', [
                'db_error' => $err, 'team_id' => $team_id, 'person_id' => $person_id, 'role_id' => $role_id,
            ] );
            // Most likely cause: unique-key violation on (team, person, role).
            return RestResponse::error(
                'assign_failed',
                __( 'Could not create the assignment. The person may already hold this role on this team.', 'talenttrack' ),
                409,
                [ 'db_error' => $err ]
            );
        }
        return RestResponse::success( [ 'created' => true ] );
    }

    public static function delete_assignment( \WP_REST_Request $r ) {
        $assignment_id = absint( $r['assignment_id'] );
        if ( $assignment_id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid assignment id.', 'talenttrack' ), 400 );
        $repo = new PeopleRepository();
        if ( ! $repo->unassign( $assignment_id ) ) {
            global $wpdb;
            Logger::error( 'rest.functional_role.unassign.failed', [ 'db_error' => (string) $wpdb->last_error, 'assignment_id' => $assignment_id ] );
            return RestResponse::error( 'db_error', __( 'The assignment could not be removed.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'deleted' => true, 'assignment_id' => $assignment_id ] );
    }

    // Helpers

    private static function clamp_per_page( $value ): int {
        $n = absint( $value );
        if ( ! in_array( $n, [ 10, 25, 50, 100 ], true ) ) return 25;
        return $n;
    }

    private static function fmtType( object $r ): array {
        return [
            'id'               => (int) $r->id,
            'role_key'         => (string) $r->role_key,
            'label'            => (string) $r->label,
            'description'      => (string) ( $r->description ?? '' ),
            'is_system'        => ! empty( $r->is_system ),
            'sort_order'       => (int) ( $r->sort_order ?? 0 ),
            'mapping_count'    => isset( $r->mapping_count ) ? (int) $r->mapping_count : null,
            'assignment_count' => isset( $r->assignment_count ) ? (int) $r->assignment_count : null,
        ];
    }

    private static function fmtAssignment( object $r ): array {
        $first = (string) ( $r->first_name ?? '' );
        $last  = (string) ( $r->last_name ?? '' );
        return [
            'id'                  => (int) $r->assignment_id,
            'team_id'             => (int) $r->team_id,
            'team_name'           => (string) ( $r->team_name ?? '' ),
            'team_age_group'      => (string) ( $r->age_group ?? '' ),
            'person_id'           => (int) $r->person_id,
            'person_name'         => trim( $first . ' ' . $last ),
            'person_email'        => (string) ( $r->email ?? '' ),
            'functional_role_id'  => $r->functional_role_id !== null ? (int) $r->functional_role_id : null,
            'functional_role_key' => (string) ( $r->role_key ?? $r->role_in_team ?? '' ),
            'role'                => (string) ( $r->role_label ?? $r->role_in_team ?? '' ),
            'start_date'          => $r->start_date,
            'end_date'            => $r->end_date,
        ];
    }
}
