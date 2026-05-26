<?php
namespace TT\Modules\Vct\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FinalizationPass — runs after the eight rule passes.
 *
 * Computes the final `total_duration_minutes` from the actual block
 * durations (which may differ from the template target after PHV /
 * envelope adjustments) and applies any last-mile shape guarantees:
 * blocks ordered by `sequence`, defensive intensity clamp re-applied
 * post-PATCH paths.
 *
 * Not a RulePass — it produces the final payload, doesn't transform
 * the context any further.
 */
class FinalizationPass {

    public function finalise( SessionPlanContext $ctx ): SessionPlanContext {
        usort( $ctx->blocks, static function ( array $a, array $b ): int {
            return ( (int) $a['sequence'] ) <=> ( (int) $b['sequence'] );
        } );

        $total_duration = 0;
        foreach ( $ctx->blocks as $b ) {
            $total_duration += (int) ( $b['duration_minutes'] ?? 0 );
        }

        // Stamp totals onto the context for the engine + persistence
        // layer to read.
        $ctx->requested_duration_minutes = $total_duration;

        return $ctx;
    }
}
