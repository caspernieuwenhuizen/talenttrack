<?php
namespace TT\Modules\Knowledge\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Knowledge\CourseAccessResolver;
use TT\Modules\Knowledge\CourseCompletionService;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\LessonRenderer;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\ProgressRepository;
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
    }

    /* ===== permission gates ===== */

    public static function can_view(): bool {
        return current_user_can( 'tt_view_knowledge' );
    }

    public static function can_manage(): bool {
        return current_user_can( 'tt_manage_knowledge' );
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
}
