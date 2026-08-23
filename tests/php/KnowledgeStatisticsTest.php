<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\Frontend\LearningReports;
use TT\Modules\Knowledge\KnowledgeModule;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\LearningStatisticsService;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\ProgressRepository;

/**
 * #2650 — the completion roll-up.
 *
 * The assertion that matters most is the visibility one. Three levels were
 * specified, and the spec is explicit that a coach must be able to see their
 * own progress without seeing their colleagues' — so the middle level is
 * enforced in the REST `permission_callback`, and there is a test that a
 * coach holding only `tt_view_knowledge` is refused. Hiding a column would
 * not have been enough.
 *
 * The drop-off number is the other thing worth pinning. It is the one figure
 * on the report that says something about the *course* rather than about the
 * people taking it, and an off-by-one in the lesson ordering would point a
 * head of development at the wrong module.
 */
final class KnowledgeStatisticsTest extends WP_UnitTestCase {

    private const COURSE = 'voetbalperiodisering';

    /** @var list<int> */
    private array $people = [];

    public function set_up(): void {
        parent::set_up();
        CourseRegistry::flushCache();
        KnowledgePerson::flush();
        KnowledgeModule::ensureCapabilities();
    }

    public function tear_down(): void {
        global $wpdb;
        foreach ( [ 'tt_course_progress', 'tt_course_enrolments', 'tt_course_submissions', 'tt_user_role_scopes' ] as $t ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" );
        }
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_people" );

        $this->people = [];
        KnowledgePerson::flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function makePerson( string $last, string $role = 'tt_coach' ): array {
        $user_id = self::factory()->user->create( [ 'role' => $role ] );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Test',
            'last_name'  => $last,
            'wp_user_id' => $user_id,
        ] );

        $person_id      = (int) $wpdb->insert_id;
        $this->people[] = $person_id;
        KnowledgePerson::flush();

        return [ $user_id, $person_id ];
    }

    /* ===== per course ===== */

    public function test_counts_split_by_status(): void {
        $repo = new EnrolmentRepository();

        [ , $a ] = $this->makePerson( 'A' );
        [ , $b ] = $this->makePerson( 'B' );
        [ , $c ] = $this->makePerson( 'C' );

        $repo->enrol( $a, self::COURSE );
        $repo->enrol( $b, self::COURSE );
        $repo->enrol( $c, self::COURSE );

        $repo->markStarted( (int) $repo->findFor( $b, self::COURSE )->id );
        $repo->markCompleted( (int) $repo->findFor( $c, self::COURSE )->id );

        $stats = ( new LearningStatisticsService() )->forCourse( self::COURSE );

        $this->assertSame( 3, $stats['enrolled'] );
        $this->assertSame( 1, $stats['not_started'] );
        $this->assertSame( 1, $stats['in_progress'] );
        $this->assertSame( 1, $stats['completed'] );
    }

    public function test_overdue_counts_only_the_unfinished(): void {
        $repo = new EnrolmentRepository();

        [ , $late ] = $this->makePerson( 'Late' );
        [ , $done ] = $this->makePerson( 'Done' );

        $past = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
        $repo->enrol( $late, self::COURSE, [ 'due_at' => $past ] );
        $repo->enrol( $done, self::COURSE, [ 'due_at' => $past ] );

        $repo->markCompleted( (int) $repo->findFor( $done, self::COURSE )->id );

        $stats = ( new LearningStatisticsService() )->forCourse( self::COURSE );

        // Somebody who finished after the deadline is not still overdue.
        $this->assertSame( 1, $stats['overdue'] );
    }

    public function test_median_is_null_when_nobody_has_finished(): void {
        [ , $person ] = $this->makePerson( 'Nobody' );
        ( new EnrolmentRepository() )->enrol( $person, self::COURSE );

        $stats = ( new LearningStatisticsService() )->forCourse( self::COURSE );

        // Null, not zero: "nobody has finished" and "everybody finished
        // instantly" must not read the same on the report.
        $this->assertNull( $stats['median_days_to_complete'] );
    }

    /* ===== drop-off ===== */

    public function test_drop_off_names_the_lesson_readers_stop_at(): void {
        $repo     = new EnrolmentRepository();
        $progress = new ProgressRepository();
        $lessons  = array_keys( CourseRegistry::lessons( self::COURSE ) );

        // Four readers finish lesson 1; only one gets past lesson 2.
        foreach ( [ 'A', 'B', 'C', 'D' ] as $i => $letter ) {
            [ , $person ] = $this->makePerson( $letter );
            $repo->enrol( $person, self::COURSE );
            $enrolment = (int) $repo->findFor( $person, self::COURSE )->id;

            $progress->markRead( $enrolment, $lessons[0] );
            if ( $letter === 'A' ) {
                $progress->markRead( $enrolment, $lessons[1] );
            }
        }

        $drop = ( new LearningStatisticsService() )->dropOffFor( self::COURSE );

        $this->assertNotNull( $drop['stalls_at'] );
        $this->assertSame( $lessons[1], $drop['stalls_at']['slug'] );
        $this->assertSame( 3, $drop['stalls_at']['drop'] );
    }

    public function test_drop_off_is_null_when_nobody_has_read_anything(): void {
        $drop = ( new LearningStatisticsService() )->dropOffFor( self::COURSE );

        $this->assertNull( $drop['stalls_at'] );
        $this->assertNotEmpty( $drop['lessons'], 'every lesson is listed even at zero readers' );
    }

    public function test_the_first_lesson_never_counts_as_a_drop(): void {
        $drop = ( new LearningStatisticsService() )->dropOffFor( self::COURSE );

        $this->assertSame( 0, $drop['lessons'][0]['drop'], 'lesson one has nothing to fall from' );
    }

    /* ===== per person ===== */

    public function test_a_persons_percentage_and_overdue(): void {
        $repo = new EnrolmentRepository();
        [ , $person ] = $this->makePerson( 'Person' );

        $repo->enrol( $person, self::COURSE );
        $repo->markCompleted( (int) $repo->findFor( $person, self::COURSE )->id );

        $stats = ( new LearningStatisticsService() )->forPerson( $person );

        $this->assertSame( 1, $stats['assigned'] );
        $this->assertSame( 1, $stats['completed'] );
        $this->assertSame( 100, $stats['percent'] );
        $this->assertSame( 0, $stats['overdue'] );
    }

    public function test_a_person_on_no_courses_is_not_a_division_by_zero(): void {
        [ , $person ] = $this->makePerson( 'Empty' );

        $stats = ( new LearningStatisticsService() )->forPerson( $person );

        $this->assertSame( 0, $stats['percent'] );
        $this->assertSame( 'Never', $stats['last_activity'] === null ? 'Never' : 'set' );
    }

    /* ===== visibility ===== */

    public function test_a_coach_may_not_read_the_rollup_over_rest(): void {
        [ $user_id ] = $this->makePerson( 'Coach' );
        wp_set_current_user( $user_id );

        $this->assertTrue( user_can( $user_id, 'tt_view_knowledge' ) );
        $this->assertFalse( user_can( $user_id, 'tt_view_knowledge_statistics' ) );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/knowledge/statistics' )
        );

        // Enforced in the permission callback, not by hiding a column.
        $this->assertSame( 403, $response->get_status() );
    }

    public function test_a_head_of_development_may_read_the_rollup(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/knowledge/statistics' )
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertArrayHasKey( 'courses', $response->get_data()['data'] );
    }

    public function test_the_report_shows_a_coach_their_own_record_rather_than_refusing(): void {
        [ $user_id, $person_id ] = $this->makePerson( 'Own' );
        ( new EnrolmentRepository() )->enrol( $person_id, self::COURSE );

        wp_set_current_user( $user_id );

        ob_start();
        LearningReports::renderCourseOverview( $user_id );
        $html = (string) ob_get_clean();

        // Own-record access is a level, not an absence of one.
        $this->assertStringContainsString( 'Showing your own record', $html );
        $this->assertStringNotContainsString( 'Stalls at', $html );
    }

    public function test_rest_course_statistics_404s_on_an_unknown_course(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/courses/not-a-course/statistics' )
        );

        $this->assertSame( 404, $response->get_status() );
    }

    /* ===== presentation ===== */

    public function test_overdue_is_readable_without_colour(): void {
        $repo = new EnrolmentRepository();
        [ , $person ] = $this->makePerson( 'Late' );
        $repo->enrol( $person, self::COURSE, [
            'due_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-3 days' ) ),
        ] );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        ob_start();
        LearningReports::renderCourseOverview( get_current_user_id() );
        $html = (string) ob_get_clean();

        // The chip carries the word, so the state survives a reader who
        // cannot separate the hues.
        $this->assertStringContainsString( 'overdue', $html );
        $this->assertStringContainsString( 'tt-learning-chip--overdue', $html );
    }

    public function test_the_export_humanises_instead_of_shipping_enums(): void {
        [ , $person ] = $this->makePerson( 'Export' );
        ( new EnrolmentRepository() )->enrol( $person, self::COURSE );

        [ $header, $rows ] = LearningReports::exportCourseRows();

        $this->assertNotEmpty( $header );
        $this->assertNotEmpty( $rows );

        $flat = implode( '|', array_merge( $header, $rows[0] ) );

        // #2012 — status columns are for people.
        $this->assertStringNotContainsString( 'not_started', $flat );
        $this->assertStringNotContainsString( 'in_progress', $flat );
        $this->assertStringContainsString( 'Nobody has finished yet', $flat );
    }

    public function test_status_labels_are_humanised(): void {
        $this->assertSame( 'Completed', LearningReports::statusLabel( EnrolmentRepository::STATUS_COMPLETED ) );
        $this->assertSame( 'In progress', LearningReports::statusLabel( EnrolmentRepository::STATUS_IN_PROGRESS ) );
        $this->assertSame( 'Not started', LearningReports::statusLabel( EnrolmentRepository::STATUS_NOT_STARTED ) );
    }
}
