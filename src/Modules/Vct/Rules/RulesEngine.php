<?php
namespace TT\Modules\Vct\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RulesEngine — orchestrates the eight rule passes + FinalizationPass.
 *
 * Two entry points (architectural fix for the PATCH-trigger-re-selection
 * problem; spec § Rules Engine pipeline):
 *
 *   compose(SessionPlanContext): SessionPlanContext
 *     — Runs the full pipeline INCLUDING ExerciseSelectionPass.
 *     — Used by POST /vct/sessions/generate.
 *
 *   validate(SessionPlanContext): ValidationResult
 *     — Runs passes 1-5 + 7 + 8 only. SKIPS ExerciseSelectionPass so
 *       the coach's existing block exercise_ids are not overwritten.
 *     — Used by PATCH /vct/sessions/{id} after a coach swaps a block.
 *
 * Passes are constructor-injected so the engine is unit-testable
 * with in-memory fake passes (architecture review H2). Production
 * code wires the eight production-pass instances via the
 * `RulesEngineFactory`-style static constructor below.
 */
class RulesEngine {

    private AgeAdmissibilityRule  $age;
    private MdContextRule         $md;
    private SessionCompositionRule $composition;
    private TacticalThemeRule     $theme;
    private ProgressionRule       $progression;
    private ExerciseSelectionPass $selection;
    private WorkloadCapRule       $workload;
    private RecoveryRule          $recovery;
    private FinalizationPass      $finalize;

    public function __construct(
        AgeAdmissibilityRule $age,
        MdContextRule $md,
        SessionCompositionRule $composition,
        TacticalThemeRule $theme,
        ProgressionRule $progression,
        ExerciseSelectionPass $selection,
        WorkloadCapRule $workload,
        RecoveryRule $recovery,
        FinalizationPass $finalize
    ) {
        $this->age         = $age;
        $this->md          = $md;
        $this->composition = $composition;
        $this->theme       = $theme;
        $this->progression = $progression;
        $this->selection   = $selection;
        $this->workload    = $workload;
        $this->recovery    = $recovery;
        $this->finalize    = $finalize;
    }

    /**
     * Run the full pipeline. Returns the mutated context — callers
     * read `$ctx->blocks`, `$ctx->total_load`, `$ctx->warnings`.
     */
    public function compose( SessionPlanContext $ctx ): SessionPlanContext {
        $ctx = $this->age->apply( $ctx );
        if ( $this->hasBlockingWarning( $ctx ) ) return $ctx;

        $ctx = $this->md->apply( $ctx );
        $ctx = $this->composition->apply( $ctx );
        if ( $this->hasBlockingWarning( $ctx ) ) return $ctx;

        $ctx = $this->theme->apply( $ctx );
        $ctx = $this->progression->apply( $ctx );
        $ctx = $this->selection->apply( $ctx );
        if ( $this->hasBlockingWarning( $ctx ) ) return $ctx;

        $ctx = $this->workload->apply( $ctx );
        $ctx = $this->recovery->apply( $ctx );

        return $this->finalize->finalise( $ctx );
    }

    /**
     * Validate an existing session shape against the rules WITHOUT
     * re-running ExerciseSelectionPass. Caller pre-fills
     * `$ctx->blocks` with the current block set; the engine
     * recomputes load + warnings against the current rules and
     * returns a ValidationResult envelope.
     */
    public function validate( SessionPlanContext $ctx ): ValidationResult {
        $ctx = $this->age->apply( $ctx );
        if ( $this->hasBlockingWarning( $ctx ) ) return $this->resultFrom( $ctx );

        $ctx = $this->md->apply( $ctx );
        $ctx = $this->composition->apply( $ctx );
        // Don't propagate composition's block-warning here — validate()
        // is called on an existing session that may not match the
        // template's current slots (operator may have edited the
        // template between generate and validate). Composition's
        // warnings are informational in validate mode.
        $ctx = $this->theme->apply( $ctx );
        $ctx = $this->progression->apply( $ctx );

        // SKIPS ExerciseSelectionPass — the architectural fix.

        $ctx = $this->workload->apply( $ctx );
        $ctx = $this->recovery->apply( $ctx );
        $ctx = $this->finalize->finalise( $ctx );

        return $this->resultFrom( $ctx );
    }

    private function hasBlockingWarning( SessionPlanContext $ctx ): bool {
        foreach ( $ctx->warnings as $w ) {
            if ( ( $w['severity'] ?? '' ) === 'block' ) return true;
        }
        return false;
    }

    private function resultFrom( SessionPlanContext $ctx ): ValidationResult {
        return new ValidationResult(
            ! $this->hasBlockingWarning( $ctx ),
            $ctx->warnings,
            $ctx->total_load
        );
    }
}
