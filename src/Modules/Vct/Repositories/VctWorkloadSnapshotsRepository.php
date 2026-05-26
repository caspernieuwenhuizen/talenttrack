<?php
namespace TT\Modules\Vct\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * VctWorkloadSnapshotsRepository — per-player load aggregates.
 *
 * Written by the nightly task (VCT-7); read by RecoveryRule + the
 * Phase 2 workload dashboard. INSERT ... ON DUPLICATE KEY UPDATE for
 * idempotent re-runs.
 */
class VctWorkloadSnapshotsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_vct_workload_snapshots';
    }

    /**
     * Most recent snapshot for $player_id, on or before $on_or_before.
     *
     * @return array<string,mixed>|null
     */
    public function latestForPlayer( int $player_id, string $on_or_before ): ?array {
        if ( $player_id <= 0 ) return null;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $on_or_before ) ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE club_id = %d AND player_id = %d AND snapshot_date <= %s
              ORDER BY snapshot_date DESC
              LIMIT 1",
            CurrentClub::id(), $player_id, $on_or_before
        ) );
        if ( ! $row ) return null;
        return $this->hydrate( $row );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForPlayer( int $player_id, string $window_start, string $window_end ): array {
        if ( $player_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE club_id = %d AND player_id = %d
                AND snapshot_date BETWEEN %s AND %s
              ORDER BY snapshot_date ASC",
            CurrentClub::id(), $player_id, $window_start, $window_end
        ) );
        if ( ! is_array( $rows ) ) return [];
        return array_map( [ $this, 'hydrate' ], $rows );
    }

    /**
     * Idempotent upsert — INSERT ... ON DUPLICATE KEY UPDATE on the
     * unique (club_id, player_id, snapshot_date) index. Used by the
     * nightly aggregator (VCT-7) so re-running the night's job is safe.
     */
    public function upsert(
        int $player_id,
        string $snapshot_date,
        int $session_load_24h,
        int $session_load_7d,
        int $session_load_28d,
        ?float $acwr,
        ?string $flag
    ): bool {
        $sql = "INSERT INTO {$this->table}
                  (club_id, player_id, snapshot_date, session_load_24h, session_load_7d, session_load_28d, acwr, flag)
                VALUES (%d, %d, %s, %d, %d, %d, %s, %s)
                ON DUPLICATE KEY UPDATE
                  session_load_24h = VALUES(session_load_24h),
                  session_load_7d  = VALUES(session_load_7d),
                  session_load_28d = VALUES(session_load_28d),
                  acwr             = VALUES(acwr),
                  flag             = VALUES(flag)";
        $params = [
            CurrentClub::id(),
            $player_id,
            $snapshot_date,
            $session_load_24h,
            $session_load_7d,
            $session_load_28d,
            $acwr !== null ? number_format( $acwr, 2, '.', '' ) : null,
            $flag,
        ];
        $ok = $this->wpdb->query( $this->wpdb->prepare( $sql, $params ) );
        return $ok !== false;
    }

    /** @param object $row */
    private function hydrate( $row ): array {
        return [
            'id'              => (int) $row->id,
            'player_id'       => (int) $row->player_id,
            'snapshot_date'   => (string) $row->snapshot_date,
            'session_load_24h'=> (int) $row->session_load_24h,
            'session_load_7d' => (int) $row->session_load_7d,
            'session_load_28d'=> (int) $row->session_load_28d,
            'acwr'            => $row->acwr !== null ? (float) $row->acwr : null,
            'flag'            => $row->flag !== null ? (string) $row->flag : null,
        ];
    }
}
