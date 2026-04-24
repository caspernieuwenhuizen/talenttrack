<?php
namespace TT\Infrastructure\People;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PeopleRepository — data access for the People/Staff domain.
 *
 * Handles CRUD on tt_people and assignment CRUD on tt_team_people. In
 * Sprint 1G (v2.10.0) the tt_teams.head_coach_id legacy column stopped
 * driving permissions and stopped being read here; the 0006 migration
 * translated all non-zero values into explicit tt_team_people rows.
 *
 * All methods return plain arrays (or bools) — no ORM, no shared mutable
 * state. Safe to instantiate anywhere.
 */
class PeopleRepository {

    /**
     * Allowed role_type values for tt_people.role_type.
     * Expanded in v2.7.0 to include 'parent' and 'other' so People can
     * represent anyone the system tracks, not just staff.
     *
     * @var string[]
     */
    public const ROLE_TYPES = [
        'coach',
        'assistant_coach',
        'manager',
        'staff',
        'physio',
        'scout',
        'parent',
        'other',
    ];

    /**
     * Allowed role_in_team values — always a staff role. Narrower than
     * ROLE_TYPES because team assignments are always staff-in-context.
     *
     * @var string[]
     */
    public const TEAM_ROLES = [
        'head_coach',
        'assistant_coach',
        'manager',
        'physio',
        'other',
    ];

    /* ═══════════════ People CRUD ═══════════════ */

    /**
     * @param array{only_staff?:bool, status?:string, search?:string} $filters
     * @return array<int, object>
     */
    public function list( array $filters = [] ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'p.status = %s';
            $params[] = $filters['status'];
        }

        if ( ! empty( $filters['search'] ) ) {
            $like = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
            $where[]  = '(p.first_name LIKE %s OR p.last_name LIKE %s OR p.email LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ( ! empty( $filters['only_staff'] ) ) {
            // Only people who have at least one team assignment.
            $where[] = "EXISTS (SELECT 1 FROM {$p}tt_team_people tp WHERE tp.person_id = p.id)";
        }

        $scope = \TT\Infrastructure\Query\QueryHelpers::apply_demo_scope( 'p', 'person' );
        $sql = "SELECT p.*,
                  (SELECT COUNT(*) FROM {$p}tt_team_people tp WHERE tp.person_id = p.id) AS team_count
                FROM {$p}tt_people p
                WHERE " . implode( ' AND ', $where ) . " {$scope}
                ORDER BY p.last_name ASC, p.first_name ASC";

        $query = $params ? $wpdb->prepare( $sql, $params ) : $sql;
        $rows  = $wpdb->get_results( $query );
        return is_array( $rows ) ? $rows : [];
    }

    public function find( int $id ): ?object {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}tt_people WHERE id = %d", $id ) );
        return $row ?: null;
    }

    /**
     * @param array{first_name:string, last_name:string, email?:?string, phone?:?string, role_type?:string, wp_user_id?:?int, status?:string} $data
     * @return int|false  Inserted ID, or false on failure.
     */
    public function create( array $data ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $row = self::sanitizeForStorage( $data );
        $result = $wpdb->insert( "{$p}tt_people", $row );
        if ( $result === false ) return false;

        $id = (int) $wpdb->insert_id;
        $person = $this->find( $id );

        /**
         * Fires after a person is created.
         *
         * @param object $person The newly-created person record.
         */
        do_action( 'tt_person_created', $person );

        return $id;
    }

    public function update( int $id, array $data ): bool {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = self::sanitizeForStorage( $data, true );
        if ( empty( $row ) ) return true;
        $result = $wpdb->update( "{$p}tt_people", $row, [ 'id' => $id ] );
        return $result !== false;
    }

    public function setStatus( int $id, string $status ): bool {
        if ( ! in_array( $status, [ 'active', 'inactive' ], true ) ) return false;
        return $this->update( $id, [ 'status' => $status ] );
    }

    /* ═══════════════ Team-staff assignments ═══════════════ */

    /**
     * Return staff for a team, grouped by functional role key.
     *
     * v2.10.0 (Sprint 1G): Grouping is driven by functional_role_id →
     * tt_functional_roles.role_key. Rows without a functional_role_id
     * (shouldn't happen post-migration, but defensive) fall back to
     * their legacy role_in_team string.
     *
     * The Sprint 1F legacy synthesis of tt_teams.head_coach_id is gone.
     * Migration 0006 promoted those values to explicit tt_team_people
     * rows; there is no longer a read-path for head_coach_id.
     *
     * Return shape:
     * [
     *   'head_coach' => [
     *     [ 'person' => object, 'wp_user' => object|null,
     *       'functional_role_key' => 'head_coach',
     *       'functional_role_id'  => int,
     *       'assignment_id' => int,
     *       'start_date' => string|null, 'end_date' => string|null,
     *       'source' => 'assignment' ],
     *     ...
     *   ],
     *   'assistant_coach' => [ ... ],
     *   ...
     * ]
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getTeamStaff( int $team_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $grouped = [];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tp.id AS assignment_id, tp.role_in_team, tp.functional_role_id,
                    tp.start_date, tp.end_date,
                    fr.role_key AS functional_role_key,
                    p.*
             FROM {$p}tt_team_people tp
             INNER JOIN {$p}tt_people p ON p.id = tp.person_id
             LEFT  JOIN {$p}tt_functional_roles fr ON fr.id = tp.functional_role_id
             WHERE tp.team_id = %d
             ORDER BY p.last_name ASC",
            $team_id
        ) );

        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                // Prefer the functional role key when available (post-migration
                // state). Fall back to role_in_team for any row that somehow
                // didn't get backfilled.
                $group_key = $r->functional_role_key ?: (string) $r->role_in_team;
                $grouped[ $group_key ][] = [
                    'person'              => $r,
                    'wp_user'             => $r->wp_user_id ? get_userdata( (int) $r->wp_user_id ) : null,
                    'functional_role_key' => (string) $group_key,
                    'functional_role_id'  => $r->functional_role_id !== null ? (int) $r->functional_role_id : null,
                    'assignment_id'       => (int) $r->assignment_id,
                    'start_date'          => $r->start_date,
                    'end_date'            => $r->end_date,
                    'source'              => 'assignment',
                ];
            }
        }

        return $grouped;
    }

    /**
     * @return array<int, object>
     */
    public function getPersonTeams( int $person_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tp.*, t.name AS team_name, t.age_group,
                    fr.role_key AS functional_role_key
             FROM {$p}tt_team_people tp
             INNER JOIN {$p}tt_teams t ON t.id = tp.team_id
             LEFT  JOIN {$p}tt_functional_roles fr ON fr.id = tp.functional_role_id
             WHERE tp.person_id = %d
             ORDER BY t.name ASC",
            $person_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Assign a person to a team under a specific functional role.
     *
     * v2.10.0 (Sprint 1G): Accepts a functional_role_id. Also writes the
     * legacy role_in_team string for continuity with the
     * uniq_team_person_role index (kept during the transition). Both
     * columns stay in sync for rows written through this method.
     */
    public function assignToTeam( int $team_id, int $person_id, int $functional_role_id, ?string $start = null, ?string $end = null ): bool {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $team_id <= 0 || $person_id <= 0 || $functional_role_id <= 0 ) return false;

        $fn_role_key = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT role_key FROM {$p}tt_functional_roles WHERE id = %d",
            $functional_role_id
        ) );
        if ( $fn_role_key === '' ) return false;

        $result = $wpdb->insert( "{$p}tt_team_people", [
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $functional_role_id,
            'role_in_team'       => $fn_role_key,
            'start_date'         => $start ?: null,
            'end_date'           => $end ?: null,
        ] );
        if ( $result === false ) return false;

        /**
         * Fires after a person is assigned to a team.
         *
         * @param int    $team_id
         * @param int    $person_id
         * @param string $functional_role_key
         * @param int    $functional_role_id
         */
        do_action( 'tt_person_assigned_to_team', $team_id, $person_id, $fn_role_key, $functional_role_id );

        return true;
    }

    public function unassign( int $assignment_id ): bool {
        global $wpdb;
        $p = $wpdb->prefix;
        $result = $wpdb->delete( "{$p}tt_team_people", [ 'id' => $assignment_id ] );
        return $result !== false;
    }

    /* ═══════════════ Internal helpers ═══════════════ */

    /**
     * Accepts raw admin input and returns a sanitized associative array
     * suitable for $wpdb->insert/update. If $partial is true, only keys
     * present in $data are returned (for updates).
     */
    private static function sanitizeForStorage( array $data, bool $partial = false ): array {
        $out = [];
        $map = [
            'first_name' => 'text',
            'last_name'  => 'text',
            'email'      => 'email',
            'phone'      => 'text',
            'role_type'  => 'role_type',
            'wp_user_id' => 'int_nullable',
            'status'     => 'status',
        ];

        foreach ( $map as $key => $type ) {
            if ( $partial && ! array_key_exists( $key, $data ) ) continue;
            $raw = $data[ $key ] ?? null;
            switch ( $type ) {
                case 'text':
                    $out[ $key ] = sanitize_text_field( (string) ( $raw ?? '' ) );
                    break;
                case 'email':
                    $val = sanitize_email( (string) ( $raw ?? '' ) );
                    $out[ $key ] = $val !== '' ? $val : null;
                    break;
                case 'int_nullable':
                    $val = $raw === null || $raw === '' ? null : (int) $raw;
                    $out[ $key ] = $val && $val > 0 ? $val : null;
                    break;
                case 'role_type':
                    $val = sanitize_text_field( (string) ( $raw ?? 'other' ) );
                    $out[ $key ] = in_array( $val, self::ROLE_TYPES, true ) ? $val : 'other';
                    break;
                case 'status':
                    $val = sanitize_text_field( (string) ( $raw ?? 'active' ) );
                    $out[ $key ] = in_array( $val, [ 'active', 'inactive' ], true ) ? $val : 'active';
                    break;
            }
        }

        return $out;
    }
}
