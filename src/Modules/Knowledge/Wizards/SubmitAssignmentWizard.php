<?php
namespace TT\Modules\Knowledge\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * SubmitAssignmentWizard (#2648, epic #2641) — handing in a practical
 * assignment, one thing at a time.
 *
 * Reachable at `?tt_view=wizard&tt_wizard=submit-assignment`, carrying
 * `slug` (course) and `lesson` so the entry point knows what is being
 * handed in. The flat form in the lesson's `tt-assignment` block remains
 * the power-user path (CLAUDE.md §3); this is the guided one, and both end
 * in the same `SubmissionService` call.
 *
 *   1. write    the answer
 *   2. attach   supporting documents, if any
 *   3. confirm  what goes to the reviewer, and who that is
 *
 * ## Why the draft is created before step 1 finishes
 *
 * The same constraint `NewMediaWizard` documents: the wizard's form is not
 * multipart and its state is a transient, so a chosen file cannot survive
 * a step boundary. Uploads therefore commit as they happen, and committing
 * needs something to attach to — a `tt_media_links` row points at a
 * submission id.
 *
 * So a draft row is opened when the wizard starts, with a null
 * `submitted_at`. That is what keeps it out of the review queue, out of
 * `SubmissionRepository::latestFor()` and therefore off both the coach's
 * lesson page and the reviewer's list until step 3 hands it in. Abandoning
 * the wizard leaves a draft nobody sees, and starting again resumes it
 * rather than opening a second — so the abandoned-work cost the media
 * wizard accepts does not arise here.
 *
 * Cap: `tt_view_knowledge`. Handing in your own coursework is not a
 * management action, and requiring one would lock every coach out of the
 * assignments the course is built around. Whether this particular lesson is
 * open to this reader is `CourseAccessResolver`'s question, asked in the
 * first step where the answer can be acted on.
 *
 * Save + Cancel is exempt under CLAUDE.md §6 (c): `WizardChrome` supplies
 * Previous / Next / Cancel.
 */
final class SubmitAssignmentWizard implements WizardInterface {

    public function slug(): string { return 'submit-assignment'; }

    public function label(): string { return __( 'Hand in an assignment', 'talenttrack' ); }

    public function requiredCap(): string { return 'tt_view_knowledge'; }

    public function firstStepSlug(): string { return 'write'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new AssignmentWriteStep(),
            new AssignmentAttachStep(),
            new AssignmentConfirmStep(),
        ];
    }
}
