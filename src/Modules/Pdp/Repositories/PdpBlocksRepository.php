<?php
namespace TT\Modules\Pdp\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PdpBlocksRepository (v3.110.191) — CRUD on `tt_pdp_blocks`,
 * the academy-configurable per-season PDP cycle blocks.
 *
 * Reads + writes scope by `club_id = CurrentClub::id()` automatically.
 * The caller is responsible for cap-checking — the REST controller
 * gates on `tt_edit_settings` (admin-tier auth).
 *
 * Listings come back ordered by `sequence ASC`. When no blocks
 * exist for a given season, `listForSeason()` returns an empty
 * array — callers fall back to the legacy even-divide behaviour.
 */
class PdpBlocksRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_pdp_blocks';
    }

    /**
     * @return list<array{sequence:int,start_date:string,end_date:string}>
     *         Ordered by sequence ASC. Empty when nothing is configured.
     */
    public function listForSeason( int $season_id ): array {
        if ( $season_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT sequence, start_date, end_date
               FROM {$this->table}
              WHERE club_id = %d AND season_id = %d
              ORDER BY sequence ASC",
            CurrentClub::id(), $season_id
        ) );
        if ( ! is_array( $rows ) ) return [];
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'sequence'   => (int) $r->sequence,
                'start_date' => (string) $r->start_date,
                'end_date'   => (string) $r->end_date,
            ];
        }
        return $out;
    }

    /**
     * Replace the whole block set for a season. Delete + insert
     * pattern. The REST + UI layers validate the input set before
     * calling this so the repo trusts the shape on arrival; only
     * the lightest sanity guards live here.
     *
     * @param list<array{sequence:int,start_date:string,end_date:string}> $blocks
     */
    public function replaceForSeason( int $season_id, array $blocks ): bool {
        if ( $season_id <= 0 ) return false;
        $club_id = CurrentClub::id();

        foreach ( $blocks as $b ) {
            $seq   = (int) ( $b['sequence']   ?? 0 );
            $start = (string) ( $b['start_date'] ?? '' );
            $end   = (string) ( $b['end_date']   ?? '' );
            if ( $seq < 1 || $seq > 4 )                                 return false;
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) )      return false;
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) )        return false;
            if ( $end < $start )                                         return false;
        }

        $this->wpdb->delete( $this->table, [
            'club_id'   => $club_id,
            'season_id' => $season_id,
        ] );

        foreach ( $blocks as $b ) {
            $ok = $this->wpdb->insert( $this->table, [
                'club_id'    => $club_id,
                'season_id'  => $season_id,
                'sequence'   => (int) $b['sequence'],
                'start_date' => (string) $b['start_date'],
                'end_date'   => (string) $b['end_date'],
            ] );
            if ( ! $ok ) return false;
        }
        return true;
    }

    /**
     * Find a single block by (season, sequence). Used by
     * `PdpConversationsRepository::createCycle()` when seeding a
     * new file's conversation windows.
     *
     * @return array{sequence:int,start_date:string,end_date:string}|null
     */
    public function find( int $season_id, int $sequence ): ?array {
        if ( $season_id <= 0 || $sequence < 1 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT sequence, start_date, end_date
               FROM {$this->table}
              WHERE club_id = %d AND season_id = %d AND sequence = %d
              LIMIT 1",
            CurrentClub::id(), $season_id, $sequence
        ) );
        if ( ! $row ) return null;
        return [
            'sequence'   => (int) $row->sequence,
            'start_date' => (string) $row->start_date,
            'end_date'   => (string) $row->end_date,
        ];
    }
}
