<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * NewEvaluationWizard (#0072; unified in #2246 / #2249) — the single
 * evaluation wizard behind every door.
 *
 * The pre-#2246 version was activity-first with an implicit player
 * fallback (it landed on ActivityPickerStep and auto-skipped to
 * PlayerPickerStep when empty). #2246 fronts the wizard with an
 * explicit two-way choice — EvaluationModeStep — so the coach always
 * knows whether they're rating an activity's players or one player
 * standalone.
 *
 * The graph:
 *
 *     EvaluationModeStep  (mode = activity | player)
 *         ├─ activity ─→ ActivityPickerStep
 *         │                  ↓
 *         │              AttendanceStep
 *         │                  ↓
 *         │              RateConfirmStep  (Yes / Skip)
 *         │                  ↓ (yes)        ↘ (skip → submit)
 *         │              RateActorsStep
 *         │                  ↓
 *         │              ReviewStep
 *         └─ player   ─→ PlayerPickerStep
 *                            ↓
 *                        HybridDeepRateStep
 *                            ↓
 *                        BehaviourStep  (optional; the only place
 *                            ↓           behaviour is captured)
 *                        ReviewStep
 *
 * #2249 convergence: the former `mark-attendance` wizard is now a thin
 * alias (`MarkAttendanceWizard`) that seeds `mode=activity` and reuses
 * this exact step chain. Every activity door (EP1 completion, the
 * dashboard hero, "Evaluate an activity") seeds `mode=activity` +
 * `activity_id` and skips straight past the mode step and picker.
 *
 * Slug stays `new-evaluation`; required cap stays `tt_edit_evaluations`.
 */
final class NewEvaluationWizard implements WizardInterface {

    public function slug(): string  { return 'new-evaluation'; }
    public function label(): string { return __( 'New evaluation', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_evaluations'; }
    public function firstStepSlug(): string { return 'evaluation-mode'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new EvaluationModeStep(),
            new ActivityPickerStep(),
            new AttendanceStep(),
            new RateConfirmStep(),
            new RateActorsStep(),
            new PlayerPickerStep(),
            new HybridDeepRateStep(),
            new BehaviourStep(),
            new ReviewStep(),
        ];
    }

    /**
     * #2246 / #2249 — opt-in seed hook. Every activity door deep-links
     * `new-evaluation&mode=activity&activity_id=N` and lands directly on
     * the attendance roster, skipping the mode step + picker. Read by
     * FrontendWizardView right after `WizardState::start()`.
     *
     * @param array<string,mixed> $url_params  Raw `$_GET` snapshot.
     * @return array<string,mixed>
     */
    public function initialState( array $url_params ): array {
        $seed = [];
        $mode = isset( $url_params['mode'] ) ? sanitize_key( (string) $url_params['mode'] ) : '';
        if ( $mode === 'activity' ) {
            $seed['mode']  = 'activity';
            $seed['_path'] = 'activity-first';
        } elseif ( $mode === 'player' ) {
            $seed['mode']  = 'player';
            $seed['_path'] = 'player-first';
        }
        $aid = isset( $url_params['activity_id'] ) ? absint( $url_params['activity_id'] ) : 0;
        if ( $aid > 0 && $seed !== [] && ( $seed['mode'] ?? '' ) === 'activity' ) {
            $seed['activity_id'] = $aid;
        }
        return $seed;
    }
}
