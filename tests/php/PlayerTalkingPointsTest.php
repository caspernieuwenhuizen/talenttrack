<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\PlayerTalkingPoints;

/**
 * #3875 (epic #3871) — the talking points a player report raises.
 *
 * One test per signal where it fires, where it does not, and where there is
 * too little data to say anything — the case that matters most, because a
 * point raised on two sessions of evidence is a judgement about a child that
 * the data does not support.
 *
 * Most signals read the packet as given, so the packet here is built by hand.
 * Attendance and minutes compare against the database — the previous window
 * and the squad's minutes — so those tests seed rows.
 */
final class PlayerTalkingPointsTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-04-30';

    private int $team   = 0;
    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Points U12', 'age_group' => 'U11' ] );
        $this->team = (int) $wpdb->insert_id;
        $this->player = $this->insertPlayer( 'Points' );
    }

    public function tear_down(): void {
        foreach ( [ PlayerTalkingPoints::CONFIG_ATTENDANCE_DROP ] as $key ) {
            QueryHelpers::set_config( $key, '' );
        }
        parent::tear_down();
    }

    // ---- status and the academy's record ---------------------------------

    public function test_an_amber_verdict_is_raised_with_its_reasons(): void {
        $points = $this->derive( [ 'status' => [ 'color' => 'amber', 'reasons' => [ 'Composite 55 below amber threshold 60.' ], 'missing_inputs' => [] ] ] );

        $this->assertSame( 'status', $points[0]['key'] );
        $this->assertSame( 'amber', $points[0]['level'] );
        $this->assertStringContainsString( 'Composite 55', $points[0]['evidence'] );
    }

    public function test_a_green_verdict_raises_nothing(): void {
        $this->assertSame( [], $this->keys( $this->derive( [ 'status' => [ 'color' => 'green', 'reasons' => [], 'missing_inputs' => [] ] ] ) ) );
    }

    public function test_missing_inputs_are_an_academy_gap_not_repeated_in_the_status_point(): void {
        $points = $this->derive( [ 'status' => [
            'color'          => 'amber',
            'reasons'        => [ sprintf( 'Computed without %s.', \TT\Infrastructure\PlayerStatus\StatusVerdict::inputLabel( 'potential' ) ) ],
            'missing_inputs' => [ 'potential' ],
        ] ] );

        $this->assertSame( [ 'status', 'missing_inputs' ], $this->keys( $points ) );
        $this->assertSame( '', $points[0]['evidence'], 'the gap is said once, as a gap' );
        $this->assertSame( 'info', $points[1]['level'] );
    }

    // ---- goals, injuries, tests, transitions ------------------------------

    public function test_an_open_goal_past_its_date_is_raised_and_a_closed_one_is_not(): void {
        $points = $this->derive( [ 'goals' => [
            [ 'title' => 'Scan before receiving', 'due_date' => '2020-03-15', 'is_closed' => false ],
            [ 'title' => 'Done already', 'due_date' => '2020-03-15', 'is_closed' => true ],
            [ 'title' => 'Not due yet', 'due_date' => '2020-06-01', 'is_closed' => false ],
        ] ] );

        $this->assertSame( [ 'goal_past_due' ], $this->keys( $points ) );
        $this->assertStringContainsString( 'Scan before receiving', $points[0]['text'] );
    }

    public function test_a_return_from_injury_inside_the_window_is_raised(): void {
        $this->assertSame( [ 'returned_from_injury' ], $this->keys( $this->derive( [ 'injuries' => [ [ 'actual_return' => '2020-04-02' ] ] ] ) ) );
        $this->assertSame( [], $this->keys( $this->derive( [ 'injuries' => [ [ 'actual_return' => '2020-01-10' ] ] ] ) ) );
        $this->assertSame( [], $this->keys( $this->derive( [ 'injuries' => [ [ 'actual_return' => '' ] ] ] ) ), 'still out is the injuries section, not a return' );
    }

    public function test_a_test_that_moved_the_wrong_way_is_raised(): void {
        $this->assertSame( [ 'tests_down' ], $this->keys( $this->derive( [ 'tests' => [ [ 'name' => '30 m sprint', 'trend' => 'down' ] ] ] ) ) );
        $this->assertSame( [], $this->keys( $this->derive( [ 'tests' => [ [ 'name' => '30 m sprint', 'trend' => 'up' ], [ 'name' => 'Height', 'trend' => '' ] ] ] ) ) );
    }

    public function test_a_transition_is_named_and_other_journey_entries_are_not(): void {
        $moved = (object) [ 'event_type' => 'age_group_promoted', 'event_date' => '2020-03-20 09:00:00', 'summary' => 'Moved to U13' ];
        $eval  = (object) [ 'event_type' => 'evaluation_completed', 'event_date' => '2020-03-21 09:00:00', 'summary' => 'Evaluated' ];

        $points = $this->derive( [ 'recent_journey' => [ $moved, $eval ] ] );

        $this->assertSame( [ 'transition' ], $this->keys( $points ) );
        $this->assertStringContainsString( 'Moved to U13', $points[0]['text'] );
    }

    // ---- not evaluated -----------------------------------------------------

    public function test_no_evaluation_in_a_long_window_is_raised(): void {
        $this->assertSame( [ 'not_evaluated' ], $this->keys( $this->derive( [ 'evaluations' => [] ] ) ) );
    }

    public function test_an_evaluation_in_the_window_clears_it(): void {
        $this->assertSame( [], $this->keys( $this->derive( [ 'evaluations' => [ [ 'id' => 1 ] ] ] ) ) );
    }

    public function test_a_short_window_is_too_little_time_to_expect_an_evaluation(): void {
        $points = PlayerTalkingPoints::derive( $this->packet( [ 'evaluations' => [] ] ), 0, '2020-03-01', '2020-03-14' );
        $this->assertSame( [], $this->keys( $points ) );
    }

    // ---- attendance --------------------------------------------------------

    public function test_a_drop_against_the_previous_window_is_raised(): void {
        // The window before 1 Mar – 30 Apr is 1 Jan – 29 Feb: five of five.
        foreach ( [ '2020-01-06', '2020-01-13', '2020-01-20', '2020-02-03', '2020-02-10' ] as $d ) $this->attend( $d, 'Present' );

        $now = [ 'activities' => 5, 'present' => 2, 'absent' => 3, 'excused' => 0, 'rate' => 40.0 ];
        $points = $this->derive( [ 'attendance' => $now, 'evaluations' => [ [ 'id' => 1 ] ] ] );

        $this->assertSame( [ 'attendance' ], $this->keys( $points ) );
        $this->assertStringContainsString( '3', $points[0]['text'] );
        $this->assertStringContainsString( '100', $points[0]['evidence'] );
    }

    public function test_no_drop_is_not_raised(): void {
        foreach ( [ '2020-01-06', '2020-01-13', '2020-01-20', '2020-02-03', '2020-02-10' ] as $d ) $this->attend( $d, 'Present' );

        $now = [ 'activities' => 5, 'present' => 5, 'absent' => 0, 'excused' => 0, 'rate' => 100.0 ];
        $this->assertSame( [], $this->keys( $this->derive( [ 'attendance' => $now, 'evaluations' => [ [ 'id' => 1 ] ] ] ) ) );
    }

    public function test_two_sessions_are_too_few_to_call_a_trend(): void {
        foreach ( [ '2020-01-06', '2020-01-13', '2020-01-20', '2020-02-03', '2020-02-10' ] as $d ) $this->attend( $d, 'Present' );

        $now = [ 'activities' => 2, 'present' => 0, 'absent' => 2, 'excused' => 0, 'rate' => 0.0 ];
        $this->assertSame( [], $this->keys( $this->derive( [ 'attendance' => $now, 'evaluations' => [ [ 'id' => 1 ] ] ] ) ) );
    }

    public function test_the_attendance_threshold_is_the_academys_to_move(): void {
        foreach ( [ '2020-01-06', '2020-01-13', '2020-01-20', '2020-02-03', '2020-02-10' ] as $d ) $this->attend( $d, 'Present' );
        QueryHelpers::set_config( PlayerTalkingPoints::CONFIG_ATTENDANCE_DROP, '80' );

        $now = [ 'activities' => 5, 'present' => 2, 'absent' => 3, 'excused' => 0, 'rate' => 40.0 ];
        $this->assertSame( [], $this->keys( $this->derive( [ 'attendance' => $now, 'evaluations' => [ [ 'id' => 1 ] ] ] ) ), 'a 60-point drop is under an 80-point threshold' );
    }

    // ---- minutes -----------------------------------------------------------

    public function test_a_share_below_the_academy_target_is_raised(): void {
        $starter = $this->insertPlayer( 'Starter' );
        foreach ( [ '2020-03-07', '2020-03-14', '2020-03-21' ] as $d ) {
            $match = $this->match( $d );
            $this->minutesFor( $match, $starter, 70 );
            $this->minutesFor( $match, $this->player, 5 );
        }

        $points = $this->derive( [ 'evaluations' => [ [ 'id' => 1 ] ] ] );
        $this->assertSame( [ 'minutes' ], $this->keys( $points ) );

        $starter_points = PlayerTalkingPoints::derive( $this->packet( [ 'player_id' => $starter, 'evaluations' => [ [ 'id' => 1 ] ] ] ), $this->team, self::FROM, self::TO );
        $this->assertSame( [], $this->keys( $starter_points ), 'the player who played everything is not raised' );
    }

    public function test_two_recorded_matches_are_too_few_to_compare_playing_time(): void {
        $starter = $this->insertPlayer( 'Starter' );
        foreach ( [ '2020-03-07', '2020-03-14' ] as $d ) {
            $match = $this->match( $d );
            $this->minutesFor( $match, $starter, 70 );
            $this->minutesFor( $match, $this->player, 5 );
        }

        $this->assertSame( [], $this->keys( $this->derive( [ 'evaluations' => [ [ 'id' => 1 ] ] ] ) ) );
    }

    // ---- ordering ----------------------------------------------------------

    public function test_the_most_urgent_comes_first(): void {
        $points = $this->derive( [
            'status' => [ 'color' => 'red', 'reasons' => [], 'missing_inputs' => [ 'potential' ] ],
            'goals'  => [ [ 'title' => 'Late', 'due_date' => '2020-03-15', 'is_closed' => false ] ],
        ] );

        $this->assertSame( [ 'red', 'amber', 'info' ], array_column( $points, 'level' ) );
        $this->assertSame( [ 'status', 'goal_past_due', 'missing_inputs' ], $this->keys( $points ) );
        $this->assertSame( 'status', $points[0]['key'] );
    }

    // ---- fixtures ----------------------------------------------------------

    /**
     * @param array<string,mixed> $overrides
     * @return list<array{key:string, level:string, text:string, evidence:string}>
     */
    private function derive( array $overrides ): array {
        return PlayerTalkingPoints::derive( $this->packet( $overrides ), $this->team, self::FROM, self::TO );
    }

    /**
     * A quiet packet — nothing to raise — with the given groups replaced.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function packet( array $overrides ): array {
        return array_merge( [
            'player_id'      => $this->player,
            'status'         => [ 'color' => 'green', 'reasons' => [], 'missing_inputs' => [] ],
            'attendance'     => [ 'activities' => 0, 'present' => 0, 'absent' => 0, 'excused' => 0, 'rate' => null ],
            'evaluations'    => [ [ 'id' => 1 ] ],
            'goals'          => [],
            'injuries'       => [],
            'tests'          => [],
            'recent_journey' => [],
        ], $overrides );
    }

    /**
     * @param list<array{key:string, level:string, text:string, evidence:string}> $points
     * @return list<string>
     */
    private function keys( array $points ): array {
        return array_column( $points, 'key' );
    }

    private function insertPlayer( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $this->team,
            'first_name' => 'Talk', 'last_name' => $last, 'status' => 'active', 'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function attend( string $date, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => CurrentClub::id(), 'team_id' => $this->team, 'title' => 'Training ' . $date, 'session_date' => $date,
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id' => CurrentClub::id(), 'activity_id' => (int) $wpdb->insert_id, 'player_id' => $this->player,
            'status' => $status, 'record_type' => 'actual', 'is_guest' => 0,
        ] );
        $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'the fixture wrote' );
    }

    private function match( string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => CurrentClub::id(), 'team_id' => $this->team, 'title' => 'Match ' . $date, 'session_date' => $date,
            'activity_type_key' => 'game', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function minutesFor( int $match, int $player_id, int $minutes ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id' => CurrentClub::id(), 'activity_id' => $match, 'player_id' => $player_id, 'is_guest' => 0,
            'status' => 'Present', 'record_type' => 'actual', 'minutes_played' => $minutes,
        ] );
        $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'the fixture wrote' );
    }
}
