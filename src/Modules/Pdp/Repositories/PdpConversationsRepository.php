<?php
namespace TT\Modules\Pdp\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PdpConversationsRepository — N conversations per PDP file.
 *
 * createCycle() seeds the full set of N rows when a file is opened.
 * Subsequent calls update individual rows by id.
 */
class PdpConversationsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_pdp_conversations';
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ) );
        return $row ?: null;
    }

    /** @return object[] */
    public function listForFile( int $file_id ): array {
        if ( $file_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE pdp_file_id = %d ORDER BY sequence ASC",
            $file_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Seed the full conversation cycle for a freshly-created file.
     * Distributes scheduled_at evenly between the season's start +
     * end. cycle_size is one of 2 / 3 / 4. Returns the count inserted
     * (0 if any guard fails).
     */
    public function createCycle( int $file_id, int $cycle_size, string $season_start, string $season_end ): int {
        if ( $file_id <= 0 ) return 0;
        if ( ! in_array( $cycle_size, [ 2, 3, 4 ], true ) ) return 0;

        $start_ts = strtotime( $season_start . ' 00:00:00' );
        $end_ts   = strtotime( $season_end   . ' 23:59:59' );
        if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) return 0;

        // Distribute evenly. The Nth conversation lands at the
        // mid-point of the Nth slice — first conversation early-
        // season, last conversation late-season, never on the very
        // first or last day.
        $span = $end_ts - $start_ts;
        $step = (int) floor( $span / ( $cycle_size + 1 ) );

        $template_keys = $this->templateKeysFor( $cycle_size );

        $inserted = 0;
        for ( $i = 1; $i <= $cycle_size; $i++ ) {
            $when = gmdate( 'Y-m-d H:i:s', $start_ts + $step * $i );
            $ok = $this->wpdb->insert( $this->table, [
                'pdp_file_id'  => $file_id,
                'sequence'     => $i,
                'template_key' => $template_keys[ $i - 1 ] ?? 'mid',
                'scheduled_at' => $when,
            ] );
            if ( $ok ) $inserted++;
        }
        return $inserted;
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function update( int $id, array $patch ): bool {
        if ( $id <= 0 || empty( $patch ) ) return false;

        $allowed = [
            'scheduled_at', 'conducted_at', 'agenda', 'notes',
            'agreed_actions', 'player_reflection',
            'coach_signoff_at', 'parent_ack_at', 'player_ack_at',
        ];
        $clean = [];
        foreach ( $patch as $k => $v ) {
            if ( in_array( $k, $allowed, true ) ) $clean[ $k ] = $v;
        }
        if ( empty( $clean ) ) return false;

        $ok = $this->wpdb->update( $this->table, $clean, [ 'id' => $id ] );
        return $ok !== false;
    }

    /** @return list<string> */
    private function templateKeysFor( int $cycle_size ): array {
        return match ( $cycle_size ) {
            2       => [ 'start', 'end' ],
            3       => [ 'start', 'mid', 'end' ],
            4       => [ 'start', 'mid_a', 'mid_b', 'end' ],
            default => [],
        };
    }
}
