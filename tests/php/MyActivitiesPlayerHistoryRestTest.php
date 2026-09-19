<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3669 — the data side of a player's "My activities" history.
 *
 * The screen showed "nothing recorded for you yet" to a player with a full
 * season behind them. The fault was in the list-table shell, which painted
 * the empty state before the fetch landed and kept it when the fetch failed.
 * This pins the contract that shell hydrates from: the exact request it
 * builds from the view's `static_filters`, made as the linked player, returns
 * that player's past activities with their recorded attendance.
 */
final class MyActivitiesPlayerHistoryRestTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;
    private int $player = 0;
    private int $user = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $this->user = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U11 History' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'History',
            'last_name'  => 'Player',
            'status'     => 'active',
            'wp_user_id' => $this->user,
        ] );
        $this->player = (int) $wpdb->insert_id;

        wp_set_current_user( $this->user );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_linked_player_gets_their_past_activities_with_attendance(): void {
        $training = $this->activity( gmdate( 'Y-m-d', strtotime( '-10 days' ) ), 'training' );
        $match    = $this->activity( gmdate( 'Y-m-d', strtotime( '-3 days' ) ), 'match' );
        $future   = $this->activity( gmdate( 'Y-m-d', strtotime( '+5 days' ) ), 'training' );
        $this->attendance( $training, 'Present' );
        $this->attendance( $match, 'Absent' );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( [
            'filter'   => [
                'player_id' => (string) $this->player,
                'date_to'   => gmdate( 'Y-m-d' ),
            ],
            'orderby'  => 'session_date',
            'order'    => 'desc',
            'page'     => 1,
            'per_page' => 25,
        ] );
        $res = rest_do_request( $req );

        $this->assertSame( 200, $res->get_status() );
        $data = (array) $res->get_data();
        $this->assertTrue( (bool) ( $data['success'] ?? false ) );

        $by_id = [];
        foreach ( (array) ( $data['data']['rows'] ?? [] ) as $row ) {
            $row = (array) $row;
            $by_id[ (int) ( $row['id'] ?? 0 ) ] = $row;
        }

        $this->assertArrayHasKey( $training, $by_id, 'a past training is part of the history' );
        $this->assertArrayHasKey( $match, $by_id, 'a past match is part of the history' );
        $this->assertArrayNotHasKey( $future, $by_id, 'date_to bounds the history at today' );

        $this->assertSame( 'Present', $by_id[ $training ]['your_attendance_status'] ?? null );
        $this->assertSame( 'Absent', $by_id[ $match ]['your_attendance_status'] ?? null );
    }

    private function activity( string $date, string $type ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => ucfirst( $type ) . ' ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => $type,
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function attendance( int $activity_id, string $status ): void {
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
