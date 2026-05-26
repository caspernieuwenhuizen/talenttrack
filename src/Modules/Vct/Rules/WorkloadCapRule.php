<?php
namespace TT\Modules\Vct\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Vct\Rules\Providers\VctPhvFlagsProvider;

/**
 * WorkloadCapRule — Pass 7.
 *
 * Sums `block.intensity × block.duration` across the composed blocks
 * to produce a session load, applies the macro-block progression
 * multiplier, and checks two ceilings:
 *
 *   1. Per-block intensity must not exceed the age's intensity_band_max
 *      (downgrade + caution warning if any block does).
 *   2. Total session load must not exceed the weekly envelope's
 *      session share (warn if it does; downgrade is the caller's job —
 *      the rule emits a `caution`, never a `block`, because session-
 *      level over-envelope is ALWAYS a soft constraint per spec).
 *
 * PHV-flag handling: when any roster player carries an active PHV
 * flag, the per-player load contribution for those players is reduced
 * by `growth_spurt_load_reduction_pct`. Modelled here as a roster-
 * weighted reduction applied to the total session load — same
 * intent, computationally cheaper than per-player breakdown in MVP.
 * Phase 2 will surface the per-player breakdown in the workload
 * dashboard.
 */
class WorkloadCapRule implements RulePass {

    private VctPhvFlagsProvider $phv_flags;

    public function __construct( VctPhvFlagsProvider $phv_flags ) {
        $this->phv_flags = $phv_flags;
    }

    public function apply( SessionPlanContext $ctx ): SessionPlanContext {
        // 1. Downgrade any block whose intensity exceeds the ceiling
        //    (defensive — composition + selection should already
        //    respect this, but the validate() path can be called on
        //    a coach's PATCH that smuggled a value through).
        $ceiling = $ctx->intensity_band_max;
        foreach ( $ctx->blocks as $i => $block ) {
            if ( (int) $block['intensity_band'] > $ceiling ) {
                $ctx->addWarning( 'block_intensity_exceeds_age_ceiling', 'block', [
                    'block_sequence' => (int) $block['sequence'],
                    'requested'      => (int) $block['intensity_band'],
                    'ceiling'        => $ceiling,
                    'age_group'      => $ctx->age_group,
                ] );
                $ctx->blocks[ $i ]['intensity_band'] = $ceiling;
            }
        }

        // 2. Compute raw load.
        $raw_load = 0;
        foreach ( $ctx->blocks as $block ) {
            $raw_load += (int) $block['intensity_band'] * (int) $block['duration_minutes'];
        }

        // 3. Apply macro-block progression multiplier.
        $progressed_load = (int) round( $raw_load * $ctx->progression_multiplier );

        // 4. Apply PHV-flag reduction (roster-weighted in MVP).
        $reduction_pct = 0;
        if ( $ctx->roster_player_ids && $ctx->growth_spurt_load_reduction_pct > 0 ) {
            $flagged = $this->phv_flags->activeForRoster( $ctx->roster_player_ids );
            if ( $flagged ) {
                $flagged_count = count( $flagged );
                $roster_count  = max( 1, count( $ctx->roster_player_ids ) );
                $flag_share    = $flagged_count / $roster_count;
                $reduction_pct = (int) round( $ctx->growth_spurt_load_reduction_pct * $flag_share );
                if ( $reduction_pct > 0 ) {
                    $ctx->addWarning( 'phv_load_reduction_applied', 'info', [
                        'flagged_players'  => $flagged_count,
                        'roster_size'      => $roster_count,
                        'reduction_pct'    => $reduction_pct,
                    ] );
                }
            }
        }
        $final_load = (int) round( $progressed_load * ( 100 - $reduction_pct ) / 100 );

        $ctx->total_load = max( 0, $final_load );

        // 5. Envelope warning. Single-session share is loosely
        //    (weekly_envelope / 3) since cadence assumes 2-3 sessions/week.
        $session_share_ceiling = max( 100, (int) ( $ctx->weekly_load_envelope / 3 ) );
        if ( $ctx->total_load > $session_share_ceiling ) {
            $ctx->addWarning( 'near_weekly_envelope', 'caution', [
                'session_load'     => $ctx->total_load,
                'session_ceiling'  => $session_share_ceiling,
                'weekly_envelope'  => $ctx->weekly_load_envelope,
            ] );
        }

        return $ctx;
    }
}
