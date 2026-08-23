<?php
namespace TT\Modules\Knowledge\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * AssignCourseWizard (#2649, epic #2641) — putting a course in front of the
 * people who should take it.
 *
 * Reachable at `?tt_view=wizard&tt_wizard=assign-course`.
 *
 *   1. course   which course, from the ones this install has
 *   2. people   which staff, filtered to the persona the course targets
 *   3. due      an optional deadline
 *   4. confirm  what is about to happen, and to whom
 *
 * ## Why the wizard exists rather than a multi-select on the course page
 *
 * Assigning is the one action in this module that touches other people's
 * records in bulk, and it is the one where a mistake is tedious to undo — a
 * course assigned to forty staff is forty enrolments and forty due dates.
 * CLAUDE.md §3 asks for a wizard on exactly this shape: several decisions,
 * a confirmation, and more than one table written on save.
 *
 * ## Re-assigning is a no-op, not a duplicate
 *
 * `EnrolmentRepository::enrol()` is keyed on `(club_id, course_slug,
 * person_id)`, so the second assignment of the same course to the same coach
 * cannot create a second enrolment. The confirm step says how many people are
 * actually new, so an administrator re-running the wizard over a squad sees
 * "3 new, 12 already enrolled" instead of a silent success that might have
 * reset a dozen due dates.
 *
 * Cap: `tt_manage_knowledge` — this is the management action the capability
 * was defined for. Save + Cancel is exempt under §6 (c): `WizardChrome`
 * supplies Previous / Next / Cancel.
 */
final class AssignCourseWizard implements WizardInterface {

    public function slug(): string { return 'assign-course'; }

    public function label(): string { return __( 'Assign a course', 'talenttrack' ); }

    public function requiredCap(): string { return 'tt_manage_knowledge'; }

    public function firstStepSlug(): string { return 'course'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new AssignCourseStep(),
            new AssignPeopleStep(),
            new AssignDueDateStep(),
            new AssignConfirmStep(),
        ];
    }
}
