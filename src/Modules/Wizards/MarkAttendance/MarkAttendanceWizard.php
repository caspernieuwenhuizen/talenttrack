<?php
namespace TT\Modules\Wizards\MarkAttendance;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Wizards\Evaluation\ActivityPickerStep;
use TT\Modules\Wizards\Evaluation\AttendanceStep;
use TT\Modules\Wizards\Evaluation\RateActorsStep;
use TT\Modules\Wizards\Evaluation\ReviewStep;
use TT\Shared\Wizards\WizardInterface;

/**
 * MarkAttendanceWizard (#0092) — the at-the-pitch coach motion.
 *
 * Frames attendance as the primary action and rating as the optional
 * follow-up, mirroring the coach's mental loop: "who showed up · how
 * did they do · save." The data model is unchanged — every step writes
 * the same `tt_attendance` / `tt_evaluations` rows the existing
 * activity edit form and `new-evaluation` wizard produce.
 *
 * Step chain:
 *
 *     ActivityPickerStep [skipped when activity_id pre-seeded]
 *         ↓
 *     AttendanceStep [skipped when attendance already exists]
 *         ↓
 *     RateConfirmStep   ← new — Yes / Skip fork
 *         ↓ (yes)         ↘ (skip)
 *     RateActorsStep      submit, redirect to activity
 *         ↓
 *     ReviewStep → submit, redirect to evaluations
 *
 * Entry: `?tt_view=wizard&slug=mark-attendance&activity_id=<id>` —
 * the new `MarkAttendanceHeroWidget` deep-links with the upcoming
 * activity preselected. Direct URL with no `activity_id` still
 * works; the coach picks at the activity-picker step.
 *
 * `initialState()` seeds the wizard so AttendanceStep + ReviewStep see
 * the same `_path = activity-first` they expect in the eval wizard,
 * plus `_attendance_next = rate-confirm` so AttendanceStep routes
 * here instead of straight to rating. The hint pattern lets us reuse
 * the eval-wizard steps without forking them.
 */
final class MarkAttendanceWizard implements WizardInterface {

    public function slug(): string  { return 'mark-attendance'; }
    public function label(): string { return __( 'Mark attendance', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_evaluations'; }
    public function firstStepSlug(): string { return 'activity-picker'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new ActivityPickerStep(),
            new AttendanceStep(),
            new RateConfirmStep(),
            new RateActorsStep(),
            new ReviewStep(),
        ];
    }

    /**
     * Seed wizard state from the entry URL. Read by FrontendWizardView
     * right after `WizardState::start()` so the first render sees the
     * preselected activity and the routing hints.
     *
     * @param array<string,mixed> $url_params  Raw `$_GET` snapshot.
     * @return array<string,mixed>
     */
    public function initialState( array $url_params ): array {
        $seed = [
            '_path'             => 'activity-first',
            '_attendance_next'  => 'rate-confirm',
            // v3.110.73 — the eval-wizard's AttendanceStep auto-skips when
            // `tt_attendance` rows already exist (the "you've already done
            // attendance, go straight to rating" optimisation). That logic
            // is wrong for THIS wizard: the coach clicked Mark attendance
            // because they want to mark/correct attendance — they expect
            // the roster every time, pre-filled with existing values.
            // This flag tells AttendanceStep to render even when rows
            // exist; AttendanceStep.render pre-fills from `tt_attendance`
            // so the coach sees their current saved state and can edit it.
            '_attendance_force_render' => 1,
            // v3.110.73 — return the coach to the dashboard on wizard
            // completion (Skip path AND Yes-rate path), not to the
            // evaluations list. The coach entered the wizard from the
            // dashboard hero; returning them there matches their mental
            // model. ReviewStep::submit honours this hint; RateConfirmStep
            // already redirects to the activity detail on Skip — that's
            // a different "I just confirmed status" landing and stays.
            '_done_redirect'    => \TT\Shared\Frontend\Components\RecordLink::dashboardUrl(),
        ];
        $aid = isset( $url_params['activity_id'] ) ? absint( $url_params['activity_id'] ) : 0;
        if ( $aid > 0 ) {
            $seed['activity_id'] = $aid;
        }
        return $seed;
    }
}
