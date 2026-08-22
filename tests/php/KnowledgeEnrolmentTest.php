<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Modules\Knowledge\CourseCompletionService;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\ProgressRepository;
use TT\Modules\Knowledge\Repositories\QuizAttemptRepository;
use TT\Modules\Knowledge\Repositories\SubmissionRepository;

/**
 * #2644 — enrolment, progress and the completion rule.
 *
 * The assertions that matter are the ones a wrong answer would quietly
 * mis-certify someone over: what counts as a completed lesson, when an
 * enrolment flips status, and that the completion hook fires once rather
 * than on every recount.
 *
 * Plus the REST smoke tests the endpoint mandate requires — including the
 * denied paths, because an authorization hole is the failure class that
 * gate exists for.
 */
final class KnowledgeEnrolmentTest extends WP_UnitTestCase {

    private const COURSE = 'voetbalperiodisering';

    private int $person_id = 0;
    private int $user_id   = 0;

    public function set_up(): void {
        parent::set_up();
        CourseRegistry::flushCache();

        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Test',
            'last_name'  => 'Coach',
            'wp_user_id' => $this->user_id,
        ] );
        $this->person_id = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wpdb;
        foreach ( [ 'tt_course_submissions', 'tt_course_quiz_attempts', 'tt_course_progress', 'tt_course_enrolments' ] as $t ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" );
        }
        $wpdb->delete( $wpdb->prefix . 'tt_people', [ 'id' => $this->person_id ] );

        wp_set_current_user( 0 );
        CourseRegistry::flushCache();
        parent::tear_down();
    }

    // ── enrolment ──────────────────────────────────────────────────────

    public function test_enrolling_twice_returns_the_same_row(): void {
        $repo = new EnrolmentRepository();

        $first  = $repo->enrol( $this->person_id, self::COURSE );
        $second = $repo->enrol( $this->person_id, self::COURSE );

        $this->assertGreaterThan( 0, $first );
        $this->assertSame( $first, $second, 'Re-enrolling must not create a second row.' );
    }

    /**
     * Re-assigning someone already enrolled must not wipe their progress.
     * The assign-course wizard (#2649) runs over whole groups, and some of
     * that group will already be halfway through.
     */
    public function test_reassigning_does_not_reset_progress(): void {
        $repo = new EnrolmentRepository();
        $id   = $repo->enrol( $this->person_id, self::COURSE );

        $lessons = array_keys( CourseRegistry::lessons( self::COURSE ) );
        ( new ProgressRepository() )->markRead( $id, $lessons[0] );
        $repo->markStarted( $id );

        $repo->enrol( $this->person_id, self::COURSE, [ 'assigned_by' => 99 ] );

        $progress = ( new ProgressRepository() )->find( $id, $lessons[0] );
        $this->assertNotNull( $progress );
        $this->assertNotNull( $progress->read_at );
        $this->assertSame( EnrolmentRepository::STATUS_IN_PROGRESS, $repo->find( $id )->status );
    }

    /**
     * `started_at` is the first time they opened the course, and reopening
     * lesson one in week six must not move it — the report's time-to-complete
     * figure is derived from it.
     */
    public function test_started_at_is_not_rewritten(): void {
        $repo = new EnrolmentRepository();
        $id   = $repo->enrol( $this->person_id, self::COURSE );

        $repo->markStarted( $id );
        $first = $repo->find( $id )->started_at;

        $repo->markStarted( $id );

        $this->assertSame( $first, $repo->find( $id )->started_at );
    }

    public function test_withdrawing_removes_the_children_too(): void {
        global $wpdb;

        $repo = new EnrolmentRepository();
        $id   = $repo->enrol( $this->person_id, self::COURSE );
        $slug = array_keys( CourseRegistry::lessons( self::COURSE ) )[0];

        ( new ProgressRepository() )->markRead( $id, $slug );
        ( new QuizAttemptRepository() )->record( $id, $slug, [], 5, 5, true );
        ( new SubmissionRepository() )->submit( $id, $slug, $slug, 'body' );

        $this->assertTrue( $repo->withdraw( $id ) );

        foreach ( [ 'tt_course_progress', 'tt_course_quiz_attempts', 'tt_course_submissions' ] as $table ) {
            $left = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE enrolment_id = %d",
                $id
            ) );
            $this->assertSame( 0, $left, "{$table} kept rows after withdrawal." );
        }
    }

    // ── completion ─────────────────────────────────────────────────────

    /**
     * Reading is not enough on a lesson that declares a quiz. Getting this
     * backwards would certify a coach who skipped every check.
     *
     * Every lesson in the shipped course that has a quiz also has an
     * assignment, so the quiz requirement is isolated by satisfying the
     * assignment and withholding only the quiz.
     */
    public function test_a_lesson_with_a_quiz_needs_the_quiz_passed(): void {
        $id       = ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE );
        $progress = new ProgressRepository();
        $service  = new CourseCompletionService();

        $lesson = $this->firstLessonWith( 'quiz' );
        $this->assertNotSame( '', $lesson, 'The shipped course should have a lesson with a quiz.' );

        $progress->markRead( $id, $lesson );
        $progress->setAssignmentApproved( $id, $lesson, true );
        $this->assertFalse(
            $service->isLessonComplete( self::COURSE, $lesson, $progress->find( $id, $lesson ) ),
            'Read and assignment approved, quiz outstanding — must not be complete.'
        );

        $progress->markQuizPassed( $id, $lesson );
        $this->assertTrue( $service->isLessonComplete( self::COURSE, $lesson, $progress->find( $id, $lesson ) ) );
    }

    /**
     * The final lesson has an assignment and no quiz, which isolates the
     * assignment requirement without any other gate in the way.
     */
    public function test_a_lesson_with_an_assignment_needs_it_approved(): void {
        $id       = ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE );
        $progress = new ProgressRepository();
        $service  = new CourseCompletionService();

        $lesson = $this->lessonWithAssignmentOnly();
        $this->assertNotSame( '', $lesson, 'The shipped course should have an assignment-only lesson.' );

        $progress->markRead( $id, $lesson );
        $this->assertFalse(
            $service->isLessonComplete( self::COURSE, $lesson, $progress->find( $id, $lesson ) ),
            'Read but assignment not approved — must not be complete.'
        );

        $progress->setAssignmentApproved( $id, $lesson, true );
        $this->assertTrue( $service->isLessonComplete( self::COURSE, $lesson, $progress->find( $id, $lesson ) ) );
    }

    /** A lesson with no requirements beyond reading completes on read. */
    public function test_reading_alone_completes_a_lesson_that_asks_nothing_else(): void {
        $id       = ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE );
        $progress = new ProgressRepository();

        $lesson = $this->lessonWithNoRequirements();
        if ( $lesson === '' ) {
            $this->markTestSkipped( 'Every shipped lesson currently carries a quiz or an assignment.' );
        }

        $progress->markRead( $id, $lesson );

        $this->assertTrue(
            ( new CourseCompletionService() )->isLessonComplete( self::COURSE, $lesson, $progress->find( $id, $lesson ) )
        );
    }

    public function test_progress_percent_floors_rather_than_rounds(): void {
        $repo    = new EnrolmentRepository();
        $id      = $repo->enrol( $this->person_id, self::COURSE );
        $service = new CourseCompletionService();

        $lessons = array_keys( CourseRegistry::lessons( self::COURSE ) );
        $this->completeLessons( $id, array_slice( $lessons, 0, count( $lessons ) - 1 ) );

        $progress = $service->progressFor( $id, self::COURSE );

        $this->assertSame( count( $lessons ) - 1, $progress['completed'] );
        $this->assertLessThan( 100, $progress['percent'], 'One lesson short must never read 100%.' );
    }

    public function test_next_lesson_is_the_first_incomplete_one(): void {
        $repo    = new EnrolmentRepository();
        $id      = $repo->enrol( $this->person_id, self::COURSE );
        $service = new CourseCompletionService();

        $lessons = array_keys( CourseRegistry::lessons( self::COURSE ) );
        $this->assertSame( $lessons[0], $service->nextLesson( $id, self::COURSE ) );

        $this->completeLessons( $id, [ $lessons[0], $lessons[1] ] );
        $this->assertSame( $lessons[2], $service->nextLesson( $id, self::COURSE ) );
    }

    public function test_completing_every_lesson_completes_the_enrolment(): void {
        $repo    = new EnrolmentRepository();
        $id      = $repo->enrol( $this->person_id, self::COURSE );
        $service = new CourseCompletionService();

        $this->completeLessons( $id, array_keys( CourseRegistry::lessons( self::COURSE ) ) );

        $this->assertSame( EnrolmentRepository::STATUS_COMPLETED, $service->recalculate( $id ) );
        $this->assertNotNull( $repo->find( $id )->completed_at );
        $this->assertNull( $service->nextLesson( $id, self::COURSE ) );
    }

    /**
     * The hook writes a certification (#2649). Firing it on every recount
     * instead of on the transition would write one per marked lesson.
     */
    public function test_the_completion_hook_fires_once(): void {
        $fired = 0;
        add_action( 'tt_knowledge_course_completed', static function () use ( &$fired ): void {
            $fired++;
        } );

        $id      = ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE );
        $service = new CourseCompletionService();

        $this->completeLessons( $id, array_keys( CourseRegistry::lessons( self::COURSE ) ) );

        $service->recalculate( $id );
        $service->recalculate( $id );
        $service->recalculate( $id );

        $this->assertSame( 1, $fired );
    }

    /**
     * A reviewer withdrawing an approval has to reopen the enrolment,
     * or the coach stays certified on a verdict that no longer stands.
     */
    public function test_withdrawing_an_approval_reopens_a_completed_enrolment(): void {
        $repo     = new EnrolmentRepository();
        $id       = $repo->enrol( $this->person_id, self::COURSE );
        $service  = new CourseCompletionService();
        $progress = new ProgressRepository();

        $this->completeLessons( $id, array_keys( CourseRegistry::lessons( self::COURSE ) ) );
        $service->recalculate( $id );
        $this->assertSame( EnrolmentRepository::STATUS_COMPLETED, $repo->find( $id )->status );

        $lesson = $this->firstLessonWith( 'assignment' );
        $progress->setAssignmentApproved( $id, $lesson, false );

        $this->assertSame( EnrolmentRepository::STATUS_IN_PROGRESS, $service->recalculate( $id ) );
        $this->assertNull( $repo->find( $id )->completed_at );
    }

    /**
     * A course withdrawn from the corpus must not reopen every completion
     * it ever produced.
     */
    public function test_a_retired_course_leaves_its_enrolments_alone(): void {
        global $wpdb;

        $repo = new EnrolmentRepository();
        $id   = $repo->enrol( $this->person_id, 'course-that-shipped-once' );
        $wpdb->update(
            $wpdb->prefix . 'tt_course_enrolments',
            [ 'status' => EnrolmentRepository::STATUS_COMPLETED ],
            [ 'id' => $id ]
        );

        $this->assertSame( EnrolmentRepository::STATUS_COMPLETED, ( new CourseCompletionService() )->recalculate( $id ) );
    }

    // ── submissions ────────────────────────────────────────────────────

    public function test_a_verdict_other_than_approved_requires_feedback(): void {
        $repo = new SubmissionRepository();
        $id   = $repo->submit(
            ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE ),
            'x',
            'x',
            'body'
        );

        $this->assertFalse( $repo->review( $id, SubmissionRepository::OUTCOME_CHANGES, '   ', $this->person_id ) );
        $this->assertTrue( $repo->review( $id, SubmissionRepository::OUTCOME_CHANGES, 'Redo the measurement.', $this->person_id ) );
    }

    /**
     * A resubmission must not overwrite the reviewer's earlier feedback —
     * that history is the record of the coaching.
     */
    public function test_resubmitting_adds_a_row_rather_than_replacing_one(): void {
        $enrolment = ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE );
        $repo      = new SubmissionRepository();

        $first = $repo->submit( $enrolment, 'x', 'x', 'first attempt' );
        $repo->review( $first, SubmissionRepository::OUTCOME_CHANGES, 'Try again.', $this->person_id );
        $second = $repo->submit( $enrolment, 'x', 'x', 'second attempt' );

        $this->assertNotSame( $first, $second );
        $this->assertCount( 2, $repo->listForEnrolment( $enrolment ) );
        $this->assertSame( $second, (int) $repo->latestFor( $enrolment, 'x' )->id );
        $this->assertSame( 'Try again.', $repo->find( $first )->feedback );
    }

    public function test_pending_queue_holds_only_unreviewed_submissions(): void {
        $enrolment = ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE );
        $repo      = new SubmissionRepository();

        $a = $repo->submit( $enrolment, 'a', 'a', 'one' );
        $repo->submit( $enrolment, 'b', 'b', 'two' );

        $this->assertSame( 2, $repo->countPending() );

        $repo->review( $a, SubmissionRepository::OUTCOME_APPROVED, '', $this->person_id );

        $this->assertSame( 1, $repo->countPending() );
    }

    // ── quiz attempts ──────────────────────────────────────────────────

    public function test_every_attempt_is_kept(): void {
        $enrolment = ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE );
        $repo      = new QuizAttemptRepository();

        $repo->record( $enrolment, 'x', [], 2, 5, false );
        $repo->record( $enrolment, 'x', [], 3, 5, false );
        $repo->record( $enrolment, 'x', [], 5, 5, true );

        $this->assertSame( 3, $repo->countFor( $enrolment, 'x' ) );
        $this->assertTrue( $repo->hasPassed( $enrolment, 'x' ) );
    }

    // ── tool state ─────────────────────────────────────────────────────

    /**
     * A zero-point measurement taken in module 4 has to survive to module
     * 11, where the final assignment asks for it.
     */
    public function test_tool_state_persists_and_merges(): void {
        $enrolment = ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE );
        $repo      = new ProgressRepository();

        $repo->saveToolState( $enrolment, 'x', [ 'zeropoint' => [ 'step' => 3 ] ] );
        $repo->saveToolState( $enrolment, 'x', [ 'weekplan' => [ 'mon' => 'off' ] ] );

        $state = $repo->toolState( $repo->find( $enrolment, 'x' ) );

        $this->assertSame( 3, $state['zeropoint']['step'] );
        $this->assertSame( 'off', $state['weekplan']['mon'] );
    }

    // ── REST ───────────────────────────────────────────────────────────

    public function test_rest_routes_are_registered(): void {
        $routes = rest_get_server()->get_routes();

        foreach ( [
            '/talenttrack/v1/courses',
            '/talenttrack/v1/courses/(?P<slug>[a-z0-9-]+)',
            '/talenttrack/v1/courses/(?P<slug>[a-z0-9-]+)/enrolments',
            '/talenttrack/v1/enrolments/(?P<id>\d+)',
            '/talenttrack/v1/people/(?P<id>\d+)/learning',
        ] as $route ) {
            $this->assertArrayHasKey( $route, $routes, "Route {$route} is not registered." );
        }
    }

    public function test_rest_course_list_requires_the_view_capability(): void {
        wp_set_current_user( 0 );

        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/courses' ) );

        $this->assertSame( 401, $response->get_status() );
    }

    public function test_rest_course_list_returns_the_catalogue(): void {
        wp_set_current_user( $this->user_id );

        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/courses' ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertNotEmpty( $response->get_data()['data'] ?? $response->get_data() );
    }

    public function test_rest_unknown_course_is_a_404(): void {
        wp_set_current_user( $this->user_id );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/courses/no-such-course' )
        );

        $this->assertSame( 404, $response->get_status() );
    }

    /**
     * Opening a lesson enrols the reader. Making them enrol separately
     * first would be a step nobody would understand.
     */
    public function test_rest_marking_a_lesson_read_enrols_on_first_touch(): void {
        wp_set_current_user( $this->user_id );

        $lesson  = array_keys( CourseRegistry::lessons( self::COURSE ) )[0];
        $request = new WP_REST_Request( 'PATCH', '/talenttrack/v1/courses/' . self::COURSE . '/progress/' . $lesson );
        $request->set_param( 'read', true );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertNotNull( ( new EnrolmentRepository() )->findFor( $this->person_id, self::COURSE ) );
    }

    /**
     * Someone else's learning record needs the statistics capability, not
     * the view capability. Getting this wrong exposes every coach's
     * completion rate to every coach.
     */
    public function test_rest_another_persons_record_needs_the_statistics_capability(): void {
        global $wpdb;

        $other_user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $other_user_obj = get_user_by( 'id', $other_user );
        $other_user_obj->add_cap( 'tt_view_knowledge' );

        wp_set_current_user( $other_user );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/people/' . $this->person_id . '/learning' )
        );

        $this->assertSame( 403, $response->get_status() );

        wp_delete_user( $other_user );
    }

    public function test_rest_withdraw_requires_the_manage_capability(): void {
        $id = ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE );

        $other_user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        get_user_by( 'id', $other_user )->add_cap( 'tt_view_knowledge' );
        wp_set_current_user( $other_user );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'DELETE', '/talenttrack/v1/enrolments/' . $id )
        );

        $this->assertSame( 403, $response->get_status() );

        wp_delete_user( $other_user );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** Mark every requirement met for the given lessons. */
    private function completeLessons( int $enrolment_id, array $lessons ): void {
        $progress = new ProgressRepository();

        foreach ( $lessons as $slug ) {
            $progress->markRead( $enrolment_id, $slug );
            $progress->markQuizPassed( $enrolment_id, $slug );
            $progress->setAssignmentApproved( $enrolment_id, $slug, true );
        }
    }

    /** First lesson in the shipped course declaring a quiz or assignment. */
    private function firstLessonWith( string $requirement ): string {
        foreach ( CourseRegistry::lessons( self::COURSE ) as $slug => $lesson ) {
            $has = $requirement === 'quiz' ? $lesson->hasQuiz() : $lesson->hasAssignment();
            if ( $has ) {
                return $slug;
            }
        }

        return '';
    }

    /** A lesson with an assignment and no quiz. */
    private function lessonWithAssignmentOnly(): string {
        foreach ( CourseRegistry::lessons( self::COURSE ) as $slug => $lesson ) {
            if ( $lesson->hasAssignment() && ! $lesson->hasQuiz() ) {
                return $slug;
            }
        }

        return '';
    }

    /** A lesson that completes on reading alone, if the corpus has one. */
    private function lessonWithNoRequirements(): string {
        foreach ( CourseRegistry::lessons( self::COURSE ) as $slug => $lesson ) {
            if ( ! $lesson->hasAssignment() && ! $lesson->hasQuiz() ) {
                return $slug;
            }
        }

        return '';
    }
}
