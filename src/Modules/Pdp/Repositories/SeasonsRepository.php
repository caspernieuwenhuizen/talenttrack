<?php
namespace TT\Modules\Pdp\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SeasonsRepository — first-class season entity for #0044.
 *
 * Exactly one row may have is_current = 1 at any time.
 * setCurrent() demotes the previous current row in the same call.
 */
class SeasonsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_seasons';
    }

    /** @return object[] */
    public function all(): array {
        $rows = $this->wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY start_date DESC, id DESC" );
        return is_array( $rows ) ? $rows : [];
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ) );
        return $row ?: null;
    }

    public function current(): ?object {
        $row = $this->wpdb->get_row(
            "SELECT * FROM {$this->table} WHERE is_current = 1 ORDER BY id DESC LIMIT 1"
        );
        return $row ?: null;
    }

    /**
     * @param array{name:string, start_date:string, end_date:string} $data
     * @return int Inserted ID, or 0 on failure.
     */
    public function create( array $data ): int {
        $name  = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
        $start = (string) ( $data['start_date'] ?? '' );
        $end   = (string) ( $data['end_date']   ?? '' );
        if ( $name === '' || $start === '' || $end === '' ) return 0;

        $ok = $this->wpdb->insert( $this->table, [
            'name'       => $name,
            'start_date' => $start,
            'end_date'   => $end,
            'is_current' => 0,
        ] );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    /**
     * Mark a single season as current. Atomically demotes any other.
     * Fires the `tt_pdp_season_set_current` action so the carryover
     * job can roll open goals into the new season.
     */
    public function setCurrent( int $season_id ): bool {
        if ( $season_id <= 0 || ! $this->find( $season_id ) ) return false;
        $this->wpdb->query( $this->wpdb->prepare(
            "UPDATE {$this->table} SET is_current = CASE WHEN id = %d THEN 1 ELSE 0 END",
            $season_id
        ) );
        do_action( 'tt_pdp_season_set_current', $season_id );
        return true;
    }
}
