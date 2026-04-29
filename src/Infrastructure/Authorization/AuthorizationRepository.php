<?php
namespace TT\Infrastructure\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * AuthorizationRepository — data access for roles, permissions, and
 * user-role scopes (the v2.9.0 data-driven RBAC tables).
 *
 * Kept deliberately thin: just queries and writes. All decision logic
 * lives in AuthorizationService. All admin UI lives in the Authorization
 * module pages.
 */
class AuthorizationRepository {

    public const SCOPE_TYPES = [ 'global', 'team', 'player', 'person' ];

    // Roles

    /** @return array<int, object> */
    public function listRoles(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}tt_role_permissions WHERE role_id = r.id AND club_id = %d) AS permission_count,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}tt_user_role_scopes WHERE role_id = r.id AND club_id = %d) AS assignment_count
             FROM {$wpdb->prefix}tt_roles r
             WHERE r.club_id = %d
             ORDER BY r.is_system DESC, r.label ASC",
            CurrentClub::id(), CurrentClub::id(), CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    public function findRole( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_roles WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    public function findRoleByKey( string $key ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_roles WHERE role_key = %s AND club_id = %d",
            $key, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    // Permissions

    /** @return string[] */
    public function getPermissionsForRole( int $role_id ): array {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT permission FROM {$wpdb->prefix}tt_role_permissions WHERE role_id = %d AND club_id = %d ORDER BY permission ASC",
            $role_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? array_map( 'strval', $rows ) : [];
    }

    /**
     * Get the distinct set of permission strings a person has, taking all
     * their role assignments into account (ignoring scope — callers handle
     * scope filtering).
     *
     * @return string[]
     */
    public function getPermissionsForPerson( int $person_id ): array {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT rp.permission
             FROM {$wpdb->prefix}tt_user_role_scopes urs
             INNER JOIN {$wpdb->prefix}tt_role_permissions rp ON rp.role_id = urs.role_id AND rp.club_id = urs.club_id
             WHERE urs.person_id = %d AND urs.club_id = %d",
            $person_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? array_map( 'strval', $rows ) : [];
    }

    // User role scopes (assignments)

    /**
     * Full list of a person's role assignments, joined with role metadata.
     *
     * @return array<int, object>
     */
    public function getPersonAssignments( int $person_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT urs.*, r.role_key, r.label AS role_label, r.description AS role_description
             FROM {$wpdb->prefix}tt_user_role_scopes urs
             INNER JOIN {$wpdb->prefix}tt_roles r ON r.id = urs.role_id AND r.club_id = urs.club_id
             WHERE urs.person_id = %d AND urs.club_id = %d
             ORDER BY r.label ASC, urs.scope_type ASC",
            $person_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @return array<int, object>
     */
    public function getAssignmentsForRole( int $role_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT urs.*, p.first_name, p.last_name, p.email
             FROM {$wpdb->prefix}tt_user_role_scopes urs
             INNER JOIN {$wpdb->prefix}tt_people p ON p.id = urs.person_id AND p.club_id = urs.club_id
             WHERE urs.role_id = %d AND urs.club_id = %d
             ORDER BY p.last_name ASC, p.first_name ASC",
            $role_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Return ALL scopes a person has, grouped by scope_type+scope_id, each
     * carrying the set of permissions granted. Used by AuthorizationService
     * for per-request resolution.
     *
     * Return shape:
     *   [
     *     [
     *       'role_id' => 3, 'role_key' => 'head_coach',
     *       'scope_type' => 'team', 'scope_id' => 12,
     *       'permissions' => ['players.view', 'players.edit', ...],
     *       'start_date' => null, 'end_date' => null,
     *     ],
     *     ...
     *   ]
     *
     * Only returns currently-active scopes (start/end dates honored if set).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveScopesForPerson( int $person_id ): array {
        global $wpdb;
        $today = current_time( 'Y-m-d' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT urs.id AS scope_id_pk, urs.role_id, urs.scope_type, urs.scope_id,
                    urs.start_date, urs.end_date,
                    r.role_key
             FROM {$wpdb->prefix}tt_user_role_scopes urs
             INNER JOIN {$wpdb->prefix}tt_roles r ON r.id = urs.role_id AND r.club_id = urs.club_id
             WHERE urs.person_id = %d
               AND urs.club_id = %d
               AND (urs.start_date IS NULL OR urs.start_date <= %s)
               AND (urs.end_date   IS NULL OR urs.end_date   >= %s)",
            $person_id, CurrentClub::id(), $today, $today
        ) );

        if ( ! is_array( $rows ) || empty( $rows ) ) return [];

        // Collect role IDs in one shot for a batch permission lookup.
        $role_ids = array_unique( array_map( function ( $r ) { return (int) $r->role_id; }, $rows ) );
        $placeholders = implode( ',', array_fill( 0, count( $role_ids ), '%d' ) );
        $perm_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT role_id, permission FROM {$wpdb->prefix}tt_role_permissions WHERE role_id IN ($placeholders) AND club_id = %d",
            array_merge( $role_ids, [ CurrentClub::id() ] )
        ) );

        $perms_by_role = [];
        if ( is_array( $perm_rows ) ) {
            foreach ( $perm_rows as $pr ) {
                $perms_by_role[ (int) $pr->role_id ][] = (string) $pr->permission;
            }
        }

        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'scope_id_pk' => (int) $r->scope_id_pk,
                'role_id'     => (int) $r->role_id,
                'role_key'    => (string) $r->role_key,
                'scope_type'  => (string) $r->scope_type,
                'scope_id'    => $r->scope_id !== null ? (int) $r->scope_id : null,
                'start_date'  => $r->start_date,
                'end_date'    => $r->end_date,
                'permissions' => $perms_by_role[ (int) $r->role_id ] ?? [],
            ];
        }

        return $out;
    }

    public function grant( int $person_id, int $role_id, string $scope_type, ?int $scope_id = null, ?string $start_date = null, ?string $end_date = null, ?int $granted_by_person_id = null ): int {
        global $wpdb;

        if ( ! in_array( $scope_type, self::SCOPE_TYPES, true ) ) return 0;
        if ( $person_id <= 0 || $role_id <= 0 ) return 0;
        if ( $scope_type === 'global' ) $scope_id = null;
        elseif ( $scope_id === null || $scope_id <= 0 ) return 0;

        $data = [
            'club_id'              => CurrentClub::id(),
            'person_id'            => $person_id,
            'role_id'              => $role_id,
            'scope_type'           => $scope_type,
            'scope_id'             => $scope_id,
            'start_date'           => $start_date ?: null,
            'end_date'             => $end_date ?: null,
            'granted_by_person_id' => $granted_by_person_id ?: null,
        ];

        $ok = $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", $data );
        if ( $ok === false ) return 0;

        $new_id = (int) $wpdb->insert_id;

        /**
         * Fires after a role is granted to a person.
         *
         * @param int    $scope_id_pk  The tt_user_role_scopes row ID.
         * @param int    $person_id
         * @param int    $role_id
         * @param string $scope_type
         * @param int|null $scope_id
         */
        do_action( 'tt_role_granted', $new_id, $person_id, $role_id, $scope_type, $scope_id );

        return $new_id;
    }

    public function revoke( int $scope_id_pk ): bool {
        global $wpdb;
        if ( $scope_id_pk <= 0 ) return false;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT person_id, role_id, scope_type, scope_id FROM {$wpdb->prefix}tt_user_role_scopes WHERE id = %d AND club_id = %d",
            $scope_id_pk, CurrentClub::id()
        ) );
        if ( ! $row ) return false;

        $ok = $wpdb->delete( "{$wpdb->prefix}tt_user_role_scopes", [ 'id' => $scope_id_pk, 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) return false;

        /**
         * Fires after a role grant is revoked.
         */
        do_action(
            'tt_role_revoked',
            $scope_id_pk,
            (int) $row->person_id,
            (int) $row->role_id,
            (string) $row->scope_type,
            $row->scope_id !== null ? (int) $row->scope_id : null
        );

        return true;
    }
}
