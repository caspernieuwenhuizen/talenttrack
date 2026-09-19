<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3585 — the activities list counts the register, not the planned squad.
 *
 * `searchForRest()` counted every `tt_attendance` row for the activity. The
 * planned squad is stored there too, as `record_type = 'expected'` rows with
 * Expected mapped to `Present`, so a match nobody had registered listed as
 * "16 recorded, 16 present" and landed in the `complete` attendance bucket.
 * Each case is asserted in both directions: the planned-only activity counts
 * nothing, and the recorded rows still count.
 */
final class ActivitiesListPlannedAttendanceTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;
    private int $activity = 0;

    /** @var list<int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO13-1' ] );
        $this->team = (int) $wpdb->insert_id;

        for ( $i = 1; $i <= 16; $i++ ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id'    => $this->club,
                'team_id'    => $this->team,
                'first_name' => 'Speler',
                'last_name'  => (string) $i,
                'status'     => 'active',
            ] );
            $this->players[] = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Wedstrijd',
            'session_date'        => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
            'activity_type_key'   => 'match',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        $this->activity = (int) $wpdb->insert_id;

        foreach ( $this->players as $player_id ) {
            $this->attendance( $player_id, 'expected' );
        }

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_planned_squad_is_not_a_register(): void {
        $row = $this->row( [] );

        $this->assertSame( 0, (int) $row['attendance_count'] );
        $this->assertSame( 0, (int) $row['present_count'] );
    }

    public function test_recorded_rows_still_count(): void {
        foreach ( array_slice( $this->players, 0, 3 ) as $player_id ) {
            $this->attendance( $player_id, 'actual' );
        }

        $row = $this->row( [] );

        $this->assertSame( 3, (int) $row['attendance_count'] );
        $this->assertSame( 3, (int) $row['present_count'] );
    }

    public function test_the_attendance_filter_buckets_on_the_register(): void {
        $this->assertContains( $this->activity, $this->ids( 'none' ) );
        $this->assertNotContains( $this->activity, $this->ids( 'complete' ) );

        foreach ( array_slice( $this->players, 0, 3 ) as $player_id ) {
            $this->attendance( $player_id, 'actual' );
        }

        $this->assertContains( $this->activity, $this->ids( 'partial' ) );
        $this->assertNotContains( $this->activity, $this->ids( 'none' ) );
    }

    private function attendance( int $player_id, string $record_type ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $this->activity,
            'player_id'   => $player_id,
            'is_guest'    => 0,
            'status'      => 'Present',
            'record_type' => $record_type,
        ] );
    }

    /**
     * @param array<string,mixed> $filter
     * @return list<array<string,mixed>>
     */
    private function rows( array $filter ): array {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( [
            'filter'   => [ 'team_id' => $this->team ] + $filter,
            'per_page' => 100,
        ] );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );

        return array_values( (array) ( $res->get_data()['data']['rows'] ?? [] ) );
    }

    /**
     * @param array<string,mixed> $filter
     * @return array<string,mixed>
     */
    private function row( array $filter ): array {
        foreach ( $this->rows( $filter ) as $row ) {
            if ( (int) ( $row['id'] ?? 0 ) === $this->activity ) return (array) $row;
        }
        $this->fail( 'The activity is missing from the list.' );
    }

    /** @return list<int> */
    private function ids( string $bucket ): array {
        return array_map(
            static fn( $row ): int => (int) ( $row['id'] ?? 0 ),
            $this->rows( [ 'attendance' => $bucket ] )
        );
    }
}
