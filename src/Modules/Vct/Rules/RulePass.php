<?php
namespace TT\Modules\Vct\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RulePass — the unit of work in the VCT rules pipeline.
 *
 * Each pass takes a SessionPlanContext, may read from its injected
 * dependencies (repositories or providers), mutates the context in
 * place, and returns it. The engine runs passes in a fixed sequence:
 *
 *   1. AgeAdmissibilityRule
 *   2. MdContextRule
 *   3. SessionCompositionRule
 *   4. TacticalThemeRule
 *   5. ProgressionRule
 *   6. ExerciseSelectionPass     (skipped in validate() mode)
 *   7. WorkloadCapRule
 *   8. RecoveryRule
 *  --
 *   FinalizationPass             (composes the Session payload)
 *
 * Passes that read from the database must declare their dependencies
 * as constructor-injected interfaces or repositories — this is the
 * architecture review H2 rule. No pass reads a global, a singleton,
 * or `$wpdb` directly.
 */
interface RulePass {

    public function apply( SessionPlanContext $ctx ): SessionPlanContext;
}
