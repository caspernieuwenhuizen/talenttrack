<?php
namespace TT\Modules\Wizards\MarkAttendance;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Wizards\Evaluation\NewEvaluationWizard;
use TT\Shared\Wizards\WizardInterface;

/**
 * MarkAttendanceWizard (#0092; converged to an alias in #2249).
 *
 * The `mark-attendance` wizard was always two front doors onto one
 * shared step chain — every step wrote the same `tt_attendance` /
 * `tt_evaluations` rows the `new-evaluation` wizard produces. #2249
 * finishes that convergence: this class is now a **thin alias** of
 * `NewEvaluationWizard`.
 *
 * The slug stays `mark-attendance` so existing
 * `WizardEntryPoint::urlFor('mark-attendance', …)` callers, the
 * dashboard hero deep-links, and any bookmarks keep resolving. Its
 * steps are the unified wizard's steps; `initialState()` seeds
 * `mode=activity` (+ `_path=activity-first`) so the mode step and the
 * activity picker auto-skip and the coach lands directly on the
 * attendance roster.
 *
 * There is no longer a duplicate step chain — the alias delegates its
 * `steps()` to the unified wizard.
 */
final class MarkAttendanceWizard implements WizardInterface {

    private NewEvaluationWizard $delegate;

    public function __construct() {
        $this->delegate = new NewEvaluationWizard();
    }

    public function slug(): string  { return 'mark-attendance'; }
    public function label(): string { return __( 'Mark attendance', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_evaluations'; }

    /**
     * Land on the attendance step: the mode step + activity picker
     * auto-skip because `initialState()` seeds `mode=activity` and an
     * `activity_id`. When no `activity_id` is seeded (the "Pick a
     * session" empty-hero door), the activity picker renders instead —
     * still on the activity branch.
     */
    public function firstStepSlug(): string { return 'evaluation-mode'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return $this->delegate->steps();
    }

    /**
     * Seed wizard state from the entry URL. Read by FrontendWizardView
     * right after `WizardState::start()` so the first render sees the
     * preselected activity and the mode/routing hints.
     *
     * @param array<string,mixed> $url_params  Raw `$_GET` snapshot.
     * @return array<string,mixed>
     */
    public function initialState( array $url_params ): array {
        $seed = [
            // #2249 — seed the unified wizard's activity branch. `mode`
            // is the canonical fork key; `_path` mirrors it for the
            // downstream steps' existing skip logic.
            'mode'              => 'activity',
            '_path'             => 'activity-first',
            // The eval-wizard's AttendanceStep auto-skips when
            // `tt_attendance` rows already exist. That's wrong for the
            // mark-attendance door: the coach clicked Mark attendance
            // because they want to mark/correct attendance — they expect
            // the roster every time, pre-filled with existing values.
            '_attendance_force_render' => 1,
            // Return the coach to the dashboard on completion (Skip and
            // Yes-rate paths), matching where they started the flow.
            '_done_redirect'    => \TT\Shared\Frontend\Components\RecordLink::dashboardUrl(),
        ];
        $aid = isset( $url_params['activity_id'] ) ? absint( $url_params['activity_id'] ) : 0;
        if ( $aid > 0 ) {
            $seed['activity_id'] = $aid;
        }
        return $seed;
    }
}
