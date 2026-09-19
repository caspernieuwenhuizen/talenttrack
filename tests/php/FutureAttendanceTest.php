<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Reports\AttendanceGridQuery;
use TT\Modules\Activities\Services\AttendanceDateRule;

/**
 * #3586 — a player cannot have been present at an activity that has not
 * happened.
 *
 * `POST attendance/bulk` saved a full "present" register on a training three
 * days ahead with a clean 200, and the grid's window ended today so the marks
 * could never be seen or cleared there. Present / late on a future activity
 * is now refused on all three write paths; absences stay allowed, and the
 * grid shows an upcoming activity once it carries one.
 */
final class FutureAttendanceTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;
    private int $player = 0;
    private int $tomorrow = 0;
    private int $yesterday = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'O11-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team,
            'first_name' => 'Noor', 'last_name' => 'Smit', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->tomorrow  = $this->activity( gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +1 day' ) ) );
        $this->yesterday = $this->activity( gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 day' ) ) );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_rule(): void {
        $today = '2026-09-19';
        $this->assertTrue( AttendanceDateRule::refuses( 'present', '2026-09-20', $today ) );
        $this->assertTrue( AttendanceDateRule::refuses( 'Late', '2026-09-20 18:00:00', $today ) );
        $this->assertFalse( AttendanceDateRule::refuses( 'Present', '2026-09-19', $today ), 'today is not the future' );
        $this->assertFalse( AttendanceDateRule::refuses( 'absent', '2026-09-20', $today ) );
        $this->assertFalse( AttendanceDateRule::refuses( 'Excused', '2026-09-20', $today ) );
        $this->assertFalse( AttendanceDateRule::refuses( '', '2026-09-20', $today ), 'clearing a cell' );
    }

    public function test_the_grid_refuses_present_on_tomorrow_and_keeps_an_absence(): void {
        [ $data, $status ] = $this->send( 'POST', 'attendance/bulk', [ 'changes' => [
            [ 'activity_id' => $this->tomorrow,  'player_id' => $this->player, 'status' => 'present' ],
            [ 'activity_id' => $this->yesterday, 'player_id' => $this->player, 'status' => 'present' ],
        ] ] );

        $this->assertSame( 200, $status );
        $this->assertSame( 1, $data['data']['saved'] );
        $this->assertSame( 1, $data['data']['rejected_count'] );
        $this->assertSame( $this->tomorrow, $data['data']['rejected'][0]['activity_id'] );
        $this->assertSame( 'future_attendance', $data['data']['rejected'][0]['reason'] );
        $this->assertNotSame( '', $data['data']['message'] );
        $this->assertSame( [], $this->actualStatuses( $this->tomorrow ) );

        [ $data ] = $this->send( 'POST', 'attendance/bulk', [ 'changes' => [
            [ 'activity_id' => $this->tomorrow, 'player_id' => $this->player, 'status' => 'absent' ],
        ] ] );
        $this->assertSame( 1, $data['data']['saved'] );
        $this->assertSame( 0, $data['data']['rejected_count'] );
        $this->assertSame( [ 'Absent' ], $this->actualStatuses( $this->tomorrow ) );
    }

    public function test_the_activity_form_refuses_present_on_a_future_activity(): void {
        [ $data, $status ] = $this->send( 'PUT', 'activities/' . $this->tomorrow, [
            'attendance' => [ $this->player => [ 'status' => 'Present' ] ],
        ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'future_attendance', $data['errors'][0]['code'] ?? null );
        $this->assertSame( [], $this->actualStatuses( $this->tomorrow ) );

        [ , $status ] = $this->send( 'PUT', 'activities/' . $this->tomorrow, [
            'attendance' => [ $this->player => [ 'status' => 'Absent' ] ],
        ] );
        $this->assertSame( 200, $status );
        $this->assertSame( [ 'Absent' ], $this->actualStatuses( $this->tomorrow ) );
        global $wpdb;
        $this->assertSame( 'scheduled', (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT plan_state FROM {$wpdb->prefix}tt_activities WHERE id = %d", $this->tomorrow
        ) ), 'a pre-recorded absence does not mark the activity held' );

        [ $data, $status ] = $this->send( 'POST', 'activities', [
            'title'        => 'Next training',
            'session_date' => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +2 days' ) ),
            'team_id'           => $this->team,
            'activity_type_key' => 'training',
            'attendance'        => [ $this->player => [ 'status' => 'Late' ] ],
        ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'future_attendance', $data['errors'][0]['code'] ?? null );
    }

    public function test_patching_a_row_to_present_on_a_future_activity_is_refused(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id' => $this->club, 'activity_id' => $this->tomorrow, 'player_id' => $this->player,
            'is_guest' => 0, 'status' => 'Absent', 'record_type' => 'actual',
        ] );
        $row_id = (int) $wpdb->insert_id;

        [ $data, $status ] = $this->send( 'PATCH', 'attendance/' . $row_id, [ 'status' => 'Present' ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'future_attendance', $data['errors'][0]['code'] ?? null );
        $this->assertSame( [ 'Absent' ], $this->actualStatuses( $this->tomorrow ) );
    }

    public function test_the_grid_shows_an_upcoming_activity_only_once_it_carries_a_mark(): void {
        $today   = current_time( 'Y-m-d' );
        $from    = gmdate( 'Y-m-d', strtotime( $today . ' -7 days' ) );
        $columns = fn(): array => array_column(
            ( new AttendanceGridQuery() )->matrix( $this->team, $from, $today )['activities'],
            'activity_id'
        );

        $this->assertContains( $this->yesterday, $columns() );
        $this->assertNotContains( $this->tomorrow, $columns() );

        $this->send( 'POST', 'attendance/bulk', [ 'changes' => [
            [ 'activity_id' => $this->tomorrow, 'player_id' => $this->player, 'status' => 'excused' ],
        ] ] );

        $this->assertContains( $this->tomorrow, $columns() );

        [ $data ] = $this->send( 'GET', 'activities/attendance-grid?team_id=' . $this->team . '&from=' . $from );
        $this->assertContains( $this->tomorrow, array_column( $data['data']['activities'], 'activity_id' ) );
    }

    private function activity( string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Training ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return list<string> */
    private function actualStatuses( int $activity_id ): array {
        global $wpdb;
        return array_map( 'strval', $wpdb->get_col( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id = %d AND record_type = 'actual' AND is_guest = 0",
            $activity_id
        ) ) );
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body = [] ): array {
        $path  = '/talenttrack/v1/' . $route;
        $query = [];
        if ( strpos( $path, '?' ) !== false ) {
            [ $path, $qs ] = explode( '?', $path, 2 );
            parse_str( $qs, $query );
        }
        $request = new WP_REST_Request( $method, $path );
        if ( $query ) $request->set_query_params( $query );
        if ( $body ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_do_request( $request );
        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }
}
