<?php
namespace TT\Infrastructure\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * FunctionalRolesRepository — data access for tt_functional_roles and
 * tt_functional_role_auth_roles (Sprint 1G, v2.10.0).
 *
 * Functional roles describe "what is this person's job on a team"
 * (head_coach, physio, manager, etc.). Authorization roles describe
 * "what are they allowed to do". The mapping table lets one functional
 * role grant multiple authorization roles — e.g. a head_coach who should
 * also get physio-level access to the medical-ish domain.
 *
 * Thin data-access class. All decision logic stays in
 * AuthorizationService; all UI stays in the Authorization admin pages.
 */
class FunctionalRolesRepository {

    // Functional roles

    /**
     * @return array<int, object>  Ordered by sort_order, then label.
     */
    public function listRoles(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT fr.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}tt_functional_role_auth_roles
                     WHERE functional_role_id = fr.id AND club_id = %d) AS mapping_count,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}tt_team_people
                     WHERE functional_role_id = fr.id AND club_id = %d) AS assignment_count
             FROM {$wpdb->prefix}tt_functional_roles fr
             WHERE fr.club_id = %d
             ORDER BY fr.sort_order ASC, fr.label ASC",
            CurrentClub::id(), CurrentClub::id(), CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    public function findRole( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_functional_roles WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    public function findRoleByKey( string $key ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s AND club_id = %d",
            $key, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    // Auth role mapping

    /**
     * Return the set of auth_role_ids a given functional role maps to.
     *
     * @return int[]
     */
    public function getAuthRoleIdsForFunctionalRole( int $functional_role_id ): array {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT auth_role_id FROM {$wpdb->prefix}tt_functional_role_auth_roles
             WHERE functional_role_id = %d AND club_id = %d",
            $functional_role_id, CurrentClub::id()
        ) );
        if ( ! is_array( $rows ) ) return [];
        return array_map( 'intval', $rows );
    }

    /**
     * Return the auth roles (joined with role metadata) that a functional
     * role maps to.
     *
     * @return array<int, object>
     */
    public function getAuthRolesForFunctionalRole( int $functional_role_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, fram.id AS mapping_id
             FROM {$wpdb->prefix}tt_functional_role_auth_roles fram
             INNER JOIN {$wpdb->prefix}tt_roles r ON r.id = fram.auth_role_id AND r.club_id = fram.club_id
             WHERE fram.functional_role_id = %d AND fram.club_id = %d
             ORDER BY r.label ASC",
            $functional_role_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Replace the set of auth roles a functional role maps to. Takes a list
     * of auth_role_ids and syncs the join table to match exactly that set
     * (adds missing, removes extras). Returns the count of final mappings.
     *
     * @param int[] $auth_role_ids
     */
    public function setAuthRoleMapping( int $functional_role_id, array $auth_role_ids ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $functional_role_id <= 0 ) return 0;

        // Normalize: positive ints, deduped.
        $desired = array_values( array_unique( array_map( 'intval', $auth_role_ids ) ) );
        $desired = array_values( array_filter( $desired, function ( $id ) { return $id > 0; } ) );

        // Validate: each must exist in tt_roles.
        if ( ! empty( $desired ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $desired ), '%d' ) );
            $valid = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$p}tt_roles WHERE id IN ($placeholders) AND club_id = %d",
                array_merge( $desired, [ CurrentClub::id() ] )
            ) );
            $valid = is_array( $valid ) ? array_map( 'intval', $valid ) : [];
            $desired = array_values( array_intersect( $desired, $valid ) );
        }

        $current = $this->getAuthRoleIdsForFunctionalRole( $functional_role_id );

        $to_add    = array_diff( $desired, $current );
        $to_remove = array_diff( $current, $desired );

        foreach ( $to_add as $auth_role_id ) {
            $wpdb->insert( "{$p}tt_functional_role_auth_roles", [
                'club_id'            => CurrentClub::id(),
                'functional_role_id' => $functional_role_id,
                'auth_role_id'       => (int) $auth_role_id,
            ] );
        }

        if ( ! empty( $to_remove ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $to_remove ), '%d' ) );
            $params = array_merge( [ $functional_role_id, CurrentClub::id() ], array_map( 'intval', $to_remove ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$p}tt_functional_role_auth_roles
                 WHERE functional_role_id = %d AND club_id = %d AND auth_role_id IN ($placeholders)",
                $params
            ) );
        }

        /**
         * Fires after a functional role's auth-role mapping is updated.
         * Authorization caches that depend on this mapping must be flushed
         * by listeners on this hook.
         *
         * @param int   $functional_role_id
         * @param int[] $auth_role_ids The new set of mapped auth role IDs.
         */
        do_action( 'tt_functional_role_mapping_updated', $functional_role_id, $desired );

        return count( $desired );
    }

    // Assignments (reverse lookups)

    /**
     * Return every tt_team_people assignment whose functional role maps
     * (directly or indirectly) to the given auth role. Used by the Roles &
     * Permissions detail page to show indirect grants.
     *
     * Returns rows with: person_id, first_name, last_name, email, team_id,
     * team_name, functional_role_id, functional_role_key,
     * functional_role_label, start_date, end_date, assignment_id.
     *
     * @return array<int, object>
     */
    public function getIndirectAssignmentsForAuthRole( int $auth_role_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tp.id   AS assignment_id,
                    tp.start_date,
                    tp.end_date,
                    p.id    AS person_id,
                    p.first_name,
                    p.last_name,
                    p.email,
                    t.id    AS team_id,
                    t.name  AS team_name,
                    fr.id       AS functional_role_id,
                    fr.role_key AS functional_role_key,
                    fr.label    AS functional_role_label
             FROM {$wpdb->prefix}tt_team_people tp
             INNER JOIN {$wpdb->prefix}tt_people p ON p.id = tp.person_id AND p.club_id = tp.club_id
             INNER JOIN {$wpdb->prefix}tt_teams  t ON t.id = tp.team_id AND t.club_id = tp.club_id
             INNER JOIN {$wpdb->prefix}tt_functional_roles fr
                     ON fr.id = tp.functional_role_id AND fr.club_id = tp.club_id
             INNER JOIN {$wpdb->prefix}tt_functional_role_auth_roles fram
                     ON fram.functional_role_id = fr.id AND fram.club_id = fr.club_id
             WHERE fram.auth_role_id = %d AND tp.club_id = %d
             ORDER BY p.last_name ASC, p.first_name ASC, t.name ASC",
            $auth_role_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Return the list of (team, functional_role) pairs for a person. Used
     * by the authorization service to resolve a user's scopes.
     *
     * @return array<int, object>
     */
    public function getFunctionalRoleAssignmentsForPerson( int $person_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tp.id   AS assignment_id,
                    tp.team_id,
                    tp.functional_role_id,
                    tp.start_date,
                    tp.end_date,
                    fr.role_key AS functional_role_key
             FROM {$wpdb->prefix}tt_team_people tp
             INNER JOIN {$wpdb->prefix}tt_functional_roles fr
                     ON fr.id = tp.functional_role_id AND fr.club_id = tp.club_id
             WHERE tp.person_id = %d
               AND tp.club_id = %d
               AND tp.functional_role_id IS NOT NULL",
            $person_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }
}
