<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * NewEvaluationWizard (#0072) — activity-first, player-fallback rebuild.
 *
 * The pre-#0072 version was a two-step pre-flight (PlayerStep +
 * TypeStep) that handed off to the heavyweight evaluation form. That
 * got coaching backwards: a coach almost always thinks "I just ran
 * U14 training; let me rate the players who were there" — activity-
 * first, attendance-aware, multi-player in one sitting.
 *
 * The new graph:
 *
 *     ActivityPickerStep [skip-if-empty]
 *         ↓
 *     AttendanceStep [skip-if-recorded]
 *         ↓
 *     RateActorsStep
 *         ↓
 *     ReviewStep ← HybridDeepRateStep
 *                ↑
 *                PlayerPickerStep
 *
 * Smart-default landing via `ActivityPickerStep::notApplicableFor()` —
 * coaches with zero recent rateable activities go straight to the
 * player-picker fallback. Both landings expose an escape-hatch link
 * to the other path.
 *
 * Slug stays `new-evaluation` so existing entry points
 * (`WizardEntryPoint::urlFor('new-evaluation', …)`) continue to
 * resolve. Required cap stays `tt_edit_evaluations`.
 */
final class NewEvaluationWizard implements WizardInterface {

    public function slug(): string  { return 'new-evaluation'; }
    public function label(): string { return __( 'New evaluation', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_evaluations'; }
    public function firstStepSlug(): string { return 'activity-picker'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new ActivityPickerStep(),
            new AttendanceStep(),
            new RateActorsStep(),
            new PlayerPickerStep(),
            new HybridDeepRateStep(),
            new ReviewStep(),
        ];
    }
}
