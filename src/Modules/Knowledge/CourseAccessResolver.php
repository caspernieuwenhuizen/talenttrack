<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Shared\Content\ContentGate;
use TT\Shared\Content\GateVerdict;

/**
 * CourseAccessResolver — the four install gates, plus the two that are
 * about what the learner has done.
 *
 * `ContentGate` answers whether this install and this reader can have the
 * content at all. Two more gates only make sense for a course:
 *
 *   - **prerequisite** — the manifest's `requires:` list names courses that
 *     must be completed first.
 *   - **sequential** — with `sequential: true`, a lesson opens when the one
 *     before it is complete.
 *
 * Both are learning state rather than install state, which is why they are
 * here and not in `Shared`.
 *
 * Everything resolves in one pass. A ten-lesson course drawn with a gate
 * call per lesson would be ten progress queries for one page, and the
 * reader needs the whole picture anyway to draw its lesson list.
 */
final class CourseAccessResolver {

    /** A course named in `requires:` has not been completed. */
    public const REASON_PREREQUISITE = 'prerequisite_incomplete';

    /** The previous lesson in a sequential course is not finished. */
    public const REASON_SEQUENTIAL = 'previous_lesson_incomplete';

    private EnrolmentRepository $enrolments;

    private CourseCompletionService $completion;

    public function __construct(
        ?EnrolmentRepository $enrolments = null,
        ?CourseCompletionService $completion = null
    ) {
        $this->enrolments = $enrolments ?? new EnrolmentRepository();
        $this->completion = $completion ?? new CourseCompletionService();
    }

    /**
     * Can this person open this course at all?
     *
     * @param int|null $person_id `tt_people.id`, for the prerequisite check.
     * @param int|null $user_id   WordPress user, for the capability check.
     */
    public function forCourse( string $course_slug, ?int $person_id = null, ?int $user_id = null ): GateVerdict {
        $plan = $this->planVerdict();
        if ( $plan !== null ) return $plan;

        $manifest = CourseRegistry::get( $course_slug );
        if ( $manifest === null ) {
            return GateVerdict::unavailable( ContentGate::REASON_MODULE, [ 'course' => $course_slug ] );
        }

        $install = ContentGate::verdict( $manifest->toArray(), $user_id );
        if ( ! $install->isAvailable() ) {
            return $install;
        }

        return $this->prerequisiteVerdict( $manifest, $person_id );
    }

    /**
     * A verdict per lesson, in teaching order.
     *
     * When the course itself is out of reach, every lesson carries that
     * same verdict — a reader denied the course should not be told which
     * of its lessons they have not unlocked.
     *
     * @return array<string, GateVerdict> keyed by lesson slug
     */
    public function forLessons( string $course_slug, ?int $person_id = null, ?int $user_id = null ): array {
        $plan = $this->planVerdict();
        if ( $plan !== null ) {
            // Same shape the "course out of reach" branch below returns:
            // every lesson carries the course's verdict, so a reader is not
            // told which of the lessons they have not unlocked.
            $off_plan = CourseRegistry::get( $course_slug );
            return $off_plan === null ? [] : array_fill_keys( $off_plan->lessonSlugs(), $plan );
        }

        $manifest = CourseRegistry::get( $course_slug );
        if ( $manifest === null ) {
            return [];
        }

        $course = $this->forCourse( $course_slug, $person_id, $user_id );
        $slugs  = $manifest->lessonSlugs();

        if ( ! $course->isAvailable() ) {
            return array_fill_keys( $slugs, $course );
        }

        // Not sequential: the install gates already passed, so every
        // lesson is open and no progress lookup is needed.
        if ( ! $manifest->isSequential() ) {
            return array_fill_keys( $slugs, GateVerdict::available() );
        }

        $enrolment = $person_id !== null && $person_id > 0
            ? $this->enrolments->findFor( $person_id, $course_slug )
            : null;

        // One query for the whole course, not one per lesson.
        $states = $enrolment !== null
            ? $this->completion->lessonStates( (int) $enrolment->id, $course_slug )
            : [];

        $verdicts = [];
        $previous = null;

        foreach ( $slugs as $slug ) {
            if ( $previous === null || ( $states[ $previous ] ?? false ) ) {
                $verdicts[ $slug ] = GateVerdict::available();
            } else {
                $verdicts[ $slug ] = GateVerdict::locked( self::REASON_SEQUENTIAL, [ 'after' => $previous ] );
            }

            $previous = $slug;
        }

        return $verdicts;
    }

    /** One lesson's verdict. */
    public function forLesson( string $course_slug, string $lesson_slug, ?int $person_id = null, ?int $user_id = null ): GateVerdict {
        $verdicts = $this->forLessons( $course_slug, $person_id, $user_id );

        return $verdicts[ $lesson_slug ]
            ?? GateVerdict::unavailable( ContentGate::REASON_MODULE, [ 'lesson' => $lesson_slug ] );
    }

    /**
     * The courses this person should see listed.
     *
     * Unavailable and denied courses are absent. A course locked behind a
     * prerequisite stays listed — that is how a reader learns the path
     * exists and what opens it.
     *
     * @return array<string, CourseManifest>
     */
    public function listableCourses( ?int $person_id = null, ?int $user_id = null ): array {
        $out = [];

        foreach ( CourseRegistry::all() as $slug => $manifest ) {
            if ( $this->forCourse( $slug, $person_id, $user_id )->isListable() ) {
                $out[ $slug ] = $manifest;
            }
        }

        return $out;
    }

    /**
     * The plan's answer, or null when the plan has nothing to say (#3107).
     *
     * `knowledge_courses` gates **here**, at the resolver, rather than at a
     * view. This class is the module's existing chokepoint — it already
     * answers "may this person open this course" — so every consumer
     * inherits the plan check: the course list, a lesson page, the REST
     * routes, and any surface written later. A view-level check would have
     * left the routes open, which is the mistake the rest of this epic
     * exists to stop making.
     *
     * `locked`, not `unavailable`: a locked verdict stays **listable**, so a
     * Standard club sees the courses exist and what opens them. That is
     * #3017's "locked, not hidden" expressed in the vocabulary this module
     * already has, and it reuses `ContentGate::REASON_TIER` so a consumer
     * telling reasons apart needs no new case.
     */
    private function planVerdict(): ?GateVerdict {
        if ( \TT\Modules\License\LicenseGate::allows( 'knowledge_courses' ) ) return null;

        return GateVerdict::locked( ContentGate::REASON_TIER, [
            'required' => \TT\Modules\License\LicenseGate::requiredTierFor( 'knowledge_courses' ),
            'feature'  => 'knowledge_courses',
        ] );
    }

    /**
     * Prerequisites, resolved against one read of the person's enrolments.
     *
     * A `requires:` slug that is not a course in this corpus is not a gate.
     * The corpus lint already fails a PR for a dangling prerequisite, so at
     * runtime it can only mean a half-deployed install — and locking every
     * learner out of a course because a sibling course is missing is worse
     * than letting them in.
     */
    private function prerequisiteVerdict( CourseManifest $manifest, ?int $person_id ): GateVerdict {
        $required = $manifest->requires();
        if ( $required === [] ) {
            return GateVerdict::available();
        }

        $completed = $this->completedCourseSlugs( $person_id );

        foreach ( $required as $prerequisite ) {
            if ( CourseRegistry::get( $prerequisite ) === null ) {
                continue;
            }

            if ( ! isset( $completed[ $prerequisite ] ) ) {
                return GateVerdict::locked( self::REASON_PREREQUISITE, [ 'course' => $prerequisite ] );
            }
        }

        return GateVerdict::available();
    }

    /**
     * Course slugs this person has finished, as a lookup.
     *
     * @return array<string, true>
     */
    private function completedCourseSlugs( ?int $person_id ): array {
        if ( $person_id === null || $person_id <= 0 ) {
            return [];
        }

        $completed = [];
        foreach ( $this->enrolments->listForPerson( $person_id ) as $enrolment ) {
            if ( (string) $enrolment->status === EnrolmentRepository::STATUS_COMPLETED ) {
                $completed[ (string) $enrolment->course_slug ] = true;
            }
        }

        return $completed;
    }
}
