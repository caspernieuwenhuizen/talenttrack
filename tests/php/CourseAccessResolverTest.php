<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Modules\Knowledge\CourseAccessResolver;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgeModule;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\ProgressRepository;
use TT\Shared\Content\ContentGate;

/**
 * #2645 — the two gates that are about what the learner has done, and the
 * enforcement of all six on the write path.
 *
 * The write path is the one that matters. Hiding a locked lesson in the
 * reader means nothing if POSTing its progress marks it read, and a
 * sequential course that can be walked around is not sequential.
 */
final class CourseAccessResolverTest extends WP_UnitTestCase {

    private const COURSE = 'voetbalperiodisering';

    private int $person_id = 0;
    private int $user_id   = 0;

    public function set_up(): void {
        parent::set_up();
        CourseRegistry::flushCache();
        KnowledgeModule::ensureCapabilities();

        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Gate',
            'last_name'  => 'Tester',
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

        FeatureRegistry::setEnabled( 'knowledge_courses', true );
        wp_set_current_user( 0 );
        CourseRegistry::flushCache();
        parent::tear_down();
    }

    // ── sequential ─────────────────────────────────────────────────────

    /**
     * The shipped course declares `sequential: true`, so only the first
     * lesson opens to somebody who has done nothing.
     */
    public function test_only_the_first_lesson_opens_before_any_progress(): void {
        $verdicts = ( new CourseAccessResolver() )->forLessons( self::COURSE, $this->person_id, $this->user_id );
        $slugs    = array_keys( $verdicts );

        $this->assertNotEmpty( $slugs );
        $this->assertTrue( $verdicts[ $slugs[0] ]->isAvailable() );
        $this->assertTrue( $verdicts[ $slugs[1] ]->isLocked() );
        $this->assertSame( CourseAccessResolver::REASON_SEQUENTIAL, $verdicts[ $slugs[1] ]->reason() );
        $this->assertSame( $slugs[0], $verdicts[ $slugs[1] ]->context()['after'] );
    }

    public function test_completing_a_lesson_opens_the_next_one(): void {
        $enrolment = ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE );
        $slugs     = array_keys( CourseRegistry::lessons( self::COURSE ) );

        $this->completeLesson( $enrolment, $slugs[0] );

        $verdicts = ( new CourseAccessResolver() )->forLessons( self::COURSE, $this->person_id, $this->user_id );

        $this->assertTrue( $verdicts[ $slugs[1] ]->isAvailable() );
        $this->assertTrue( $verdicts[ $slugs[2] ]->isLocked(), 'The one after that stays locked.' );
    }

    /**
     * A locked lesson is still listed. Hiding it would make the course
     * look shorter than it is, and a reader cannot work towards something
     * they cannot see.
     */
    public function test_locked_lessons_are_listable(): void {
        $verdicts = ( new CourseAccessResolver() )->forLessons( self::COURSE, $this->person_id, $this->user_id );

        foreach ( $verdicts as $slug => $verdict ) {
            $this->assertTrue( $verdict->isListable(), "Lesson {$slug} should be listed." );
        }
    }

    public function test_every_declared_lesson_gets_a_verdict(): void {
        $manifest = CourseRegistry::get( self::COURSE );
        $verdicts = ( new CourseAccessResolver() )->forLessons( self::COURSE, $this->person_id, $this->user_id );

        $this->assertSame( $manifest->lessonSlugs(), array_keys( $verdicts ) );
    }

    // ── install gates flow through ─────────────────────────────────────

    /**
     * The course manifest declares `feature: knowledge_courses`, so
     * switching the feature off must take the course down — and take
     * every lesson with it, rather than reporting each lesson's own
     * sequential state.
     */
    public function test_disabling_the_feature_makes_the_course_unavailable(): void {
        FeatureRegistry::setEnabled( 'knowledge_courses', false );

        $resolver = new CourseAccessResolver();
        $verdict  = $resolver->forCourse( self::COURSE, $this->person_id, $this->user_id );

        $this->assertTrue( $verdict->isUnavailable() );
        $this->assertSame( ContentGate::REASON_FEATURE, $verdict->reason() );
        $this->assertSame( [], $resolver->listableCourses( $this->person_id, $this->user_id ) );

        foreach ( $resolver->forLessons( self::COURSE, $this->person_id, $this->user_id ) as $lesson_verdict ) {
            $this->assertTrue( $lesson_verdict->isUnavailable() );
        }
    }

    /**
     * A reader without the course's declared capability is denied, and the
     * course is absent from their listing rather than shown locked.
     */
    public function test_a_reader_without_the_capability_sees_no_course(): void {
        $stranger = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $resolver = new CourseAccessResolver();
        $verdict  = $resolver->forCourse( self::COURSE, 0, $stranger );

        $this->assertTrue( $verdict->isDenied() );
        $this->assertFalse( $verdict->isListable() );
        $this->assertSame( [], $resolver->listableCourses( 0, $stranger ) );
    }

    public function test_an_unknown_course_is_unavailable(): void {
        $verdict = ( new CourseAccessResolver() )->forCourse( 'no-such-course', $this->person_id, $this->user_id );

        $this->assertFalse( $verdict->isAvailable() );
        $this->assertSame( [], ( new CourseAccessResolver() )->forLessons( 'no-such-course', $this->person_id ) );
    }

    // ── prerequisites ──────────────────────────────────────────────────

    /**
     * The shipped course requires nothing, so it must open to a newcomer.
     * If this ever fails, a `requires:` entry has been added without the
     * prerequisite course existing.
     */
    public function test_the_shipped_course_has_no_prerequisite_wall(): void {
        $verdict = ( new CourseAccessResolver() )->forCourse( self::COURSE, $this->person_id, $this->user_id );

        $this->assertTrue( $verdict->isAvailable() );
    }

    // The fail-open on a dangling `requires:` slug is deliberately not
    // tested here. Proving it needs a course whose prerequisite does not
    // exist, and `tools/check-courses.php` fails a PR for exactly that —
    // so the fixture would have to live inside `courses/`, where a crashed
    // test leaves the corpus lint red for the next person. The lint is the
    // guard that matters; the runtime fail-open is the safety net beneath
    // it, and is covered by the unknown-key tests in ContentGateTest.

    // ── REST enforcement ───────────────────────────────────────────────

    /**
     * The one that matters: a locked lesson must not accept progress.
     */
    public function test_rest_refuses_progress_on_a_locked_lesson(): void {
        wp_set_current_user( $this->user_id );

        $slugs   = array_keys( CourseRegistry::lessons( self::COURSE ) );
        $request = new WP_REST_Request( 'PATCH', '/talenttrack/v1/courses/' . self::COURSE . '/progress/' . $slugs[2] );
        $request->set_param( 'read', true );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 403, $response->get_status() );

        $progress = new ProgressRepository();
        $enrolment = ( new EnrolmentRepository() )->findFor( $this->person_id, self::COURSE );

        if ( $enrolment !== null ) {
            $row = $progress->find( (int) $enrolment->id, $slugs[2] );
            $this->assertTrue( $row === null || $row->read_at === null, 'A refused lesson must not be marked read.' );
        }
    }

    public function test_rest_accepts_progress_on_the_open_lesson(): void {
        wp_set_current_user( $this->user_id );

        $slugs   = array_keys( CourseRegistry::lessons( self::COURSE ) );
        $request = new WP_REST_Request( 'PATCH', '/talenttrack/v1/courses/' . self::COURSE . '/progress/' . $slugs[0] );
        $request->set_param( 'read', true );

        $this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
    }

    /**
     * Progress on lesson 2 is refused, then accepted once lesson 1 is
     * done — the gate opens as well as closes.
     */
    public function test_rest_opens_the_next_lesson_after_the_previous_one(): void {
        wp_set_current_user( $this->user_id );

        $slugs = array_keys( CourseRegistry::lessons( self::COURSE ) );

        $second = new WP_REST_Request( 'PATCH', '/talenttrack/v1/courses/' . self::COURSE . '/progress/' . $slugs[1] );
        $second->set_param( 'read', true );
        $this->assertSame( 403, rest_get_server()->dispatch( $second )->get_status() );

        $enrolment = ( new EnrolmentRepository() )->enrol( $this->person_id, self::COURSE );
        $this->completeLesson( $enrolment, $slugs[0] );

        $again = new WP_REST_Request( 'PATCH', '/talenttrack/v1/courses/' . self::COURSE . '/progress/' . $slugs[1] );
        $again->set_param( 'read', true );
        $this->assertSame( 200, rest_get_server()->dispatch( $again )->get_status() );
    }

    /**
     * A course this install does not have is absent, not forbidden. A 403
     * confirms it exists here, which is what hiding it was meant to avoid.
     */
    public function test_rest_returns_404_for_a_course_the_install_cannot_have(): void {
        wp_set_current_user( $this->user_id );
        FeatureRegistry::setEnabled( 'knowledge_courses', false );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/courses/' . self::COURSE )
        );

        $this->assertSame( 404, $response->get_status() );
    }

    public function test_rest_catalogue_omits_courses_the_install_cannot_have(): void {
        wp_set_current_user( $this->user_id );
        FeatureRegistry::setEnabled( 'knowledge_courses', false );

        $data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/courses' ) )->get_data();
        $list = $data['data'] ?? $data;

        $this->assertSame( [], $list );
    }

    public function test_rest_course_payload_carries_per_lesson_verdicts(): void {
        wp_set_current_user( $this->user_id );

        $data = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/courses/' . self::COURSE )
        )->get_data();

        $payload = $data['data'] ?? $data;

        $this->assertArrayHasKey( 'access', $payload );
        $this->assertNotEmpty( $payload['lessons'] );
        $this->assertArrayHasKey( 'access', $payload['lessons'][0] );
        $this->assertSame( 'available', $payload['lessons'][0]['access']['kind'] );
        $this->assertSame( 'locked', $payload['lessons'][1]['access']['kind'] );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** Satisfy every requirement the lesson declares. */
    private function completeLesson( int $enrolment_id, string $lesson_slug ): void {
        $progress = new ProgressRepository();
        $progress->markRead( $enrolment_id, $lesson_slug );
        $progress->markQuizPassed( $enrolment_id, $lesson_slug );
        $progress->setAssignmentApproved( $enrolment_id, $lesson_slug, true );
    }
}
