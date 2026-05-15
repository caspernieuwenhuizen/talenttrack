<?php
namespace TT\Modules\Prospects\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ScoutingVisitsRepository — read/write `tt_scouting_plan_visits`.
 *
 * The visit entity sits next to `tt_prospects` and links many-to-one:
 * a single visit can produce zero or more prospects. The link is
 * carried on `tt_prospects.scouting_visit_id`.
 */
class ScoutingVisitsRepository {

    public const STATUS_PLANNED   = 'planned';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_scouting_plan_visits';
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * @param array{scout_user_id?:int,status?:string,from?:string,to?:string,include_archived?:bool} $filters
     * @return object[]
     */
    public function search( array $filters = [] ): array {
        $where  = [ 'club_id = %d' ];
        $params = [ CurrentClub::id() ];

        if ( ! empty( $filters['scout_user_id'] ) ) {
            $where[]  = 'scout_user_id = %d';
            $params[] = (int) $filters['scout_user_id'];
        }
        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = (string) $filters['status'];
        }
        if ( ! empty( $filters['from'] ) ) {
            $where[]  = 'visit_date >= %s';
            $params[] = (string) $filters['from'];
        }
        if ( ! empty( $filters['to'] ) ) {
            $where[]  = 'visit_date <= %s';
            $params[] = (string) $filters['to'];
        }
        if ( empty( $filters['include_archived'] ) ) {
            $where[] = 'archived_at IS NULL';
        }

        $sql = "SELECT * FROM {$this->table}
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY visit_date DESC, id DESC";

        /** @var string $prepared */
        $prepared = $this->wpdb->prepare( $sql, ...$params );
        $rows = $this->wpdb->get_results( $prepared );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Upcoming visits (planned + visit_date >= today) for a scout.
     *
     * @return object[]
     */
    public function upcomingForScout( int $scout_user_id, int $limit = 5 ): array {
        if ( $scout_user_id <= 0 ) return [];
        $today = current_time( 'Y-m-d' );
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE club_id = %d
                AND scout_user_id = %d
                AND status = %s
                AND visit_date >= %s
                AND archived_at IS NULL
              ORDER BY visit_date ASC, COALESCE(visit_time, '00:00:00') ASC, id ASC
              LIMIT %d",
            CurrentClub::id(), $scout_user_id, self::STATUS_PLANNED, $today, $limit
        );
        $rows = $this->wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Count prospects logged from a visit.
     */
    public function prospectCount( int $visit_id ): int {
        if ( $visit_id <= 0 ) return 0;
        $prospects = $this->wpdb->prefix . 'tt_prospects';
        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$prospects}
              WHERE club_id = %d
                AND scouting_visit_id = %d
                AND archived_at IS NULL",
            CurrentClub::id(), $visit_id
        ) );
    }

    /**
     * Prospects logged from a visit (id, names, status).
     *
     * @return object[]
     */
    public function prospectsForVisit( int $visit_id ): array {
        if ( $visit_id <= 0 ) return [];
        $prospects = $this->wpdb->prefix . 'tt_prospects';
        $sql = $this->wpdb->prepare(
            "SELECT id, first_name, last_name, current_club, position, dob, archived_at,
                    promoted_to_player_id, promoted_to_trial_case_id, discovered_at
              FROM {$prospects}
             WHERE club_id = %d
               AND scouting_visit_id = %d
             ORDER BY archived_at IS NULL DESC, discovered_at DESC, id DESC",
            CurrentClub::id(), $visit_id
        );
        $rows = $this->wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create( array $data ): int {
        $insert = [
            'club_id'           => CurrentClub::id(),
            'uuid'              => wp_generate_uuid4(),
            'scout_user_id'     => (int) ( $data['scout_user_id'] ?? get_current_user_id() ),
            'visit_date'        => (string) ( $data['visit_date'] ?? gmdate( 'Y-m-d' ) ),
            'visit_time'        => $data['visit_time'] ?? null,
            'location'          => (string) ( $data['location'] ?? '' ),
            'event_description' => $data['event_description'] ?? null,
            'age_groups_csv'    => $data['age_groups_csv'] ?? null,
            'notes'             => $data['notes'] ?? null,
            'status'            => $data['status'] ?? self::STATUS_PLANNED,
        ];
        $ok = $this->wpdb->insert( $this->table, $insert );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function update( int $id, array $patch ): bool {
        if ( $id <= 0 || ! $patch ) return false;
        $allowed = [ 'visit_date', 'visit_time', 'location', 'event_description', 'age_groups_csv', 'notes', 'status', 'archived_at' ];
        $clean = array_intersect_key( $patch, array_flip( $allowed ) );
        if ( ! $clean ) return false;
        return (bool) $this->wpdb->update( $this->table, $clean, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
    }

    public function archive( int $id ): bool {
        return $this->update( $id, [ 'archived_at' => current_time( 'mysql' ) ] );
    }
}
