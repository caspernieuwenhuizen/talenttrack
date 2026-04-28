<?php
namespace TT\Modules\Trials\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

class TrialTracksRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_trial_tracks';
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d", $id
        ) );
        return $row ?: null;
    }

    public function findBySlug( string $slug ): ?object {
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE slug = %s", $slug
        ) );
        return $row ?: null;
    }

    /**
     * @return object[]
     */
    public function listAll( bool $include_archived = false ): array {
        $sql = "SELECT * FROM {$this->table}";
        if ( ! $include_archived ) {
            $sql .= " WHERE archived_at IS NULL";
        }
        $sql .= " ORDER BY sort_order ASC, name ASC";
        $rows = $this->wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create( array $data ): int {
        $slug = sanitize_title( (string) ( $data['slug'] ?? $data['name'] ?? '' ) );
        if ( $slug === '' ) return 0;
        $insert = [
            'slug'                  => $slug,
            'name'                  => (string) ( $data['name'] ?? $slug ),
            'description'           => $data['description'] ?? null,
            'default_duration_days' => (int) ( $data['default_duration_days'] ?? 28 ),
            'sort_order'            => (int) ( $data['sort_order'] ?? 100 ),
            'is_seeded'             => 0,
        ];
        $ok = $this->wpdb->insert( $this->table, $insert );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function update( int $id, array $patch ): bool {
        $allowed = [ 'name', 'description', 'default_duration_days', 'sort_order', 'archived_at' ];
        $existing = $this->find( $id );
        if ( ! $existing ) return false;
        if ( ! (int) $existing->is_seeded && isset( $patch['slug'] ) ) {
            $allowed[] = 'slug';
        }
        $clean = array_intersect_key( $patch, array_flip( $allowed ) );
        if ( ! $clean ) return false;
        return (bool) $this->wpdb->update( $this->table, $clean, [ 'id' => $id ] );
    }

    public function archive( int $id ): bool {
        return $this->update( $id, [ 'archived_at' => current_time( 'mysql', true ) ] );
    }
}
