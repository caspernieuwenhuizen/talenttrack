<?php
namespace TT\Modules\Knowledge\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Knowledge\CourseAccessResolver;
use TT\Modules\Knowledge\CourseCompletionService;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\LessonRenderer;
use TT\Modules\Knowledge\Quiz\QuizPayload;
use TT\Modules\Knowledge\Quiz\QuizScorer;
use TT\Modules\Knowledge\Quiz\QuizSubmission;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\ProgressRepository;
use TT\Modules\Knowledge\Repositories\QuizAttemptRepository;
use TT\Modules\Knowledge\Repositories\SubmissionRepository;
use TT\Modules\Knowledge\ReviewerResolver;
use TT\Modules\Knowledge\SubmissionAttachments;
use TT\Modules\Knowledge\SubmissionService;
use WP_REST_Request;

/**
 * REST surface for the knowledge library (#2644, epic #2641).
 *
 * Resource-oriented under `talenttrack/v1`:
 *
 *   GET    /courses                                   catalogue + this user's state
 *   GET    /courses/{slug}                            manifest + per-lesson state
 *   POST   /courses/{slug}/enrolments                 enrol self, or assign
 *   PATCH  /courses/{slug}/progress/{lesson}          mark read, persist tool state
 *   DELETE /enrolments/{id}                           withdraw
 *   GET    /people/{id}/learning                      one person's record
 *
 * Lesson bodies are not here. The reader (#2646) adds
 * `/courses/{slug}/lessons/{lesson}` once the gate (#2645) can decide
 * whether a given lesson is open — serving bodies before the gate exists
 * would be shipping the unlocked version of a sequential course.
 *
 * Every route declares a `permission_callback` resolved through
 * capabilities, never a role-string compare and never `__return_true`
 * (CLAUDE.md §4). Reads about another person are gated on the statistics
 * capability rather than the view capability, so a coach can see their own
 * record without seeing the rest of the staff.
 */
final class KnowledgeRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/courses', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'list_courses' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );

        register_rest_route( self::NS, '/courses/(?P<slug>[a-z0-9-]+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_course' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );

        register_rest_route( self::NS, '/courses/(?P<slug>[a-z0-9-]+)/lessons/(?P<lesson>[a-z0-9-]+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_lesson' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );

        register_rest_route( self::NS, '/courses/(?P<slug>[a-z0-9-]+)/quiz/(?P<lesson>[a-z0-9-]+)', [
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'submit_quiz' ],  'permission_callback' => [ __CLASS__, 'can_view' ] ],
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'quiz_attempts' ], 'permission_callback' => [ __CLASS__, 'can_view' ] ],
        ] );

        register_rest_route( self::NS, '/courses/(?P<slug>[a-z0-9-]+)/enrolments', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'enrol' ],
            'permission_callback' => [ __CLASS__, 'can_enrol_target' ],
        ] );

        register_rest_route( self::NS, '/courses/(?P<slug>[a-z0-9-]+)/progress/(?P<lesson>[a-z0-9-]+)', [
            'methods'             => 'PATCH',
            'callback'            => [ __CLASS__, 'update_progress' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );

        register_rest_route( self::NS, '/enrolments/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ __CLASS__, 'withdraw' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( self::NS, '/people/(?P<id>\d+)/learning', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'person_learning' ],
            'permission_callback' => [ __CLASS__, 'can_view_person' ],
        ] );

        // #2648 — assignments and their review. Resource-oriented: a
        // submission is created under the lesson it answers, and the
        // verdict is a PATCH on the submission rather than an
        // `/approve` verb (CLAUDE.md §4).
        register_rest_route( self::NS, '/courses/(?P<slug>[a-z0-9-]+)/submissions/(?P<lesson>[a-z0-9-]+)', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'submit_assignment' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );

        register_rest_route( self::NS, '/submissions', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'pending_submissions' ],
            'permission_callback' => [ __CLASS__, 'can_review' ],
        ] );

        register_rest_route( self::NS, '/submissions/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [ __CLASS__, 'review_submission' ],
            'permission_callback' => [ __CLASS__, 'can_review' ],
        ] );
    }

    /* ===== permission gates ===== */

    public static function can_view(): bool {
        return current_user_can( 'tt_view_knowledge' );
    }

    public static function can_manage(): bool {
        return current_user_can( 'tt_manage_knowledge' );
    }

    /**
     * The coarse gate on the review routes (#2648).
     *
     * `can_manage()` would be wrong: work is routed to mentors, who hold
     * no management capability, and gating on one would lock them out of
     * the queue their own mentees' coursework lands in. This asks whether
     * the caller is a reviewer of anybody; whether they may rule on a
     * *particular* submission is re-checked per row in
     * `review_submission()`, because that is not a question a
     * `permission_callback` can answer before it has seen the id.
     */
    public static function can_review(): bool {
        $user_id = get_current_user_id();

        return self::can_view()
            && ReviewerResolver::isReviewer( $user_id, KnowledgePerson::forUser( $user_id ) );
    }

    /**
     * Reading someone else's record needs the statistics capability;
     * reading your own needs only the view capability.
     */
    public static function can_view_person( WP_REST_Request $r ): bool {
        if ( current_user_can( 'tt_view_knowledge_statistics' ) ) {
            return true;
        }

        return self::can_view() && self::isSelf( (int) $r['id'] );
    }

    /**
     * Enrolling yourself needs the view capability. Enrolling anyone else
     * is an assignment, and needs the manage capability.
     */
    public static function can_enrol_target( WP_REST_Request $r ): bool {
        $person_id = (int) $r->get_param( 'person_id' );

        if ( $person_id <= 0 || self::isSelf( $person_id ) ) {
            return self::can_view();
        }

        return self::can_manage();
    }

    /* ===== routes ===== */

    /**
     * The catalogue, each entry carrying this user's state and its gate
     * verdict.
     *
     * Courses this install does not have, and courses this reader may not
     * open, are absent rather than listed-and-locked: listing them is
     * advertising. A course locked behind a prerequisite stays listed with
     * its verdict, because that is how a reader learns the path exists.
     */
    public static function list_courses( WP_REST_Request $r ) {
        $person_id = self::currentPersonId();
        $service   = new CourseCompletionService();
        $repo      = new EnrolmentRepository();
        $access    = new CourseAccessResolver();

        $out = [];
        foreach ( CourseRegistry::all() as $slug => $manifest ) {
            $verdict = $access->forCourse( $slug, $person_id, get_current_user_id() );
            if ( ! $verdict->isListable() ) {
                continue;
            }

            $enrolment = $person_id > 0 ? $repo->findFor( $person_id, $slug ) : null;

            $out[] = [
                'course'    => $manifest->toArray(),
                'access'    => $verdict->toArray(),
                'enrolment' => self::shapeEnrolment( $enrolment ),
                'progress'  => $enrolment !== null
                    ? $service->progressFor( (int) $enrolment->id, $slug )
                    : [ 'completed' => 0, 'total' => count( $manifest->lessonSlugs() ), 'percent' => 0 ],
            ];
        }

        return RestResponse::success( $out );
    }

    public static function get_course( WP_REST_Request $r ) {
        $slug     = (string) $r['slug'];
        $manifest = CourseRegistry::get( $slug );

        if ( $manifest === null ) {
            return RestResponse::notFound( 'course_not_found', __( 'That course is not in this library.', 'talenttrack' ) );
        }

        $person_id = self::currentPersonId();
        $access    = new CourseAccessResolver();
        $verdict   = $access->forCourse( $slug, $person_id, get_current_user_id() );

        // Absent, not forbidden. A 403 confirms the course exists here,
        // which is the one thing hiding it was meant to avoid.
        if ( ! $verdict->isListable() ) {
            return RestResponse::notFound( 'course_not_found', __( 'That course is not in this library.', 'talenttrack' ) );
        }

        $repo      = new EnrolmentRepository();
        $enrolment = $person_id > 0 ? $repo->findFor( $person_id, $slug ) : null;
        $service   = new CourseCompletionService();

        $states       = $enrolment !== null ? $service->lessonStates( (int) $enrolment->id, $slug ) : [];
        $lesson_gates = $access->forLessons( $slug, $person_id, get_current_user_id() );

        $lessons = [];
        foreach ( CourseRegistry::lessons( $slug ) as $lesson_slug => $lesson ) {
            $lessons[] = $lesson->toArray() + [
                'complete' => $states[ $lesson_slug ] ?? false,
                'access'   => isset( $lesson_gates[ $lesson_slug ] )
                    ? $lesson_gates[ $lesson_slug ]->toArray()
                    : null,
            ];
        }

        return RestResponse::success( [
            'course'      => $manifest->toArray(),
            'access'      => $verdict->toArray(),
            'lessons'     => $lessons,
            'enrolment'   => self::shapeEnrolment( $enrolment ),
            'progress'    => $enrolment !== null
                ? $service->progressFor( (int) $enrolment->id, $slug )
                : [ 'completed' => 0, 'total' => count( $manifest->lessonSlugs() ), 'percent' => 0 ],
            'next_lesson' => $enrolment !== null ? $service->nextLesson( (int) $enrolment->id, $slug ) : null,
        ] );
    }

    /**
     * One lesson: its rendered body, its state and its gate verdict.
     *
     * The §4 test — a non-WordPress front end asking for this gets the
     * same HTML the rendered page shows, because both go through
     * `LessonRenderer`.
     *
     * A locked lesson returns 403 with the verdict rather than the body.
     * Locked is not absent: the reader is allowed to know the lesson
     * exists and what opens it, which is why this is not a 404 the way an
     * unavailable course is.
     */
    public static function get_lesson( WP_REST_Request $r ) {
        $slug   = (string) $r['slug'];
        $lesson = (string) $r['lesson'];

        $entry     = CourseRegistry::lesson( $slug, $lesson );
        $person_id = self::currentPersonId();
        $verdict   = ( new CourseAccessResolver() )->forLesson( $slug, $lesson, $person_id, get_current_user_id() );

        if ( $entry === null || $verdict->isUnavailable() || $verdict->isDenied() ) {
            return RestResponse::notFound( 'lesson_not_found', __( 'That lesson is not part of this course.', 'talenttrack' ) );
        }

        if ( $verdict->isLocked() ) {
            return RestResponse::error(
                'lesson_locked',
                __( 'Finish the previous lesson first.', 'talenttrack' ),
                403,
                $verdict->toArray()
            );
        }

        $rendered = LessonRenderer::render( $entry->body() );

        $enrolment = $person_id > 0
            ? ( new EnrolmentRepository() )->findFor( $person_id, $slug )
            : null;

        $progress = new ProgressRepository();
        $row      = $enrolment !== null ? $progress->find( (int) $enrolment->id, $lesson ) : null;

        return RestResponse::success( [
            'lesson'      => $entry->toArray(),
            'html'        => $rendered['html'],
            'interactive' => $rendered['interactive'],
            'access'      => $verdict->toArray(),
            'read_at'     => $row->read_at ?? null,
            'tool_state'  => $progress->toolState( $row ),
        ] );
    }

    /**
     * Mark a quiz attempt.
     *
     * Scoring is here rather than in the browser because the payload
     * carries the answer key: a client-side scorer would have to be handed
     * the answers to do its job.
     *
     * Every attempt is recorded, passed or not. A coach who passed on the
     * fourth try has a different development record than one who passed
     * first time, and that is what a head of academy reading the record
     * wants to see.
     */
    public static function submit_quiz( WP_REST_Request $r ) {
        $slug   = (string) $r['slug'];
        $lesson = (string) $r['lesson'];

        $gate = self::lessonGate( $slug, $lesson );
        if ( $gate !== null ) {
            return $gate;
        }

        $payload = QuizPayload::forLesson( $slug, $lesson );
        if ( $payload === null || $payload->count() === 0 ) {
            return RestResponse::notFound( 'quiz_not_found', __( 'This lesson has no check.', 'talenttrack' ) );
        }

        $person_id = self::currentPersonId();
        if ( $person_id <= 0 ) {
            return RestResponse::error(
                'no_person_record',
                __( 'This login is not linked to a staff record.', 'talenttrack' ),
                400
            );
        }

        $raw = $r->get_param( 'q' );
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }

        $submitted = QuizSubmission::normalise( $payload, $raw );
        $result    = QuizScorer::score( $payload, $submitted );

        $enrolments = new EnrolmentRepository();
        $enrolment  = $enrolments->findFor( $person_id, $slug );
        if ( $enrolment === null ) {
            $enrolments->enrol( $person_id, $slug );
            $enrolment = $enrolments->findFor( $person_id, $slug );
        }

        if ( $enrolment === null ) {
            return RestResponse::error( 'enrolment_failed', __( 'Could not record the attempt.', 'talenttrack' ), 400 );
        }

        $enrolment_id = (int) $enrolment->id;

        ( new QuizAttemptRepository() )->record(
            $enrolment_id,
            $lesson,
            $submitted,
            $result['score'],
            $result['max'],
            $result['passed']
        );

        $service = new CourseCompletionService();

        if ( $result['passed'] ) {
            ( new ProgressRepository() )->markQuizPassed( $enrolment_id, $lesson );
            $service->recalculate( $enrolment_id );
        }

        return RestResponse::success( $result + [
            'attempts'    => ( new QuizAttemptRepository() )->countFor( $enrolment_id, $lesson ),
            'progress'    => $service->progressFor( $enrolment_id, $slug ),
            'next_lesson' => $service->nextLesson( $enrolment_id, $slug ),
        ] );
    }

    /**
     * This reader's attempts at one lesson's quiz.
     *
     * Their own only — a coach's attempt history is part of their
     * development record, and the roll-up that shows anyone else's is
     * #2650 behind its own capability.
     */
    public static function quiz_attempts( WP_REST_Request $r ) {
        $slug   = (string) $r['slug'];
        $lesson = (string) $r['lesson'];

        $gate = self::lessonGate( $slug, $lesson );
        if ( $gate !== null ) {
            return $gate;
        }

        $person_id = self::currentPersonId();
        $enrolment = $person_id > 0
            ? ( new EnrolmentRepository() )->findFor( $person_id, $slug )
            : null;

        if ( $enrolment === null ) {
            return RestResponse::success( [ 'attempts' => [] ] );
        }

        $attempts = [];
        foreach ( ( new QuizAttemptRepository() )->listFor( (int) $enrolment->id, $lesson ) as $attempt ) {
            // The stored answers are not returned: they are the reader's
            // own working, and echoing them back adds nothing the score
            // does not already say.
            $attempts[] = [
                'score'      => (int) $attempt->score,
                'max_score'  => (int) $attempt->max_score,
                'passed'     => (bool) $attempt->passed,
                'created_at' => $attempt->created_at,
            ];
        }

        return RestResponse::success( [ 'attempts' => $attempts ] );
    }

    /**
     * Shared gate for the two quiz routes: the lesson must exist and be
     * open to this reader. Returns null when it is, or the response to
     * send when it is not.
     *
     * @return \WP_REST_Response|null
     */
    private static function lessonGate( string $slug, string $lesson ) {
        if ( CourseRegistry::lesson( $slug, $lesson ) === null ) {
            return RestResponse::notFound( 'lesson_not_found', __( 'That lesson is not part of this course.', 'talenttrack' ) );
        }

        $verdict = ( new CourseAccessResolver() )->forLesson(
            $slug,
            $lesson,
            self::currentPersonId(),
            get_current_user_id()
        );

        if ( $verdict->isUnavailable() || $verdict->isDenied() ) {
            return RestResponse::notFound( 'lesson_not_found', __( 'That lesson is not part of this course.', 'talenttrack' ) );
        }

        if ( $verdict->isLocked() ) {
            return RestResponse::error(
                'lesson_locked',
                __( 'Finish the previous lesson first.', 'talenttrack' ),
                403,
                $verdict->toArray()
            );
        }

        return null;
    }

    public static function enrol( WP_REST_Request $r ) {
        $slug = (string) $r['slug'];

        if ( CourseRegistry::get( $slug ) === null ) {
            return RestResponse::notFound( 'course_not_found', __( 'That course is not in this library.', 'talenttrack' ) );
        }

        $person_id = (int) $r->get_param( 'person_id' );
        if ( $person_id <= 0 ) {
            $person_id = self::currentPersonId();
        }

        if ( $person_id <= 0 ) {
            return RestResponse::error(
                'no_person_record',
                __( 'This login is not linked to a staff record, so it cannot be enrolled.', 'talenttrack' ),
                400
            );
        }

        $repo = new EnrolmentRepository();
        $id   = $repo->enrol( $person_id, $slug, [
            'assigned_by' => self::isSelf( $person_id ) ? 0 : self::currentPersonId(),
            'due_at'      => $r->get_param( 'due_at' ),
        ] );

        if ( $id <= 0 ) {
            return RestResponse::error( 'enrolment_failed', __( 'Could not enrol on that course.', 'talenttrack' ), 400 );
        }

        return RestResponse::success( self::shapeEnrolment( $repo->find( $id ) ), 201 );
    }

    /**
     * Mark a lesson read, and/or persist its interactive-block state.
     *
     * PATCH rather than PUT: the body carries whichever of the two the
     * reader is reporting, not a whole progress record.
     */
    public static function update_progress( WP_REST_Request $r ) {
        $slug   = (string) $r['slug'];
        $lesson = (string) $r['lesson'];

        if ( CourseRegistry::lesson( $slug, $lesson ) === null ) {
            return RestResponse::notFound( 'lesson_not_found', __( 'That lesson is not part of this course.', 'talenttrack' ) );
        }

        $person_id = self::currentPersonId();
        if ( $person_id <= 0 ) {
            return RestResponse::error(
                'no_person_record',
                __( 'This login is not linked to a staff record.', 'talenttrack' ),
                400
            );
        }

        // The write path is where a sequential course would actually be
        // walked around: hiding a locked lesson in the reader means nothing
        // if POSTing its progress marks it read. Enforced here, not only in
        // the view.
        $verdict = ( new CourseAccessResolver() )->forLesson( $slug, $lesson, $person_id, get_current_user_id() );

        if ( $verdict->isUnavailable() || $verdict->isDenied() ) {
            return RestResponse::notFound( 'lesson_not_found', __( 'That lesson is not part of this course.', 'talenttrack' ) );
        }

        if ( $verdict->isLocked() ) {
            return RestResponse::error(
                'lesson_locked',
                __( 'Finish the previous lesson first.', 'talenttrack' ),
                403,
                $verdict->toArray()
            );
        }

        $enrolments = new EnrolmentRepository();
        $enrolment  = $enrolments->findFor( $person_id, $slug );

        // Opening a lesson is the act that starts a course. Enrolling
        // separately first would be a step nobody would understand.
        if ( $enrolment === null ) {
            $enrolments->enrol( $person_id, $slug );
            $enrolment = $enrolments->findFor( $person_id, $slug );
        }

        if ( $enrolment === null ) {
            return RestResponse::error( 'enrolment_failed', __( 'Could not record progress.', 'talenttrack' ), 400 );
        }

        $enrolment_id = (int) $enrolment->id;
        $enrolments->markStarted( $enrolment_id );

        $progress = new ProgressRepository();

        if ( (bool) $r->get_param( 'read' ) ) {
            $progress->markRead( $enrolment_id, $lesson );
        }

        $tool_state = $r->get_param( 'tool_state' );
        if ( is_array( $tool_state ) && $tool_state !== [] ) {
            $progress->saveToolState( $enrolment_id, $lesson, $tool_state );
        }

        $service = new CourseCompletionService();
        $status  = $service->recalculate( $enrolment_id );

        $row = $progress->find( $enrolment_id, $lesson );

        return RestResponse::success( [
            'status'      => $status,
            'progress'    => $service->progressFor( $enrolment_id, $slug ),
            'next_lesson' => $service->nextLesson( $enrolment_id, $slug ),
            'lesson'      => [
                'slug'       => $lesson,
                'read_at'    => $row->read_at ?? null,
                'complete'   => $service->isLessonComplete( $slug, $lesson, $row ),
                'tool_state' => $progress->toolState( $row ),
            ],
        ] );
    }

    public static function withdraw( WP_REST_Request $r ) {
        $repo = new EnrolmentRepository();

        if ( ! $repo->withdraw( (int) $r['id'] ) ) {
            return RestResponse::notFound( 'enrolment_not_found', __( 'That enrolment does not exist.', 'talenttrack' ) );
        }

        return RestResponse::success( [ 'withdrawn' => true ] );
    }

    public static function person_learning( WP_REST_Request $r ) {
        $person_id = (int) $r['id'];
        $repo      = new EnrolmentRepository();
        $service   = new CourseCompletionService();

        $out = [];
        foreach ( $repo->listForPerson( $person_id ) as $enrolment ) {
            $slug     = (string) $enrolment->course_slug;
            $manifest = CourseRegistry::get( $slug );

            $out[] = [
                'enrolment' => self::shapeEnrolment( $enrolment ),
                // A course withdrawn from the corpus still shows on the
                // person's record; it is retired, not erased.
                'course'    => $manifest !== null ? $manifest->toArray() : null,
                'retired'   => $manifest === null,
                'progress'  => $manifest !== null
                    ? $service->progressFor( (int) $enrolment->id, $slug )
                    : null,
            ];
        }

        return RestResponse::success( $out );
    }

    /* ===== helpers ===== */

    /**
     * The payload shape for an enrolment.
     *
     * `uuid` is the identity a consumer should hold; the numeric id is an
     * implementation detail of this database and is exposed only because
     * the withdraw route still addresses it.
     *
     * @return array<string, mixed>|null
     */
    private static function shapeEnrolment( ?object $enrolment ): ?array {
        if ( $enrolment === null ) {
            return null;
        }

        return [
            'id'           => (int) $enrolment->id,
            'uuid'         => (string) $enrolment->uuid,
            'course_slug'  => (string) $enrolment->course_slug,
            'person_id'    => (int) $enrolment->person_id,
            'status'       => (string) $enrolment->status,
            'due_at'       => $enrolment->due_at,
            'started_at'   => $enrolment->started_at,
            'completed_at' => $enrolment->completed_at,
        ];
    }

    /**
     * The `tt_people` row for the logged-in user, or 0.
     *
     * #2646 moved the lookup into `KnowledgePerson` so the three views and
     * this controller share one implementation — and one answer about
     * whether an archived person still counts.
     */
    private static function currentPersonId(): int {
        return KnowledgePerson::current();
    }

    private static function isSelf( int $person_id ): bool {
        return $person_id > 0 && $person_id === self::currentPersonId();
    }

    /* ===== assignments and review (#2648) ===== */

    /**
     * Hand in an assignment.
     *
     * The one-shot path: body in, submission out. The wizard's draft flow
     * is a frontend affordance built on the same service, not a second API
     * — a consumer with a written answer has no reason to make three calls
     * to deliver it.
     *
     * @return \WP_REST_Response
     */
    public static function submit_assignment( WP_REST_Request $r ) {
        $slug   = (string) $r['slug'];
        $lesson = (string) $r['lesson'];

        $lesson_obj = CourseRegistry::lesson( $slug, $lesson );
        if ( $lesson_obj === null ) {
            return RestResponse::notFound( 'lesson_not_found', __( 'That lesson is not part of this course.', 'talenttrack' ) );
        }

        $person_id = self::currentPersonId();
        if ( $person_id <= 0 ) {
            return RestResponse::error(
                'no_person_record',
                __( 'This login is not linked to a staff record.', 'talenttrack' ),
                400
            );
        }

        // Same reasoning as `update_progress()`: hiding a locked lesson in
        // the reader means nothing if its assignment can be submitted, and
        // approved, over the API.
        $verdict = ( new CourseAccessResolver() )->forLesson( $slug, $lesson, $person_id, get_current_user_id() );

        if ( $verdict->isUnavailable() || $verdict->isDenied() ) {
            return RestResponse::notFound( 'lesson_not_found', __( 'That lesson is not part of this course.', 'talenttrack' ) );
        }

        if ( $verdict->isLocked() ) {
            return RestResponse::error( 'lesson_locked', __( 'Finish the previous lesson first.', 'talenttrack' ), 403 );
        }

        $enrolment = ( new EnrolmentRepository() )->findFor( $person_id, $slug );
        if ( $enrolment === null ) {
            return RestResponse::error( 'not_enrolled', __( 'You are not on this course.', 'talenttrack' ), 400 );
        }

        $body   = sanitize_textarea_field( (string) $r->get_param( 'body' ) );
        $result = ( new SubmissionService() )->submit( (int) $enrolment->id, $slug, $lesson, $body );

        switch ( $result['status'] ) {
            case SubmissionService::OK:
                return RestResponse::success(
                    self::shapeSubmission( ( new SubmissionRepository() )->find( $result['id'] ) ),
                    201
                );

            case SubmissionService::ERR_EMPTY:
                return RestResponse::error( 'empty_body', __( 'Write your answer before handing it in.', 'talenttrack' ), 400 );

            case SubmissionService::ERR_ALREADY:
                return RestResponse::error( 'already_submitted', __( 'This assignment is already with a reviewer.', 'talenttrack' ), 409 );

            case SubmissionService::ERR_NO_ASSIGNMENT:
                return RestResponse::error( 'no_assignment', __( 'That lesson has no assignment to hand in.', 'talenttrack' ), 400 );

            default:
                return RestResponse::error( 'submit_failed', __( 'That could not be handed in.', 'talenttrack' ), 500 );
        }
    }

    /**
     * The caller's review queue, oldest first.
     *
     * Narrowed to their own routed work plus everything unrouted, unless
     * they hold the management capability — the same rule the queue view
     * applies, because it is the repository's, not the surface's.
     *
     * @return \WP_REST_Response
     */
    public static function pending_submissions( WP_REST_Request $r ) {
        $person_id = self::currentPersonId();

        $rows = ( new SubmissionRepository() )->listPending(
            current_user_can( ReviewerResolver::REVIEW_CAP ) ? 0 : $person_id
        );

        $out = [];
        foreach ( $rows as $row ) {
            $out[] = self::shapeSubmission( $row );
        }

        return RestResponse::success( [ 'submissions' => $out ] );
    }

    /**
     * Record a verdict.
     *
     * The per-row authorisation lives here rather than in the
     * `permission_callback`, which runs before the id is known: reaching
     * the queue does not entitle a mentor to rule on work routed to
     * somebody else.
     *
     * @return \WP_REST_Response
     */
    public static function review_submission( WP_REST_Request $r ) {
        $id         = (int) $r['id'];
        $repository = new SubmissionRepository();
        $submission = $repository->find( $id );

        if ( $submission === null ) {
            return RestResponse::notFound( 'submission_not_found', __( 'That submission does not exist.', 'talenttrack' ) );
        }

        $person_id = self::currentPersonId();
        $routed_to = $submission->reviewer_person_id === null ? null : (int) $submission->reviewer_person_id;

        if ( ! ReviewerResolver::canReview( get_current_user_id(), $person_id, $routed_to ) ) {
            return RestResponse::error( 'not_your_review', __( 'That submission is not yours to review.', 'talenttrack' ), 403 );
        }

        $outcome  = sanitize_key( (string) $r->get_param( 'outcome' ) );
        $feedback = sanitize_textarea_field( (string) $r->get_param( 'feedback' ) );

        $result = ( new SubmissionService() )->review( $id, $outcome, $feedback, $person_id );

        switch ( $result['status'] ) {
            case SubmissionService::OK:
                return RestResponse::success( [
                    'submission' => self::shapeSubmission( $repository->find( $id ) ),
                    'completed'  => $result['completed'],
                ] );

            case SubmissionService::ERR_NO_FEEDBACK:
                return RestResponse::error( 'feedback_required', __( 'Say why before asking for changes or turning it down.', 'talenttrack' ), 400 );

            case SubmissionService::ERR_BAD_OUTCOME:
                return RestResponse::error( 'bad_outcome', __( 'That is not a decision this review can take.', 'talenttrack' ), 400 );

            default:
                return RestResponse::error( 'review_failed', __( 'That decision could not be recorded.', 'talenttrack' ), 500 );
        }
    }

    /**
     * The payload shape for a submission.
     *
     * `body` and `feedback` are the substance, so both are returned in
     * full. Attachments are counted rather than expanded: a consumer that
     * wants the documents asks the media endpoint for
     * `entity_type=course_submission`, which is where their visibility
     * rules are applied.
     *
     * @return array<string, mixed>|null
     */
    private static function shapeSubmission( ?object $submission ): ?array {
        if ( $submission === null ) {
            return null;
        }

        return [
            'id'            => (int) $submission->id,
            'uuid'          => (string) $submission->uuid,
            'enrolment_id'  => (int) $submission->enrolment_id,
            'lesson_slug'   => (string) $submission->lesson_slug,
            'body'          => (string) ( $submission->body ?? '' ),
            'submitted_at'  => $submission->submitted_at,
            'outcome'       => (string) $submission->outcome,
            'feedback'      => (string) ( $submission->feedback ?? '' ),
            'reviewed_at'   => $submission->reviewed_at,
            'reviewer_id'   => $submission->reviewer_person_id === null ? null : (int) $submission->reviewer_person_id,
            'attachments'   => SubmissionAttachments::countFor( (int) $submission->id ),
        ];
    }
}
