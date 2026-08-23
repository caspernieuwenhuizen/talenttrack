<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\ProgressRepository;
use TT\Modules\Knowledge\Repositories\SubmissionRepository;

/**
 * SubmissionService (#2648, epic #2641) — handing an assignment in, and the
 * verdict on it.
 *
 * The one place either transition happens. The REST controller, the lesson
 * form and the review queue all call in here, so the rules below hold
 * whichever surface a request arrives through, and a future non-WordPress
 * front end gets the same answers (CLAUDE.md §4).
 *
 * ## Approval is what completes an assignment, not submission
 *
 * `tt_course_progress.assignment_approved_at` is stamped on approval and
 * cleared on anything else, then `CourseCompletionService::recalculate()`
 * runs. That ordering matters in the unhappy direction: a coach whose
 * approved work is later reopened as changes-requested loses the lesson
 * and, if it was the last one, the course completion with it. Leaving the
 * stamp in place would leave a certificate standing on work a reviewer has
 * since withdrawn.
 *
 * ## What this service will not do
 *
 * It does not decide whether the reader may see the lesson — that is
 * `CourseAccessResolver`, and every caller passes through it first. It
 * does not send anything: the review queue is a state-derived alert
 * (`SubmissionsAwaitingReviewAlert`), which self-resolves when the queue
 * empties, rather than an event mail fired from in here.
 */
final class SubmissionService {

    /** Outcome of a call: what happened, and why not when it did not. */
    public const OK               = 'ok';
    public const ERR_NO_ASSIGNMENT = 'no_assignment';
    public const ERR_EMPTY         = 'empty_body';
    public const ERR_ALREADY       = 'already_submitted';
    public const ERR_NOT_FOUND     = 'not_found';
    public const ERR_BAD_OUTCOME   = 'bad_outcome';
    public const ERR_NO_FEEDBACK   = 'feedback_required';

    private SubmissionRepository $submissions;
    private EnrolmentRepository $enrolments;
    private ProgressRepository $progress;
    private CourseCompletionService $completion;

    public function __construct(
        ?SubmissionRepository $submissions = null,
        ?EnrolmentRepository $enrolments = null,
        ?ProgressRepository $progress = null,
        ?CourseCompletionService $completion = null
    ) {
        $this->submissions = $submissions ?? new SubmissionRepository();
        $this->enrolments  = $enrolments  ?? new EnrolmentRepository();
        $this->progress    = $progress    ?? new ProgressRepository();
        $this->completion  = $completion  ?? new CourseCompletionService();
    }

    /**
     * Hand in an assignment.
     *
     * @return array{status: string, id: int}
     */
    public function submit( int $enrolment_id, string $course_slug, string $lesson_slug, string $body ): array {
        $lesson = CourseRegistry::lesson( $course_slug, $lesson_slug );
        if ( $lesson === null || ! $lesson->hasAssignment() ) {
            return [ 'status' => self::ERR_NO_ASSIGNMENT, 'id' => 0 ];
        }

        if ( trim( wp_strip_all_tags( $body ) ) === '' ) {
            return [ 'status' => self::ERR_EMPTY, 'id' => 0 ];
        }

        // A submission already awaiting a verdict cannot be replaced. The
        // resubmit path is opened by a reviewer asking for changes, not by
        // the coach deciding their first attempt was better — otherwise a
        // reviewer reads one version and rules on another.
        $latest = $this->submissions->latestFor( $enrolment_id, $lesson_slug );
        if ( $latest !== null && ! $this->isResubmittable( $latest ) ) {
            return [ 'status' => self::ERR_ALREADY, 'id' => (int) $latest->id ];
        }

        $id = $this->submissions->submit(
            $enrolment_id,
            $lesson_slug,
            $this->assignmentKeyFor( $lesson ),
            $body
        );

        if ( $id <= 0 ) return [ 'status' => self::ERR_NOT_FOUND, 'id' => 0 ];

        $enrolment = $this->enrolments->find( $enrolment_id );
        $author    = $enrolment !== null ? (int) $enrolment->person_id : 0;
        $reviewer  = ReviewerResolver::forSubmitter( $author );
        if ( $reviewer > 0 ) {
            $this->submissions->assignReviewer( $id, $reviewer );
        }

        return [ 'status' => self::OK, 'id' => $id ];
    }

    /**
     * Open or resume the wizard's draft for a lesson.
     *
     * Resumed rather than recreated, so a coach who backs out of the wizard
     * and starts it again keeps the answer and the documents they had
     * already attached instead of stranding both on a row nobody will look
     * at.
     *
     * @return array{status: string, id: int}
     */
    public function startDraft( int $enrolment_id, string $course_slug, string $lesson_slug ): array {
        $lesson = CourseRegistry::lesson( $course_slug, $lesson_slug );
        if ( $lesson === null || ! $lesson->hasAssignment() ) {
            return [ 'status' => self::ERR_NO_ASSIGNMENT, 'id' => 0 ];
        }

        $latest = $this->submissions->latestFor( $enrolment_id, $lesson_slug );
        if ( $latest !== null && ! $this->isResubmittable( $latest ) ) {
            return [ 'status' => self::ERR_ALREADY, 'id' => (int) $latest->id ];
        }

        $draft = $this->submissions->draftFor( $enrolment_id, $lesson_slug );
        if ( $draft !== null ) {
            return [ 'status' => self::OK, 'id' => (int) $draft->id ];
        }

        $id = $this->submissions->createDraft(
            $enrolment_id,
            $lesson_slug,
            $this->assignmentKeyFor( $lesson ),
            ''
        );

        return $id > 0
            ? [ 'status' => self::OK, 'id' => $id ]
            : [ 'status' => self::ERR_NOT_FOUND, 'id' => 0 ];
    }

    /** Save the wizard's written answer onto its draft. */
    public function saveDraft( int $draft_id, string $body ): array {
        if ( trim( wp_strip_all_tags( $body ) ) === '' ) {
            return [ 'status' => self::ERR_EMPTY, 'id' => $draft_id ];
        }

        return $this->submissions->updateDraft( $draft_id, $body )
            ? [ 'status' => self::OK, 'id' => $draft_id ]
            : [ 'status' => self::ERR_NOT_FOUND, 'id' => $draft_id ];
    }

    /**
     * Hand the wizard's draft in — the point at which it becomes a
     * submission and enters somebody's queue.
     *
     * The reviewer is resolved here rather than when the draft was opened,
     * because a draft can sit for a week and the mentorship that should
     * route it is the one in force when the work arrives.
     *
     * @return array{status: string, id: int}
     */
    public function handInDraft( int $draft_id ): array {
        $draft = $this->submissions->find( $draft_id );
        if ( $draft === null || $draft->submitted_at !== null ) {
            return [ 'status' => self::ERR_NOT_FOUND, 'id' => $draft_id ];
        }

        if ( trim( wp_strip_all_tags( (string) ( $draft->body ?? '' ) ) ) === '' ) {
            return [ 'status' => self::ERR_EMPTY, 'id' => $draft_id ];
        }

        if ( ! $this->submissions->handIn( $draft_id ) ) {
            return [ 'status' => self::ERR_NOT_FOUND, 'id' => $draft_id ];
        }

        $enrolment = $this->enrolments->find( (int) $draft->enrolment_id );
        $reviewer  = ReviewerResolver::forSubmitter(
            $enrolment !== null ? (int) $enrolment->person_id : 0
        );
        if ( $reviewer > 0 ) {
            $this->submissions->assignReviewer( $draft_id, $reviewer );
        }

        return [ 'status' => self::OK, 'id' => $draft_id ];
    }

    /**
     * Record a verdict and move the learner's progress with it.
     *
     * @return array{status: string, completed: bool}
     */
    public function review( int $submission_id, string $outcome, string $feedback, int $reviewer_person_id ): array {
        $submission = $this->submissions->find( $submission_id );
        if ( $submission === null ) {
            return [ 'status' => self::ERR_NOT_FOUND, 'completed' => false ];
        }

        if ( ! in_array( $outcome, SubmissionRepository::outcomes(), true ) ) {
            return [ 'status' => self::ERR_BAD_OUTCOME, 'completed' => false ];
        }

        if ( $outcome !== SubmissionRepository::OUTCOME_APPROVED && trim( $feedback ) === '' ) {
            return [ 'status' => self::ERR_NO_FEEDBACK, 'completed' => false ];
        }

        if ( ! $this->submissions->review( $submission_id, $outcome, $feedback, $reviewer_person_id ) ) {
            return [ 'status' => self::ERR_NOT_FOUND, 'completed' => false ];
        }

        $enrolment_id = (int) $submission->enrolment_id;
        $lesson_slug  = (string) $submission->lesson_slug;

        $this->progress->setAssignmentApproved(
            $enrolment_id,
            $lesson_slug,
            $outcome === SubmissionRepository::OUTCOME_APPROVED
        );

        // Recalculate either way. Approval can complete the course; a
        // withdrawn approval can un-complete it, and `recalculate()` fires
        // the reopened hook on that transition.
        $status = $this->completion->recalculate( $enrolment_id );

        return [
            'status'    => self::OK,
            'completed' => $status === EnrolmentRepository::STATUS_COMPLETED,
        ];
    }

    /**
     * Can the coach hand this lesson in again?
     *
     * Only after changes were requested. Approved work is finished, a
     * pending submission is mid-review, and a rejection is a verdict rather
     * than an invitation — reopening a rejected assignment is a reviewer's
     * decision, made by asking for changes instead.
     */
    public function isResubmittable( ?object $submission ): bool {
        if ( $submission === null ) return true;

        return (string) $submission->outcome === SubmissionRepository::OUTCOME_CHANGES;
    }

    /** True when this lesson is waiting on the learner to act. */
    public function awaitsLearner( ?object $submission ): bool {
        return $this->isResubmittable( $submission );
    }

    /**
     * The `id` from the lesson's `tt-assignment` block, which scopes a
     * submission to one assignment within a lesson.
     *
     * Falls back to the lesson slug. A lesson with an assignment but no
     * declared id still stores something stable, and `course-lint` is what
     * stops that combination reaching a release in the first place.
     */
    private function assignmentKeyFor( CourseLesson $lesson ): string {
        $key = $lesson->assignmentKey();
        return $key !== '' ? $key : $lesson->slug();
    }
}
