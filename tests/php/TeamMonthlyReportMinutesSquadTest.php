<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Analytics\Reports\MinutesShareQuery;

/**
 * #3589 — the monthly report's minutes block lists the whole squad, and
 * uses the academy's one minutes-share target.
 *
 * The block was built from `MinutesQuery::forTeam()`, which only returns
 * players who got on the pitch, so a squad player who was available and
 * never played (the player the block exists to flag) was missing, and the
 * median was worked out without them. Its target was a hardcoded 50 while
 * the minutes-share report read the configured 30.
 */
final class TeamMonthlyReportMinutesSquadTest extends WP_UnitTestCase {

    private int $team = 0;
    /** @var list<int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Monthly O11', 'age_group' => 'U11' ] );
        $this->team = (int) $wpdb->insert_id;
        foreach ( [ 'Een', 'Twee', 'Bank' ] as $last ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => 1, 'team_id' => $this->team, 'first_name' => 'Speler', 'last_name' => $last, 'status' => 'active',
            ] );
            $this->players[] = (int) $wpdb->insert_id;
        }

        // One match in March 2020: two players get minutes, the third sits.
        $wpdb->insert( "{$p}tt_activities", [
            'club_id' => 1, 'team_id' => $this->team, 'title' => 'Thuis', 'session_date' => '2020-03-07',
            'activity_type_key' => 'game', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        $match = (int) $wpdb->insert_id;
        foreach ( $this->players as $i => $pid ) {
            $wpdb->insert( "{$p}tt_attendance", [
                'club_id' => 1, 'activity_id' => $match, 'player_id' => $pid, 'is_guest' => 0,
                'status' => 'Present', 'record_type' => 'actual',
                'minutes_played' => $i < 2 ? 70 : null,
            ] );
        }

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_player_who_did_not_play_is_in_the_block(): void {
        $minutes = $this->minutesBlock();

        $this->assertCount( 3, $minutes['rows'] );
        $bench = null;
        foreach ( $minutes['rows'] as $row ) {
            if ( (int) $row['player_id'] === $this->players[2] ) $bench = $row;
        }
        $this->assertNotNull( $bench, 'the squad player with no minutes is listed' );
        $this->assertSame( 0, $bench['total_minutes'] );
        $this->assertEquals( 0, $bench['share_pct'] );
        $this->assertSame( 70, $bench['available_minutes'] );

        // 100, 100, 0: the median counts the player who sat.
        $this->assertEquals( 100, $minutes['median_share_pct'] );
        $this->assertSame( $minutes['rows'][0]['available_minutes'], $bench['available_minutes'] );
    }

    public function test_the_target_is_the_academys_minutes_share_target(): void {
        $this->assertSame( MinutesShareQuery::targetPct(), $this->minutesBlock()['target_pct'] );

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/teams/' . $this->team . '/minutes-share' );
        $share   = (array) rest_do_request( $request )->get_data();
        $this->assertSame( $share['data']['target_pct'], $this->minutesBlock()['target_pct'] );

        \TT\Infrastructure\Query\QueryHelpers::set_config( 'minutes_share_target_pct', '40' );
        $this->assertSame( 40, $this->minutesBlock()['target_pct'] );
    }

    /** @return array<string,mixed> */
    private function minutesBlock(): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/teams/' . $this->team . '/monthly-report' );
        $request->set_query_params( [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'blocks' => 'minutes' ] );
        $response = rest_do_request( $request );
        $this->assertSame( 200, $response->get_status() );

        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return (array) ( $data['data']['data']['minutes'] ?? [] );
    }
}
