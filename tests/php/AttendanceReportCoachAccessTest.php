<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\REST\ReportsRestController;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Analytics\Frontend\FrontendAttendanceLeaderboardView;
use TT\Modules\Analytics\Frontend\FrontendAttendancePlayerReportView;
use TT\Modules\Analytics\Frontend\FrontendAttendanceTeamReportView;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\PersonaDashboard\Kpis\MyTeamAttendancePct;

/**
 * #4579 — the three attendance reports, their REST routes and their tiles
 * gate on the matrix's `attendance` read, with record scope, not on
 * `tt_view_analytics`, which no coach holds. A head coach tapped their own
 * attendance figure on the dashboard and was told they had no permission
 * for the report behind it.
 *
 * Coaches are not given analytics read by this: the head coach here is
 * asserted not to hold it.
 */
final class AttendanceReportCoachAccessTest extends WP_UnitTestCase {

    private int $club      = 1;
    private int $myTeam    = 0;
    private int $otherTeam = 0;
    private int $coach     = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p = $wpdb->prefix;

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->myTeam    = $this->team( 'Gate JO13-1' );
        $this->otherTeam = $this->team( 'Gate JO17-2' );

        // One present and one absent on each team, inside the dashboard
        // KPI's 28-day window: 50% on both.
        foreach ( [ $this->myTeam => [ 'Mila', 'Mees' ], $this->otherTeam => [ 'Otto', 'Olaf' ] ] as $team => $names ) {
            $activity = $this->activity( $team, gmdate( 'Y-m-d', strtotime( '-5 days' ) ) );
            $this->attendance( $activity, $this->player( $team, $names[0] ), 'present' );
            $this->attendance( $activity, $this->player( $team, $names[1] ), 'absent' );
        }

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Gate', 'last_name' => 'Coach',
            'role_type' => 'head_coach', 'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $this->myTeam, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => $person, 'role_id' => 1,
            'scope_type' => 'team', 'scope_id' => $this->myTeam,
        ] );
        AuthorizationService::flushCache();

        foreach ( [ 'report_attendance_report_team', 'report_attendance_report_player', 'report_attendance_leaderboard' ] as $feature ) {
            \TT\Core\FeatureRegistry::setEnabled( $feature, true );
        }

        ReportsRestController::init();
        do_action( 'rest_api_init' );
        $_GET = [];
    }

    public function tear_down(): void {
        $_GET = [];
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // The gate

    public function test_a_head_coach_reads_attendance_for_their_own_team_only(): void {
        wp_set_current_user( $this->coach );
        $this->assertFalse( current_user_can( 'tt_view_analytics' ), 'coaches do not get analytics read' );
        $this->assertTrue( AuthorizationService::canOpenAttendanceReports( $this->coach ) );
        $this->assertSame( [ $this->myTeam ], AuthorizationService::attendanceReadableTeamIds( $this->coach ) );
        $this->assertTrue( AuthorizationService::canReadTeamAttendance( $this->coach, $this->myTeam ) );
        $this->assertFalse( AuthorizationService::canReadTeamAttendance( $this->coach, $this->otherTeam ) );
    }

    public function test_the_head_of_development_and_admin_keep_the_academy_wide_list(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertNull( AuthorizationService::attendanceReadableTeamIds( $admin ) );

        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        AuthorizationService::flushCache();
        $this->assertNull( AuthorizationService::attendanceReadableTeamIds( $hod ), 'global attendance read' );
    }

    public function test_a_parent_holds_no_report_access(): void {
        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        AuthorizationService::flushCache();
        $this->assertFalse( AuthorizationService::canOpenAttendanceReports( $parent ), 'player-scope read on their child opens no report' );
    }

    // The team report

    public function test_a_head_coach_opens_the_team_report_with_the_dashboard_figure(): void {
        wp_set_current_user( $this->coach );
        $kpi = ( new MyTeamAttendancePct() )->compute( $this->coach, $this->club );
        $this->assertTrue( $kpi->is_available );
        $this->assertSame( '50%', $kpi->current );

        $_GET = [
            'from' => gmdate( 'Y-m-d', strtotime( '-28 days' ) ),
            'to'   => gmdate( 'Y-m-d' ),
        ];
        $html = $this->render( FrontendAttendanceTeamReportView::class );
        $this->assertStringNotContainsString( 'You do not have permission to view this report.', $html );
        $this->assertStringContainsString( 'Gate JO13-1', $html );
        $this->assertStringNotContainsString( 'Gate JO17-2', $html, 'another team\'s row never reaches the coach' );

        $rows = ( new AttendanceRankingQuery() )->teamRows( $_GET['from'], $_GET['to'], [ $this->myTeam ] );
        $this->assertCount( 1, $rows );
        $this->assertSame( 50.0, (float) $rows[0]['present_pct'], 'the report row matches the KPI' );
    }

    public function test_a_viewer_without_attendance_read_is_refused_the_reports(): void {
        $observer = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $observer );

        foreach ( [ FrontendAttendanceTeamReportView::class, FrontendAttendancePlayerReportView::class, FrontendAttendanceLeaderboardView::class ] as $view ) {
            $this->assertStringContainsString(
                'You do not have permission to view this report.',
                $this->render( $view, $observer ),
                $view
            );
        }
    }

    // The player report

    public function test_the_player_report_refuses_another_teams_players_on_the_page(): void {
        wp_set_current_user( $this->coach );

        $_GET = [ 'team_id' => (string) $this->myTeam ];
        $own  = $this->render( FrontendAttendancePlayerReportView::class );
        $this->assertStringContainsString( 'Mila', $own );

        $_GET   = [ 'team_id' => (string) $this->otherTeam ];
        $theirs = $this->render( FrontendAttendancePlayerReportView::class );
        $this->assertStringContainsString( 'You do not have access to this team’s players.', $theirs );
        $this->assertStringNotContainsString( 'Otto', $theirs );
        $this->assertStringNotContainsString( 'Olaf', $theirs );
    }

    // The leaderboard

    public function test_a_head_coachs_leaderboard_lists_only_their_teams_players(): void {
        wp_set_current_user( $this->coach );
        $html = $this->render( FrontendAttendanceLeaderboardView::class );
        $this->assertStringNotContainsString( 'You do not have permission to view this report.', $html );

        $board = $this->get( '/talenttrack/v1/reports/attendance-leaderboard', 0 );
        $this->assertSame( 200, $board->get_status() );
        $names = wp_json_encode( $board->get_data() );
        $this->assertStringContainsString( 'Mila', (string) $names );
        $this->assertStringNotContainsString( 'Otto', (string) $names, 'the board ranks the coach\'s players only' );
        $this->assertStringNotContainsString( 'Olaf', (string) $names );
    }

    // REST

    public function test_a_head_coach_reads_their_team_over_rest_and_is_refused_another(): void {
        wp_set_current_user( $this->coach );
        foreach ( [ '/reports/attendance', '/reports/attendance-leaderboard', '/reports/attendance-at-risk' ] as $route ) {
            $this->assertSame( 200, $this->get( '/talenttrack/v1' . $route, $this->myTeam )->get_status(), $route );

            $refused = $this->get( '/talenttrack/v1' . $route, $this->otherTeam );
            $this->assertSame( 403, $refused->get_status(), $route . ' refuses another team' );
            $this->assertStringNotContainsString( 'Otto', (string) wp_json_encode( $refused->get_data() ) );
        }
    }

    public function test_a_viewer_without_attendance_read_is_refused_over_rest(): void {
        $observer = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $observer );
        foreach ( [ '/reports/attendance', '/reports/attendance-leaderboard', '/reports/attendance-at-risk' ] as $route ) {
            $this->assertSame( 403, $this->get( '/talenttrack/v1' . $route, 0 )->get_status(), $route );
        }
    }

    // Helpers

    private function render( string $view, int $user_id = 0 ): string {
        $user_id = $user_id > 0 ? $user_id : $this->coach;
        ob_start();
        $view::render( $user_id, false );
        return (string) ob_get_clean();
    }

    private function get( string $route, int $team_id ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', $route );
        if ( $team_id > 0 ) $req->set_param( 'team_id', $team_id );
        return rest_get_server()->dispatch( $req );
    }

    private function team( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function player( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Poort',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function activity( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Training ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'training',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function attendance( int $activity_id, int $player_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'is_guest'    => 0,
            'record_type' => 'actual',
        ] );
    }
}
