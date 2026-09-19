<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Reports\AttendanceGridQuery;
use TT\Modules\Activities\Services\ActivityGridLink;
use TT\Modules\Activities\Services\EmptyRegisterConfirm;
use TT\Shared\Frontend\Components\BackLink;

/**
 * #3656 — the way into the attendance grid must lead to a column.
 *
 * Since #3586 the grid carries an upcoming activity only once something has
 * been recorded on it, but the entry links did not know that rule: opening
 * next Monday's training and pressing "Record attendance" landed the coach
 * on "No activities for this team in the chosen period".
 *
 * Also covers the second defect on that screen: the period pills and the
 * Clear link copied `tt_back` into `add_query_arg()` unencoded, so a back
 * target with a query string of its own spilled into the link.
 */
final class AttendanceGridLinkColumnTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;
    private int $player = 0;
    private int $user = 0;
    private int $past = 0;
    private int $upcoming = 0;
    private int $upcoming_marked = 0;

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

        $this->past            = $this->activity( $this->offsetDays( -1 ) );
        $this->upcoming        = $this->activity( $this->offsetDays( 9 ) );
        $this->upcoming_marked = $this->activity( $this->offsetDays( 10 ) );
        $this->mark( $this->upcoming_marked, 'Absent' );

        $this->user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        unset( $_GET[ BackLink::PARAM ] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_column_rule_answers_for_one_activity(): void {
        $today = current_time( 'Y-m-d' );

        $this->assertTrue(
            AttendanceGridQuery::isColumn( $this->past, $this->offsetDays( -1 ), $today ),
            'an activity that has taken place is always a column'
        );
        $this->assertTrue(
            AttendanceGridQuery::isColumn( $this->past, $today, $today ),
            'today is not the future'
        );
        $this->assertFalse(
            AttendanceGridQuery::isColumn( $this->upcoming, $this->offsetDays( 9 ), $today ),
            'an upcoming activity with nothing on it has no register to enter'
        );
        $this->assertTrue(
            AttendanceGridQuery::isColumn( $this->upcoming_marked, $this->offsetDays( 10 ), $today ),
            'a pre-recorded absence makes it a column'
        );

        // The transition itself: the same activity, once it carries a mark.
        $this->assertFalse( AttendanceGridQuery::hasRecordedMark( $this->upcoming ) );
        $this->mark( $this->upcoming, 'Excused' );
        $this->assertTrue( AttendanceGridQuery::hasRecordedMark( $this->upcoming ) );
        $this->assertTrue( AttendanceGridQuery::isColumn( $this->upcoming, $this->offsetDays( 9 ), $today ) );
    }

    public function test_a_planned_squad_row_is_not_a_recorded_mark(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $this->upcoming,
            'player_id'   => $this->player,
            'is_guest'    => 0,
            'status'      => 'Present',
            'record_type' => 'planned',
        ] );

        $this->assertFalse(
            AttendanceGridQuery::hasRecordedMark( $this->upcoming ),
            'the planned line-up is a forecast, not a register'
        );
        $this->assertFalse( ActivityGridLink::canUseAttendance( $this->upcoming, $this->user ) );
    }

    public function test_the_entry_links_follow_the_column_rule(): void {
        $this->assertTrue(
            ActivityGridLink::canUseAttendance( $this->past, $this->user ),
            'an activity that has taken place keeps its grid link'
        );
        $this->assertTrue(
            ActivityGridLink::canUseAttendance( $this->upcoming_marked, $this->user ),
            'a pre-recorded absence is worth opening the grid for'
        );
        $this->assertFalse(
            ActivityGridLink::canUseAttendance( $this->upcoming, $this->user ),
            'no column, no link'
        );

        // The empty-register dialog behind "Mark completed" offers
        // "Record attendance" only when there is somewhere to record it.
        $this->assertSame( '', EmptyRegisterConfirm::recordUrl( $this->upcoming, $this->user ) );
        $this->assertNotSame( '', EmptyRegisterConfirm::recordUrl( $this->past, $this->user ) );
    }

    public function test_the_grid_still_carries_a_past_activity_over_rest(): void {
        $from = $this->offsetDays( -7 );
        $to   = current_time( 'Y-m-d' );

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/activities/attendance-grid' );
        $request->set_query_params( [ 'team_id' => $this->team, 'from' => $from, 'to' => $to ] );
        $response = rest_do_request( $request );
        $data     = (array) $response->get_data();

        $this->assertSame( 200, (int) $response->get_status() );
        $columns = array_column( $data['data']['activities'], 'activity_id' );
        $this->assertContains( $this->past, $columns, 'no regression to #3586' );
        $this->assertNotContains( $this->upcoming, $columns );
    }

    public function test_the_back_target_survives_a_query_string_of_its_own(): void {
        $back = home_url( '/?tt_view=activities&team_id=' . $this->team );
        $_GET[ BackLink::PARAM ] = $back;

        $this->assertSame( $back, BackLink::currentValue(), 'the hidden field takes the plain value' );

        $url = add_query_arg(
            array_merge( [ 'tt_view' => 'attendance-grid', 'period' => 'month' ], BackLink::carryArgs() ),
            home_url( '/' )
        );
        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        $args  = [];
        parse_str( $query, $args );

        $this->assertSame( $back, $args[ BackLink::PARAM ] ?? '', 'one parameter, not three' );
        $this->assertArrayNotHasKey( 'team_id', $args, "the back target's own args must not leak out" );
        $this->assertSame( 'month', $args['period'] ?? '' );
    }

    public function test_a_cross_origin_back_target_is_dropped(): void {
        $_GET[ BackLink::PARAM ] = 'https://elders.example.com/steal';

        $this->assertSame( '', BackLink::currentValue() );
        $this->assertSame( [], BackLink::carryArgs() );
    }

    private function offsetDays( int $days ): string {
        $sign = $days < 0 ? '-' : '+';
        return (string) gmdate(
            'Y-m-d',
            (int) strtotime( current_time( 'Y-m-d' ) . ' ' . $sign . abs( $days ) . ' days' )
        );
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

    private function mark( int $activity_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $this->player,
            'is_guest'    => 0,
            'status'      => $status,
            'record_type' => 'actual',
        ] );
    }
}
