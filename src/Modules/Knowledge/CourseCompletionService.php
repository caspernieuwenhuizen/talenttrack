<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\ProgressRepository;

/**
 * CourseCompletionService — what "done" means, in one place.
 *
 * A lesson is complete when every requirement its front matter declares has
 * been met: read it always, pass the quiz when `quiz: true`, get the
 * assignment approved when `assignment: true`. A course is complete when
 * every lesson its manifest declares is complete.
 *
 * This lives in a service rather than in a view or a controller because
 * three surfaces need the same answer and must not disagree: the reader
 * drawing a lesson list, the sequential gate deciding what opens (#2645),
 * and the statistics report counting cohorts (#2650). It is also the §4
 * test — delete every view file and the REST API still answers correctly.
 *
 * Requirements are read from the corpus on every call, not cached against
 * the enrolment. A course revision that adds a lesson must reopen the
 * people who had finished the old version, rather than leaving them
 * certified for a course they have not done.
 */
final class CourseCompletionService {

    private EnrolmentRepository $enrolments;
    private ProgressRepository $progress;

    public function __construct( ?EnrolmentRepository $enrolments = null, ?ProgressRepository $progress = null ) {
        $this->enrolments = $enrolments ?? new EnrolmentRepository();
        $this->progress   = $progress ?? new ProgressRepository();
    }

    /**
     * Is this lesson finished?
     *
     * An unknown lesson is not complete. Returning true would let a
     * mistyped slug in a manifest mark a course finished.
     */
    public function isLessonComplete( string $course_slug, string $lesson_slug, ?object $progress_row ): bool {
        $lesson = CourseRegistry::lesson( $course_slug, $lesson_slug );
        if ( $lesson === null || $progress_row === null ) {
            return false;
        }

        if ( $progress_row->read_at === null ) {
            return false;
        }

        if ( $lesson->hasQuiz() && $progress_row->quiz_passed_at === null ) {
            return false;
        }

        if ( $lesson->hasAssignment() && $progress_row->assignment_approved_at === null ) {
            return false;
        }

        return true;
    }

    /**
     * Per-lesson completion for an enrolment, in manifest order.
     *
     * One progress query for the whole course, not one per lesson: a
     * ten-lesson course drawn with an N+1 would be ten round trips for a
     * page that renders one list.
     *
     * @return array<string, bool> keyed by lesson slug, in teaching order
     */
    public function lessonStates( int $enrolment_id, string $course_slug ): array {
        $manifest = CourseRegistry::get( $course_slug );
        if ( $manifest === null ) {
            return [];
        }

        $rows   = $this->progress->forEnrolment( $enrolment_id );
        $states = [];

        foreach ( $manifest->lessonSlugs() as $lesson_slug ) {
            $states[ $lesson_slug ] = $this->isLessonComplete(
                $course_slug,
                $lesson_slug,
                $rows[ $lesson_slug ] ?? null
            );
        }

        return $states;
    }

    /**
     * How far through the course this enrolment is.
     *
     * `percent` is rounded down, so a course is never shown at 100% until
     * it genuinely is — nine of ten lessons on a ten-lesson course reads
     * 90%, and rounding that up would be a lie the certification would
     * then act on.
     *
     * @return array{completed: int, total: int, percent: int}
     */
    public function progressFor( int $enrolment_id, string $course_slug ): array {
        $states    = $this->lessonStates( $enrolment_id, $course_slug );
        $total     = count( $states );
        $completed = count( array_filter( $states ) );

        return [
            'completed' => $completed,
            'total'     => $total,
            'percent'   => $total > 0 ? (int) floor( ( $completed / $total ) * 100 ) : 0,
        ];
    }

    /**
     * The next lesson to open — the first incomplete one.
     *
     * What "resume" means. Opening a course at lesson 1 when someone is
     * six lessons in is the small rudeness that makes people stop
     * returning to a course.
     *
     * Null when the course is finished or does not resolve.
     */
    public function nextLesson( int $enrolment_id, string $course_slug ): ?string {
        foreach ( $this->lessonStates( $enrolment_id, $course_slug ) as $lesson_slug => $complete ) {
            if ( ! $complete ) {
                return $lesson_slug;
            }
        }

        return null;
    }

    /**
     * Recount an enrolment and move its status if the count says so.
     *
     * Called after anything that could change completion: a lesson marked
     * read, a quiz passed, a review recorded. Moves in both directions —
     * a reviewer who withdraws an approval, or a course revision that adds
     * a lesson, has to be able to reopen a finished enrolment.
     *
     * Returns the status the enrolment now holds.
     */
    public function recalculate( int $enrolment_id ): string {
        $enrolment = $this->enrolments->find( $enrolment_id );
        if ( $enrolment === null ) {
            return EnrolmentRepository::STATUS_NOT_STARTED;
        }

        $course_slug = (string) $enrolment->course_slug;

        // A course withdrawn from the corpus leaves its enrolments alone.
        // Recounting against a manifest that no longer exists would reopen
        // every completion the moment a course is retired.
        if ( CourseRegistry::get( $course_slug ) === null ) {
            return (string) $enrolment->status;
        }

        $was_complete = $enrolment->status === EnrolmentRepository::STATUS_COMPLETED;
        $progress     = $this->progressFor( $enrolment_id, $course_slug );
        $finished     = $progress['total'] > 0 && $progress['completed'] === $progress['total'];

        // The hooks fire on the transition, never on the state. Recalculate
        // runs after every marked lesson, so firing on "is complete" would
        // write a certification on each of them.
        if ( $finished ) {
            if ( ! $was_complete ) {
                $this->enrolments->markCompleted( $enrolment_id );

                /**
                 * Fires once, when an enrolment reaches completion.
                 *
                 * The hook the certification bridge and the methodology
                 * binding hang off (#2649), so this service does not have to
                 * know what happens next.
                 *
                 * @param int    $enrolment_id
                 * @param string $course_slug
                 * @param int    $person_id
                 */
                do_action( 'tt_knowledge_course_completed', $enrolment_id, $course_slug, (int) $enrolment->person_id );
            }

            return EnrolmentRepository::STATUS_COMPLETED;
        }

        if ( $was_complete ) {
            $this->enrolments->reopen( $enrolment_id );

            /**
             * Fires once, when a completed enrolment is no longer complete.
             *
             * @param int    $enrolment_id
             * @param string $course_slug
             * @param int    $person_id
             */
            do_action( 'tt_knowledge_course_reopened', $enrolment_id, $course_slug, (int) $enrolment->person_id );
        }

        return EnrolmentRepository::STATUS_IN_PROGRESS;
    }
}
