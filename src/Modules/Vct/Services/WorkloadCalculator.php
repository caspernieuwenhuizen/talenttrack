<?php
namespace TT\Modules\Vct\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WorkloadCalculator — pure-function helpers for session + microcycle
 * load computation.
 *
 * No state, no DB. The nightly aggregation task (VCT-7) instantiates
 * this and feeds it rows; the REST `/vct/players/{id}/workload`
 * surface uses the rolling helpers below.
 */
class WorkloadCalculator {

    /**
     * Single-session load: Σ (block.intensity × block.duration).
     *
     * @param list<array<string,mixed>> $blocks
     */
    public function sessionLoad( array $blocks ): int {
        $load = 0;
        foreach ( $blocks as $b ) {
            $load += (int) ( $b['intensity_band'] ?? 0 ) * (int) ( $b['duration_minutes'] ?? 0 );
        }
        return $load;
    }

    /**
     * Rolling N-day load given a list of (date, load) tuples.
     *
     * @param list<array{date:string, load:int}> $entries
     */
    public function rollingLoad( array $entries, string $window_end, int $days ): int {
        if ( $days < 1 ) return 0;
        $end_ts = strtotime( $window_end );
        if ( $end_ts === false ) return 0;
        $cutoff = $end_ts - ( $days - 1 ) * 86400;

        $total = 0;
        foreach ( $entries as $e ) {
            $ts = strtotime( (string) $e['date'] );
            if ( $ts === false || $ts < $cutoff || $ts > $end_ts ) continue;
            $total += (int) $e['load'];
        }
        return $total;
    }

    /**
     * ACWR (Acute:Chronic Workload Ratio): 7-day load / 28-day load.
     * Returns null when chronic load is 0 (undefined ratio).
     */
    public function acwr( int $acute_7d, int $chronic_28d ): ?float {
        if ( $chronic_28d <= 0 ) return null;
        return round( $acute_7d / $chronic_28d, 2 );
    }

    /**
     * Classify the ACWR into a coarse flag for the UI. Thresholds
     * are conservative defaults; spec § What's deliberately
     * conservative — tunable per club in Phase 2.
     */
    public function flagForAcwr( ?float $acwr ): ?string {
        if ( $acwr === null ) return null;
        if ( $acwr >= 1.50 ) return 'acwr_high';
        if ( $acwr <  0.80 ) return 'acwr_low';
        return null;
    }

    /**
     * Apply the per-player PHV reduction to a single player's load
     * contribution. The full-roster reduction in WorkloadCapRule is
     * the session-level surface; this helper drives the per-player
     * snapshot writes in the nightly task.
     */
    public function applyPhvReduction( int $base_load, int $reduction_pct ): int {
        $pct = max( 0, min( 100, $reduction_pct ) );
        return (int) round( $base_load * ( 100 - $pct ) / 100 );
    }
}
