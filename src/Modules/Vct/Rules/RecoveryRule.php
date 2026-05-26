<?php
namespace TT\Modules\Vct\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Vct\Repositories\VctWorkloadSnapshotsRepository;

/**
 * RecoveryRule — Pass 8.
 *
 * Enforces the per-age min-recovery-hours gap between high-intensity
 * sessions: 72h for U10-U12, 48h for U13-U14 (Appendix A revised).
 *
 * Reads the most recent workload snapshot for any roster player; if
 * the prior 24h load was high (≥ band 5 equivalent) AND we're inside
 * the gap window, emit a `caution` warning. Doesn't downgrade —
 * coaches can still publish a high-intensity session inside the gap
 * if context demands, but the warning surfaces in the wizard preview.
 *
 * "Recent high-load snapshot" is detected via
 * `session_load_24h > recovery_threshold`. The threshold is derived
 * from age (intensity_band_max * 30 = "one band-max slot of 30 min" =
 * a reasonable proxy for a hard session, calibrated against the
 * seeded session_minutes_max values).
 */
class RecoveryRule implements RulePass {

    private VctWorkloadSnapshotsRepository $snapshots;

    public function __construct( VctWorkloadSnapshotsRepository $snapshots ) {
        $this->snapshots = $snapshots;
    }

    public function apply( SessionPlanContext $ctx ): SessionPlanContext {
        // Skip when the engine hasn't been told who the session is
        // being planned for (e.g. an exploratory generate without a
        // bound roster yet). The recovery gate doesn't apply.
        if ( ! $ctx->roster_player_ids ) return $ctx;

        $gap_hours = $ctx->min_recovery_hours_between_high;
        $cutoff_ts = strtotime( $ctx->session_date );
        if ( $cutoff_ts === false ) return $ctx;
        $cutoff_date = gmdate( 'Y-m-d', $cutoff_ts );

        $recovery_threshold = max( 50, $ctx->intensity_band_max * 30 );
        $hot_players        = [];

        foreach ( $ctx->roster_player_ids as $pid ) {
            $latest = $this->snapshots->latestForPlayer( (int) $pid, $cutoff_date );
            if ( $latest === null ) continue;
            if ( (int) $latest['session_load_24h'] < $recovery_threshold ) continue;

            $last_ts = strtotime( (string) $latest['snapshot_date'] );
            if ( $last_ts === false ) continue;
            $hours_since = max( 0, (int) ( ( $cutoff_ts - $last_ts ) / 3600 ) );

            if ( $hours_since < $gap_hours ) {
                $hot_players[] = [
                    'player_id'   => (int) $pid,
                    'hours_since' => $hours_since,
                ];
            }
        }

        if ( $hot_players ) {
            $ctx->addWarning( 'below_recovery_gap', 'caution', [
                'required_hours'  => $gap_hours,
                'age_group'       => $ctx->age_group,
                'hot_players'     => $hot_players,
            ] );
        }

        return $ctx;
    }
}
