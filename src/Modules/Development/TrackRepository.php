<?php
namespace TT\Modules\Development;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TrackRepository — CRUD around `tt_dev_tracks`.
 *
 * Tracks are an admin-curated list (e.g. "Speed", "Game intelligence").
 * Ideas can optionally be tagged to a track for the player-development
 * roadmap surface (Tracks tile).
 */
class TrackRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_dev_tracks';
    }

    public function create( string $name, string $description = '' ): int {
        $name = trim( $name );
        if ( $name === '' ) return 0;
        $sort = (int) $this->wpdb->get_var( "SELECT COALESCE(MAX(sort_order),0)+1 FROM {$this->table}" );
        $this->wpdb->insert( $this->table, [
            'name'        => $name,
            'description' => $description,
            'sort_order'  => $sort,
        ] );
        return (int) $this->wpdb->insert_id;
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id )
        );
        return $row ?: null;
    }

    public function update( int $id, string $name, string $description ): bool {
        if ( $id <= 0 ) return false;
        $ok = $this->wpdb->update(
            $this->table,
            [ 'name' => $name, 'description' => $description ],
            [ 'id' => $id ]
        );
        return $ok !== false;
    }

    public function delete( int $id ): bool {
        if ( $id <= 0 ) return false;
        // Detach ideas from this track first so we don't orphan rows.
        $this->wpdb->update(
            $this->wpdb->prefix . 'tt_dev_ideas',
            [ 'track_id' => null ],
            [ 'track_id' => $id ]
        );
        return (bool) $this->wpdb->delete( $this->table, [ 'id' => $id ] );
    }

    public function reorder( int $id, int $sortOrder ): bool {
        if ( $id <= 0 ) return false;
        $ok = $this->wpdb->update( $this->table, [ 'sort_order' => $sortOrder ], [ 'id' => $id ] );
        return $ok !== false;
    }

    /** @return list<object> */
    public function listAll(): array {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY sort_order ASC, id ASC"
        );
        return is_array( $rows ) ? $rows : [];
    }
}
