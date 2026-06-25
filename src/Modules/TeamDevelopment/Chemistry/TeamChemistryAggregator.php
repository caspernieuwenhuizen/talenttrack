<?php
namespace TT\Modules\TeamDevelopment\Chemistry;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\Repositories\ChemistrySnapshotsRepository;

/**
 * TeamChemistryAggregator (#1017 Phase 4) — Team chemistry over a time
 * window (spec §9): the average of recent Lineup-chemistry snapshots
 * (last 5 / last 10 / season-to-date), distinct from the instantaneous
 * Lineup score. Writes one snapshot per blueprint-save / match-complete.
 */
final class TeamChemistryAggregator {

    public const WINDOW_LAST_5  = 5;
    public const WINDOW_LAST_10 = 10;
    public const WINDOW_SEASON  = 0; // 0 = all snapshots held

    private ChemistrySnapshotsRepository $snapshots;

    public function __construct( ?ChemistrySnapshotsRepository $snapshots = null ) {
        $this->snapshots = $snapshots ?? new ChemistrySnapshotsRepository();
    }

    public function recordSnapshot( int $team_id, ?int $lineup_score, string $source = 'blueprint_save' ): void {
        if ( $team_id <= 0 || $lineup_score === null ) return;
        $this->snapshots->record( $team_id, $lineup_score, $source );
    }

    /**
     * Average lineup chemistry over the window. Null when no snapshots exist.
     */
    public function teamChemistry( int $team_id, int $window = self::WINDOW_LAST_5 ): ?int {
        if ( $team_id <= 0 ) return null;
        $limit = $window > 0 ? $window : 100;
        $rows  = $this->snapshots->listForTeam( $team_id, $limit );

        $vals = [];
        foreach ( $rows as $row ) {
            if ( $row->lineup_chemistry !== null ) {
                $vals[] = (int) $row->lineup_chemistry;
            }
        }
        if ( empty( $vals ) ) return null;
        return (int) round( array_sum( $vals ) / count( $vals ) );
    }
}
