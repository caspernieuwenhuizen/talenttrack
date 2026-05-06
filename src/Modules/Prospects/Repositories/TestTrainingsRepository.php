<?php
namespace TT\Modules\Prospects\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TestTrainingsRepository (#0081 child 1) — read/write
 * `tt_test_trainings`. Sessions a prospect is invited to. Many-to-many
 * to prospects is via the workflow task's `TaskContext` carrying
 * `test_training_id`, not a join table.
 */
class TestTrainingsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_test_trainings';
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
     * @param array{from?:string,to?:string,coach_user_id?:int,age_group_lookup_id?:int,include_archived?:bool} $filters
     * @return object[]
     */
    public function search( array $filters = [] ): array {
        $where  = [ 'club_id = %d' ];
        $params = [ CurrentClub::id() ];

        if ( ! empty( $filters['from'] ) ) {
            $where[]  = 'date >= %s';
            $params[] = (string) $filters['from'];
        }
        if ( ! empty( $filters['to'] ) ) {
            $where[]  = 'date <= %s';
            $params[] = (string) $filters['to'];
        }
        if ( ! empty( $filters['coach_user_id'] ) ) {
            $where[]  = 'coach_user_id = %d';
            $params[] = (int) $filters['coach_user_id'];
        }
        if ( ! empty( $filters['age_group_lookup_id'] ) ) {
            $where[]  = 'age_group_lookup_id = %d';
            $params[] = (int) $filters['age_group_lookup_id'];
        }
        if ( empty( $filters['include_archived'] ) ) {
            $where[] = 'archived_at IS NULL';
        }

        $sql = "SELECT * FROM {$this->table}
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY date ASC, id ASC";

        /** @var string $prepared */
        $prepared = $this->wpdb->prepare( $sql, ...$params );
        $rows = $this->wpdb->get_results( $prepared );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create( array $data ): int {
        $insert = [
            'club_id'             => CurrentClub::id(),
            'uuid'                => wp_generate_uuid4(),
            'date'                => (string) ( $data['date'] ?? gmdate( 'Y-m-d H:i:s' ) ),
            'location'            => $data['location'] ?? null,
            'age_group_lookup_id' => isset( $data['age_group_lookup_id'] ) ? (int) $data['age_group_lookup_id'] : null,
            'coach_user_id'       => (int) ( $data['coach_user_id'] ?? get_current_user_id() ),
            'notes'               => $data['notes'] ?? null,
            'created_by'          => (int) ( $data['created_by'] ?? get_current_user_id() ),
        ];
        $ok = $this->wpdb->insert( $this->table, $insert );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function update( int $id, array $patch ): bool {
        if ( $id <= 0 || ! $patch ) return false;
        $allowed = [ 'date', 'location', 'age_group_lookup_id', 'coach_user_id', 'notes', 'archived_at' ];
        $clean = array_intersect_key( $patch, array_flip( $allowed ) );
        if ( ! $clean ) return false;
        return (bool) $this->wpdb->update( $this->table, $clean, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
    }
}
