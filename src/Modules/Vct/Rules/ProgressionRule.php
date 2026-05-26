<?php
namespace TT\Modules\Vct\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Vct\Repositories\VctMacroBlocksRepository;

/**
 * ProgressionRule — Pass 5.
 *
 * Finds the macro-block covering session_date for (team_id, season_id)
 * and computes the per-week intensity multiplier from
 * `phase_profile_json`. Multiplier is stamped onto context for the
 * WorkloadCapRule to apply later.
 *
 * No block configured (operator hasn't set up a season) → multiplier
 * stays at 1.0 and the pipeline emits an `info` warning. Coaches see
 * "no progression context — using neutral week" rather than a hard
 * block.
 */
class ProgressionRule implements RulePass {

    private VctMacroBlocksRepository $macro_blocks;

    public function __construct( VctMacroBlocksRepository $macro_blocks ) {
        $this->macro_blocks = $macro_blocks;
    }

    public function apply( SessionPlanContext $ctx ): SessionPlanContext {
        if ( $ctx->season_id <= 0 ) {
            $ctx->progression_multiplier = 1.0;
            $ctx->addWarning( 'no_macro_block_configured', 'info', [
                'reason' => 'season_id_missing_or_unset',
            ] );
            return $ctx;
        }

        $block = $this->macro_blocks->findCurrent( $ctx->team_id, $ctx->season_id, $ctx->session_date );
        if ( $block === null ) {
            $ctx->progression_multiplier = 1.0;
            $ctx->addWarning( 'no_macro_block_configured', 'info', [
                'team_id'      => $ctx->team_id,
                'season_id'    => $ctx->season_id,
                'session_date' => $ctx->session_date,
            ] );
            return $ctx;
        }

        $week_within = $this->weekWithinBlock( $block['start_date'], $ctx->session_date );
        $multiplier  = $this->multiplierForWeek( $block['phase_profile'], $week_within );

        $ctx->progression_multiplier = $multiplier;
        return $ctx;
    }

    /**
     * 1-indexed week-within-block. Week 1 is the seven days starting
     * at `start_date`. Returns at minimum 1 even for session_date <
     * start_date (defensive — shouldn't happen because the repo
     * filters by date range).
     */
    private function weekWithinBlock( string $start_date, string $session_date ): int {
        $start = strtotime( $start_date );
        $sess  = strtotime( $session_date );
        if ( $start === false || $sess === false || $sess < $start ) return 1;
        $days = (int) floor( ( $sess - $start ) / 86400 );
        return max( 1, (int) floor( $days / 7 ) + 1 );
    }

    /**
     * Find the multiplier for the given week from the
     * phase_profile JSON array. Each entry is `{week, phase, multiplier}`.
     * Falls back to 1.0 if the requested week is beyond the profile.
     *
     * @param list<array<string,mixed>> $profile
     */
    private function multiplierForWeek( array $profile, int $week ): float {
        foreach ( $profile as $entry ) {
            if ( (int) ( $entry['week'] ?? 0 ) === $week ) {
                return (float) ( $entry['multiplier'] ?? 1.0 );
            }
        }
        return 1.0;
    }
}
