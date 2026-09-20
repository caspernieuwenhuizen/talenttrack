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

    /* ===== per team (#3769) ===== */

    /**
     * #3769 — somebody the academy never put on the course has no state on
     * it. Reporting them as `not_started` made "nobody ever asked this
     * coach" read exactly like "we asked and they have not begun", and the
     * only way to tell was to try enrolling them and watch for a new row.
     */
    public function test_team_learning_lists_enrolled_staff_only(): void {
        $team = $this->makeTeam( 'JO17-1' );
        $repo = new EnrolmentRepository();

        [ , $waiting ] = $this->makePerson( 'Waiting' );
        [ , $absent ]  = $this->makePerson( 'Absent' );
        [ , $done ]    = $this->makePerson( 'Done' );

        foreach ( [ $waiting, $absent, $done ] as $person ) {
            $this->assignToTeam( $person, $team );
        }

        $repo->enrol( $waiting, self::COURSE );
        $repo->enrol( $done, self::COURSE );
        $repo->markCompleted( (int) $repo->findFor( $done, self::COURSE )->id );

        $course = $this->teamCourse( $team );
        $ids    = array_column( $course['staff'], 'person_id' );

        $this->assertNotContains( $absent, $ids, 'Never enrolled is not a row on this list.' );
        $this->assertContains( $waiting, $ids );
        $this->assertContains( $done, $ids );

        $statuses = array_column( $course['staff'], 'status', 'person_id' );
        $this->assertSame( EnrolmentRepository::STATUS_NOT_STARTED, $statuses[ $waiting ] );
        $this->assertSame( EnrolmentRepository::STATUS_COMPLETED, $statuses[ $done ] );

        $this->assertSame( 2, $course['total'], 'total counts the enrolled.' );
        $this->assertSame( 1, $course['done'] );
        $this->assertSame( 3, $course['assigned'], 'assigned still counts the whole staff.' );
        $this->assertSame( 1, $course['unenrolled'] );

        $this->deleteTeam( $team );
    }

    /**
     * #3769 — the reported mismatch. The roll-up counted enrolment rows
     * club-wide and the team view counted team-assigned people, and both
     * numbers appeared in one response, where they read as a bug.
     */
    public function test_team_totals_and_course_statistics_agree(): void {
        $team = $this->makeTeam( 'JO15-1' );
        $repo = new EnrolmentRepository();

        [ , $waiting ] = $this->makePerson( 'Waiting' );
        [ , $absent ]  = $this->makePerson( 'Absent' );
        [ , $done ]    = $this->makePerson( 'Done' );

        foreach ( [ $waiting, $absent, $done ] as $person ) {
            $this->assignToTeam( $person, $team );
        }

        $repo->enrol( $waiting, self::COURSE );
        $repo->enrol( $done, self::COURSE );
        $repo->markCompleted( (int) $repo->findFor( $done, self::COURSE )->id );

        $team_course = $this->teamCourse( $team );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/courses/' . self::COURSE . '/statistics' )
        );
        $stats = $response->get_data()['data'];

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $stats['enrolled'], $team_course['total'] );
        $this->assertSame( $stats['completed'], $team_course['done'] );

        $row = null;
        foreach ( $stats['teams'] as $entry ) {
            if ( (int) $entry['team_id'] === $team ) $row = $entry;
        }

        $this->assertNotNull( $row, 'the team is on the roll-up too' );
        $this->assertSame( $team_course['total'], $row['total'] );
        $this->assertSame( $team_course['done'], $row['done'] );

        $this->deleteTeam( $team );
    }

    /** #3769 — each row carries its deadline and whether it has passed. */
    public function test_team_learning_rows_carry_the_deadline_and_an_overdue_flag(): void {
        $team = $this->makeTeam( 'JO13-1' );
        $repo = new EnrolmentRepository();

        [ , $late ]  = $this->makePerson( 'Late' );
        [ , $ahead ] = $this->makePerson( 'Ahead' );

        $this->assignToTeam( $late, $team );
        $this->assignToTeam( $ahead, $team );

        $repo->enrol( $late, self::COURSE, [ 'due_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ) ] );
        $repo->enrol( $ahead, self::COURSE, [ 'due_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ) ] );

        $rows = array_column( $this->teamCourse( $team )['staff'], null, 'person_id' );

        $this->assertNotNull( $rows[ $late ]['due_at'] );
        $this->assertTrue( $rows[ $late ]['is_overdue'] );
        $this->assertNotNull( $rows[ $ahead ]['due_at'] );
        $this->assertFalse( $rows[ $ahead ]['is_overdue'] );

        // The row flag and the roll-up's count answer from one rule.
        $this->assertSame(
            1,
            ( new LearningStatisticsService() )->countsFor( self::COURSE, $team )['overdue']
        );

        $this->deleteTeam( $team );
    }

    /**
     * #3769 — a team whose staff are assigned but unenrolled must say so.
     * Dropping the invented `not_started` rows would otherwise leave the
     * panel looking broken rather than empty on purpose.
     */
    public function test_a_team_with_nobody_enrolled_gets_an_explicit_empty_state(): void {
        $team = $this->makeTeam( 'JO19-1' );

        [ , $person ] = $this->makePerson( 'Unenrolled' );
        $this->assignToTeam( $person, $team );

        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );

        ob_start();
        LearningReports::renderTeams( $admin );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Nobody on these teams is enrolled', $html );
        $this->assertStringNotContainsString( 'tt-learning-chip--none', $html, 'no table is drawn at all' );

        $this->deleteTeam( $team );
    }

    /* ===== team helpers ===== */

    private function makeTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => $name ] );

        return (int) $wpdb->insert_id;
    }

    private function deleteTeam( int $team_id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_teams', [ 'id' => $team_id ] );
    }

    private function assignToTeam( int $person_id, int $team_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'club_id'    => 1,
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
    }

    /**
     * `GET /teams/{id}/learning?course=…`, unwrapped to the one course.
     *
     * @return array<string, mixed>
     */
    private function teamCourse( int $team_id ): array {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/teams/' . $team_id . '/learning' );
        $request->set_param( 'course', self::COURSE );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );

        return $response->get_data()['data']['courses'][0];
    }
}
