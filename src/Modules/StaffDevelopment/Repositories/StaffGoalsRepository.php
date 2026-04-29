<?php
namespace TT\Modules\StaffDevelopment\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

class StaffGoalsRepository {

    public const STATUS_PENDING     = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_ARCHIVED    = 'archived';

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_staff_goals';
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) ) ?: null;
    }

    /** @return object[] */
    public function listForPerson( int $person_id, bool $include_archived = false ): array {
        if ( $person_id <= 0 ) return [];
        $sql = "SELECT * FROM {$this->table} WHERE person_id = %d AND club_id = %d";
        if ( ! $include_archived ) $sql .= " AND archived_at IS NULL";
        $sql .= " ORDER BY priority DESC, due_date ASC, id DESC";
        return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $person_id, CurrentClub::id() ) ) ?: [];
    }

    public function create( array $data ): int {
        $row = [
            'club_id'             => CurrentClub::id(),
            'person_id'           => (int) ( $data['person_id'] ?? 0 ),
            'season_id'           => isset( $data['season_id'] ) ? (int) $data['season_id'] : null,
            'title'               => (string) ( $data['title'] ?? '' ),
            'description'         => (string) ( $data['description'] ?? '' ),
            'status'              => (string) ( $data['status'] ?? self::STATUS_PENDING ),
            'priority'            => (string) ( $data['priority'] ?? 'medium' ),
            'due_date'            => $data['due_date'] ?? null,
            'cert_type_lookup_id' => isset( $data['cert_type_lookup_id'] ) ? (int) $data['cert_type_lookup_id'] : null,
            'created_by'          => (int) ( $data['created_by'] ?? get_current_user_id() ),
        ];
        $ok = $this->wpdb->insert( $this->table, $row );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    public function update( int $id, array $data ): bool {
        if ( $id <= 0 ) return false;
        $allowed = [ 'title', 'description', 'status', 'priority', 'due_date', 'season_id', 'cert_type_lookup_id', 'archived_at' ];
        $row = array_intersect_key( $data, array_flip( $allowed ) );
        if ( ! $row ) return false;
        return (bool) $this->wpdb->update(
            $this->table,
            $row,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    public function archive( int $id ): bool {
        return $this->update( $id, [ 'archived_at' => current_time( 'mysql' ), 'status' => self::STATUS_ARCHIVED ] );
    }
}
