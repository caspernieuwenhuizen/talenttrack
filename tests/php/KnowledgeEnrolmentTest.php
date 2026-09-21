<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Knowledge\CourseCompletionService;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgeModule;
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
 *
 * #3922 — those denied paths used to build a `subscriber` and bolt
 * `tt_view_knowledge` on with `add_cap()`. Every assertion on such a
 * fixture is a refusal, so nothing distinguishes "holds read, lacks
 * manage" — the thing the test names — from "holds nothing at all", and
 * all of them would pass with the gate under test deleted. They now use a
 * real `tt_coach`, the persona the module grants read and withholds the
 * statistics and manage grants from, and each refusal is paired with a
 * grant the same caller does get.
 */
final class KnowledgeEnrolmentTest extends WP_UnitTestCase {

    private const COURSE = 'voetbalperiodisering';

    private int $person_id = 0;
    private int $user_id   = 0;

    public function set_up(): void {
        parent::set_up();
        CourseRegistry::flushCache();

        // #3922 — the roles the knowledge grants are hung on have to exist
        // before they are granted. `ensureCapabilities()` skips a role it
        // cannot find, and a user created against a role that does not
        // exist holds no role at all, so without this the reader fixture
        // below would silently be a nobody.
        ( new RolesService() )->installRoles();

        // The bootstrap runs migrations only, not the capability grants —
        // and a grant made on `init` lands inside the test transaction and
        // is rolled back before the next test. Idempotent, so calling it
        // per test is correct rather than merely harmless.
        KnowledgeModule::ensureCapabilities();

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
     * #3707 — assigning a course to somebody not yet on it creates the
     * enrolment with the deadline the assignment carried.
     */
    public function test_rest_assigning_a_new_person_is_201_and_stores_the_deadline(): void {
        wp_set_current_user( $this->user_id );

        $other = $this->createPerson();

        $response = rest_get_server()->dispatch( $this->assignRequest( $other, '2026-12-18' ) );
        $data     = $response->get_data()['data'];

        $this->assertSame( 201, $response->get_status() );
        $this->assertFalse( $data['already_enrolled'] );
        $this->assertFalse( $data['due_at_ignored'] );
        $this->assertSame( EnrolmentRepository::normaliseDate( '2026-12-18' ), $data['due_at'] );

        $this->deletePerson( $other );
    }

    /**
     * #3707 — re-assigning somebody already on the course answers `200
     * already_enrolled`, not `201 Created`. Nothing was created, and the
     * status code has to say so: an admin reading `201` believes the
     * assignment landed.
     */
    public function test_rest_reassigning_is_200_and_says_already_enrolled(): void {
        wp_set_current_user( $this->user_id );

        $other = $this->createPerson();

        $first  = rest_get_server()->dispatch( $this->assignRequest( $other, '2026-12-18' ) );
        $second = rest_get_server()->dispatch( $this->assignRequest( $other, '2026-12-18' ) );

        $data = $second->get_data()['data'];

        $this->assertSame( 200, $second->get_status() );
        $this->assertTrue( $data['already_enrolled'] );
        $this->assertFalse( $data['due_at_ignored'], 'The same deadline was not ignored — it is already the stored one.' );
        $this->assertSame( $first->get_data()['data']['id'], $data['id'], 'Re-assigning must return the same enrolment.' );
        $this->assertSame( EnrolmentRepository::STATUS_NOT_STARTED, $data['status'] );

        $this->deletePerson( $other );
    }

    /**
     * #3707 — the bug as reported: a second assignment carrying a *new*
     * deadline. The repository keeps the stored one on purpose, so the
     * response has to admit the new one was not applied rather than hand
     * back `201` and the old date.
     */
    public function test_rest_reassigning_with_a_new_deadline_says_it_was_not_applied(): void {
        wp_set_current_user( $this->user_id );

        $other = $this->createPerson();
        $repo  = new EnrolmentRepository();

        rest_get_server()->dispatch( $this->assignRequest( $other, '2026-09-07' ) );

        $response = rest_get_server()->dispatch( $this->assignRequest( $other, '2026-12-18' ) );
        $data     = $response->get_data()['data'];

        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $data['already_enrolled'] );
        $this->assertTrue( $data['due_at_ignored'] );
        $this->assertNotEmpty( $data['message'] );
        $this->assertSame( EnrolmentRepository::normaliseDate( '2026-09-07' ), $data['due_at'], 'The stored deadline must not move.' );

        $stored = $repo->findFor( $other, self::COURSE );
        $this->assertNotNull( $stored );
        $this->assertSame( EnrolmentRepository::normaliseDate( '2026-09-07' ), $stored->due_at );

        $this->deletePerson( $other );
    }

    /**
     * #3707 — the self-enrol path the "start" button uses still works when
     * somebody taps it twice. Second tap: same enrolment, 200, no drama.
     */
    public function test_rest_self_enrolling_twice_still_works(): void {
        wp_set_current_user( $this->user_id );

        $first  = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/talenttrack/v1/courses/' . self::COURSE . '/enrolments' ) );
        $second = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/talenttrack/v1/courses/' . self::COURSE . '/enrolments' ) );

        $this->assertSame( 201, $first->get_status() );
        $this->assertSame( 200, $second->get_status() );
        $this->assertTrue( $second->get_data()['data']['already_enrolled'] );
        $this->assertFalse( $second->get_data()['data']['due_at_ignored'], 'No deadline was asked for, so none was ignored.' );
        $this->assertSame( $first->get_data()['data']['id'], $second->get_data()['data']['id'] );
    }

    /**
     * Someone else's learning record needs the statistics capability, not
     * the view capability. Getting this wrong exposes every coach's
     * completion rate to every coach.
     *
     * #3922 — the caller is a real `tt_coach`, which the module grants
     * `tt_view_knowledge` and deliberately not `tt_view_knowledge_statistics`.
     * It used to be a `subscriber` with the cap bolted on by `add_cap()`,
     * and nothing in the test could tell that apart from a caller holding
     * nothing at all: every assertion was a refusal. The reader now has to
     * read their **own** record first, so the 403 below is the one the
     * test's name claims.
     */
    public function test_rest_another_persons_record_needs_the_statistics_capability(): void {
        [ $reader, $reader_person ] = $this->makeKnowledgeReader();

        $this->assertSame(
            200,
            rest_get_server()->dispatch(
                new WP_REST_Request( 'GET', '/talenttrack/v1/people/' . $reader_person . '/learning' )
            )->get_status(),
            'the reader cannot read their own record, so a refusal below proves nothing'
        );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/people/' . $this->person_id . '/learning' )
        );

        $this->assertSame( 403, $response->get_status() );

        wp_delete_user( $reader );
    }

    /**
     * #3708 — the deadline moves, and nothing else on the row does. An
     * academy that pushes a staff course target from September to December
     * must not pay for it with everybody's progress.
     */
    public function test_rest_patch_moves_the_deadline_and_leaves_progress_alone(): void {
        wp_set_current_user( $this->user_id );

        $repo = new EnrolmentRepository();
        $id   = $repo->enrol( $this->person_id, self::COURSE, [ 'due_at' => '2026-09-07' ] );
        $repo->markStarted( $id );

        $started = $repo->find( $id )->started_at;

        $response = rest_get_server()->dispatch( $this->dueDateRequest( $id, '2026-12-18' ) );
        $data     = $response->get_data()['data'];

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( EnrolmentRepository::normaliseDate( '2026-12-18' ), $data['due_at'] );

        $stored = $repo->find( $id );
        $this->assertSame( EnrolmentRepository::normaliseDate( '2026-12-18' ), $stored->due_at );
        $this->assertSame( EnrolmentRepository::STATUS_IN_PROGRESS, $stored->status );
        $this->assertSame( $started, $stored->started_at, 'Moving a deadline must not rewrite when they started.' );
    }

    /** #3708 — an explicit null is "no deadline", not "leave it alone". */
    public function test_rest_patch_with_null_clears_the_deadline(): void {
        wp_set_current_user( $this->user_id );

        $repo = new EnrolmentRepository();
        $id   = $repo->enrol( $this->person_id, self::COURSE, [ 'due_at' => '2026-09-07' ] );

        $response = rest_get_server()->dispatch( $this->dueDateRequest( $id, null ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertNull( $response->get_data()['data']['due_at'] );
        $this->assertNull( $repo->find( $id )->due_at );
    }

    /**
     * #3708 — the partial-update contract (CLAUDE.md §6). A body that says
     * nothing about `due_at` leaves the stored one where it was; the
     * alternative is a deadline quietly vanishing every time some other
     * field is patched.
     */
    public function test_rest_patch_omitting_due_at_leaves_the_row_alone(): void {
        wp_set_current_user( $this->user_id );

        $repo = new EnrolmentRepository();
        $id   = $repo->enrol( $this->person_id, self::COURSE, [ 'due_at' => '2026-09-07' ] );

        $request = new WP_REST_Request( 'PATCH', '/talenttrack/v1/enrolments/' . $id );
        $request->set_param( 'note', 'nothing to do with the deadline' );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( EnrolmentRepository::normaliseDate( '2026-09-07' ), $repo->find( $id )->due_at );
    }

    /**
     * #3708 — a date that does not exist is refused rather than rolled
     * forward. `strtotime( '2026-02-31' )` returns 3 March, which would
     * store a deadline nobody asked for.
     */
    public function test_rest_patch_rejects_a_malformed_date_and_writes_nothing(): void {
        wp_set_current_user( $this->user_id );

        $repo = new EnrolmentRepository();
        $id   = $repo->enrol( $this->person_id, self::COURSE, [ 'due_at' => '2026-09-07' ] );

        foreach ( [ 'next tuesday', '18-12-2026', '2026-02-31' ] as $bad ) {
            $response = rest_get_server()->dispatch( $this->dueDateRequest( $id, $bad ) );

            $this->assertSame( 400, $response->get_status(), $bad . ' should be refused.' );
            $this->assertSame( EnrolmentRepository::normaliseDate( '2026-09-07' ), $repo->find( $id )->due_at );
        }
    }

    /** #3708 — an unknown enrolment is a 404, not a silent success. */
    public function test_rest_patch_on_an_unknown_enrolment_is_404(): void {
        wp_set_current_user( $this->user_id );

        $response = rest_get_server()->dispatch( $this->dueDateRequest( 999999, '2026-12-18' ) );

        $this->assertSame( 404, $response->get_status() );
    }

    /**
     * #3708 — same gate as the DELETE sibling: moving a deadline is
     * management. #3922 — and the caller is a reader who genuinely holds
     * knowledge read, proven by reading the library first.
     */
    public function test_rest_patch_requires_the_manage_capability(): void {
        $id = ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE );

        [ $reader ] = $this->makeKnowledgeReader();
        $this->assertReaderHoldsKnowledgeRead();

        $response = rest_get_server()->dispatch( $this->dueDateRequest( $id, '2026-12-18' ) );

        $this->assertSame( 403, $response->get_status() );
        $this->assertNull(
            ( new EnrolmentRepository() )->find( $id )->due_at,
            'a refused deadline move still wrote'
        );

        wp_delete_user( $reader );
    }

    /**
     * #3708 — an enrolment moved into the future drops out of the overdue
     * listing the alerts and the learning report read.
     */
    public function test_moving_a_deadline_forward_drops_it_from_the_overdue_listing(): void {
        wp_set_current_user( $this->user_id );

        $repo = new EnrolmentRepository();
        $id   = $repo->enrol( $this->person_id, self::COURSE, [ 'due_at' => '2020-01-01' ] );

        $overdue = array_map( static fn( $row ) => (int) $row->id, $repo->listOverdue() );
        $this->assertContains( $id, $overdue );

        rest_get_server()->dispatch( $this->dueDateRequest( $id, gmdate( 'Y-m-d', time() + YEAR_IN_SECONDS ) ) );

        $overdue = array_map( static fn( $row ) => (int) $row->id, $repo->listOverdue() );
        $this->assertNotContains( $id, $overdue );
    }

    /**
     * #3922 — as above: a reader who genuinely holds knowledge read still
     * may not withdraw somebody, and the enrolment survives the attempt.
     */
    public function test_rest_withdraw_requires_the_manage_capability(): void {
        $repo = new EnrolmentRepository();
        $id   = $repo->enrol( $this->person_id, self::COURSE );

        [ $reader ] = $this->makeKnowledgeReader();
        $this->assertReaderHoldsKnowledgeRead();

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'DELETE', '/talenttrack/v1/enrolments/' . $id )
        );

        $this->assertSame( 403, $response->get_status() );
        $this->assertNotNull( $repo->find( $id ), 'a refused withdrawal still removed the enrolment' );

        wp_delete_user( $reader );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * A caller the knowledge module genuinely grants read to, and nothing
     * more: a `tt_coach`, which `KnowledgeModule::ensureCapabilities()`
     * gives `tt_view_knowledge` and deliberately not
     * `tt_view_knowledge_statistics` or `tt_manage_knowledge`.
     *
     * Why a role rather than `add_cap()` on a `subscriber` (#3922): a
     * fixture whose caller holds nothing is indistinguishable from one
     * whose caller holds read-but-not-manage as long as every assertion is
     * a refusal, and both pass with the gate under test deleted. Pairing
     * the refusal with a grant is what makes the test name true, and that
     * needs a caller who really is a reader.
     *
     * The `tt_people` row carries the coach's own `wp_user_id`, so
     * `KnowledgeRestController::isSelf()` resolves and the reader can read
     * their own learning record.
     *
     * @return array{0:int,1:int} the WP user id and their person id.
     */
    private function makeKnowledgeReader(): array {
        global $wpdb;

        $user_id = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Reader',
            'last_name'  => 'Coach',
            'wp_user_id' => $user_id,
        ] );
        $person_id = (int) $wpdb->insert_id;

        wp_set_current_user( $user_id );

        return [ $user_id, $person_id ];
    }

    /**
     * The grant half of every refusal below: the current caller can read
     * the course library, so a 403 on a management route is about the
     * management gate rather than about holding no knowledge access.
     */
    private function assertReaderHoldsKnowledgeRead(): void {
        $this->assertSame(
            200,
            rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/courses' ) )->get_status(),
            'the caller holds no knowledge read at all, so a refusal proves nothing'
        );
    }

    /** A second staff record, so assignment can be tested rather than self-enrol. */
    private function createPerson(): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Other',
            'last_name'  => 'Coach',
        ] );

        return (int) $wpdb->insert_id;
    }

    private function deletePerson( int $person_id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_people', [ 'id' => $person_id ] );
    }

    /** A `PATCH /enrolments/{id}` carrying a deadline — `null` clears it. */
    private function dueDateRequest( int $enrolment_id, ?string $due_at ): WP_REST_Request {
        $request = new WP_REST_Request( 'PATCH', '/talenttrack/v1/enrolments/' . $enrolment_id );
        $request->set_param( 'due_at', $due_at );

        return $request;
    }

    private function assignRequest( int $person_id, string $due_at ): WP_REST_Request {
        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/courses/' . self::COURSE . '/enrolments' );
        $request->set_param( 'person_id', $person_id );
        $request->set_param( 'due_at', $due_at );

        return $request;
    }

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
