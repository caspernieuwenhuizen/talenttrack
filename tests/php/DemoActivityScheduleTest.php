<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoRoster;
use TT\Modules\DemoData\Generators\ActivityGenerator;

/**
 * #3660 / #3676 — a demo academy keeps a real weekly rhythm.
 *
 * Every slot used to be an offset from "now minus N weeks", so the weekday
 * was whatever day the generator ran on: a Monday run put every match on a
 * Thursday and none on a Saturday. And no generated activity had a time, so
 * the week planner, the activity detail and the parent views had nothing to
 * show. Trainings now fall on Tuesday and Thursday, the fixture on Saturday,
 * and every row carries its times.
 */
final class DemoActivityScheduleTest extends WP_UnitTestCase {

    /** Mid-September, pinned per DemoSeasonCadenceTest: Tuesday 2026-09-15 00:00 UTC. */
    private const NOW = 1789430400;

    /** @return array<string, array{int}> */
    public function runDayProvider(): array {
        return [
            'a Monday morning'      => [ 1789372800 ], // 2026-09-14 08:00 UTC
            'a Wednesday afternoon' => [ 1789570800 ], // 2026-09-16 15:00 UTC
            'a Saturday night'      => [ 1789858800 ], // 2026-09-19 23:00 UTC
            'a Sunday at midnight'  => [ 1789862400 ], // 2026-09-20 00:00 UTC
        ];
    }

    /**
     * @dataProvider runDayProvider
     */
    public function test_fixtures_fall_on_saturday_and_trainings_on_tuesday_or_thursday( int $now ): void {
        $slots = ( new DemoCalendar( 16, $now ) )->activitySlots();

        $games = 0;
        foreach ( $slots as $slot ) {
            $weekday = gmdate( 'N', strtotime( $slot['date'] . ' 00:00:00 UTC' ) );
            if ( $slot['is_game'] ) {
                $games++;
                $this->assertSame( '6', $weekday, 'a fixture is on Saturday, youth match day' );
            } else {
                $this->assertContains( $weekday, [ '2', '4' ], 'a training is on Tuesday or Thursday' );
            }
            $this->assertSame( $slot['date'], gmdate( 'Y-m-d', $slot['ts'] ), 'ts is on the slot date' );
        }

        $this->assertCount( 2 * ( 16 + DemoCalendar::HORIZON_WEEKS ), $slots, 'two slots a week, unchanged' );
        $this->assertSame( intdiv( 16 + DemoCalendar::HORIZON_WEEKS, 3 ), $games, 'one game every third week, unchanged' );
    }

    /**
     * @dataProvider runDayProvider
     */
    public function test_no_slot_lands_before_the_window_and_the_horizon_is_ahead( int $now ): void {
        $calendar = new DemoCalendar( 8, $now );
        $slots    = $calendar->activitySlots();
        $window   = gmdate( 'Y-m-d', $calendar->windowStart() );

        $this->assertGreaterThan( $window, $slots[0]['date'], 'the roster opens at the window; nothing before it' );

        $future = array_filter( $slots, static fn( array $s ): bool => $s['is_future'] );
        $this->assertGreaterThanOrEqual( 2 * ( DemoCalendar::HORIZON_WEEKS - 1 ), count( $future ) );
        foreach ( $slots as $slot ) {
            $this->assertSame( $slot['ts'] >= $now, $slot['is_future'] );
        }
    }

    public function test_the_same_now_gives_the_same_dates(): void {
        $this->assertSame(
            ( new DemoCalendar( 36, self::NOW ) )->activitySlots(),
            ( new DemoCalendar( 36, self::NOW ) )->activitySlots()
        );
    }

    public function test_every_slot_carries_its_times(): void {
        foreach ( ( new DemoCalendar( 8, self::NOW ) )->activitySlots() as $slot ) {
            if ( $slot['is_game'] ) {
                $this->assertSame( '10:00:00', $slot['start_time'] );
                $this->assertSame( '11:30:00', $slot['end_time'] );
                $this->assertSame( '09:15:00', $slot['time_of_presence'] );
                $this->assertSame( $slot['date'] . ' 10:00', gmdate( 'Y-m-d H:i', $slot['ts'] ), 'ts is the kick-off' );
            } else {
                $this->assertSame( '18:30:00', $slot['start_time'] );
                $this->assertSame( '20:00:00', $slot['end_time'] );
                $this->assertNull( $slot['time_of_presence'] );
            }
        }
    }

    // ----- Generated rows, read back through the table and the REST list -----

    private int $team_id = 0;

    private function generate(): void {
        global $wpdb;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Schedule U14', 'age_group' => 'U14' ] );
        $this->team_id = (int) $wpdb->insert_id;

        for ( $i = 1; $i <= 4; $i++ ) {
            $wpdb->insert( "{$wpdb->prefix}tt_players", [
                'club_id'     => $club,
                'first_name'  => 'Schedule',
                'last_name'   => 'Player ' . $i,
                'team_id'     => $this->team_id,
                'date_joined' => '2024-08-01',
                'wp_user_id'  => null,
            ] );
        }

        $teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_teams WHERE id = %d",
            $this->team_id
        ) );
        foreach ( $teams as $team ) {
            $team->head_coach_user_id = 0;
        }
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE team_id = %d",
            $this->team_id
        ) );

        $calendar = new DemoCalendar( 8, self::NOW );
        ( new ActivityGenerator(
            new DemoBatchRegistry( 'test-3660' ),
            $teams,
            $players,
            8,
            'en_US',
            $calendar,
            new DemoRoster( $calendar, $teams, $players )
        ) )->generate();
    }

    public function test_generated_activities_have_times_and_games_a_presence_time(): void {
        global $wpdb;
        $this->generate();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_type_key, session_date, start_time, end_time, time_of_presence, kickoff_time
               FROM {$wpdb->prefix}tt_activities
              WHERE team_id = %d AND activity_source_key = 'generated'",
            $this->team_id
        ) );

        $this->assertNotEmpty( $rows );
        $games = 0;
        foreach ( (array) $rows as $row ) {
            $this->assertNotNull( $row->start_time );
            $this->assertNotNull( $row->end_time );
            if ( (string) $row->activity_type_key === 'game' ) {
                $games++;
                $this->assertSame( '6', gmdate( 'N', strtotime( $row->session_date . ' 00:00:00 UTC' ) ) );
                $this->assertNotNull( $row->time_of_presence );
                $this->assertLessThan( (string) $row->start_time, (string) $row->time_of_presence, 'players report before kick-off' );
                $this->assertSame( (string) $row->start_time, (string) $row->kickoff_time );
            } else {
                $this->assertSame( '18:30:00', (string) $row->start_time );
                $this->assertSame( '20:00:00', (string) $row->end_time );
            }
        }
        $this->assertGreaterThan( 0, $games );
    }

    public function test_the_rest_list_returns_saturday_games_with_times(): void {
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->generate();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( [
            'per_page' => 100,
            'filter'   => [ 'team_id' => $this->team_id, 'date_from' => '2026-08-01', 'date_to' => '2026-10-31' ],
        ] );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );

        $rows  = $res->get_data()['data']['rows'] ?? [];
        $games = array_values( array_filter( $rows, static fn( $r ): bool => ( $r['activity_type_key'] ?? '' ) === 'game' ) );

        $this->assertNotEmpty( $rows );
        $this->assertNotEmpty( $games );
        foreach ( $games as $game ) {
            $this->assertSame( '6', gmdate( 'N', strtotime( $game['session_date'] . ' 00:00:00 UTC' ) ) );
            $this->assertNotNull( $game['start_time'] );
            $this->assertNotNull( $game['end_time'] );
            $this->assertNotNull( $game['time_of_presence'] );
        }
        foreach ( $rows as $row ) {
            if ( ( $row['activity_type_key'] ?? '' ) === 'game' ) continue;
            $this->assertSame( '18:30:00', $row['start_time'] );
            $this->assertSame( '20:00:00', $row['end_time'] );
        }

        $wp_rest_server = null;
        wp_set_current_user( 0 );
    }
}
