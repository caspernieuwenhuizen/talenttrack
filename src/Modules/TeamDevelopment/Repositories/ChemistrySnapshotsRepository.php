<?php
namespace TT\Modules\TeamDevelopment\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ChemistrySnapshotsRepository (#1912) — the lineup-chemistry time series.
 * Phase 4 writes one row per blueprint-save / match-complete; Team
 * Chemistry then averages over a window (last 5 / 10 / season). The table
 * exists from migration 0178; this repo is the read/write surface.
 */
class ChemistrySnapshotsRepository {

    public function record( int $team_id, ?int $lineup_chemistry, string $source = 'blueprint_save' ): int {
        if ( $team_id <= 0 ) return 0;
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_team_chemistry_snapshots", [
            'club_id'          => CurrentClub::id(),
            'uuid'             => wp_generate_uuid4(),
            'team_id'          => $team_id,
            'lineup_chemistry' => $lineup_chemistry,
            'source'           => $source !== '' ? $source : 'blueprint_save',
            'computed_at'      => current_time( 'mysql', true ),
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * Most-recent snapshots for a team (newest first).
     *
     * @return array<int, object>
     */
    public function listForTeam( int $team_id, int $limit = 10 ): array {
        if ( $team_id <= 0 ) return [];
        $limit = max( 1, min( 100, $limit ) );
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, lineup_chemistry, source, computed_at
               FROM {$p}tt_team_chemistry_snapshots
              WHERE team_id = %d AND club_id = %d
              ORDER BY computed_at DESC, id DESC
              LIMIT %d",
            $team_id, CurrentClub::id(), $limit
        ) );
        return is_array( $rows ) ? $rows : [];
    }
}
