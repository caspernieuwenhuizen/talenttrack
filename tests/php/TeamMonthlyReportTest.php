<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;

/**
 * #3458 (epic #3457) — the team monthly report as data.
 *
 * What is pinned: a deselected block is neither in the payload nor queried;
 * deltas are taken against the preceding window of equal length and are null,
 * never zero, without a predecessor; a quiet team produces a well-formed empty
 * report; the coverage banner names the activity with no register; and the REST
 * route refuses a caller with no reports read on the team.
 *
 * Dates are in 2020 on purpose — well in the past on any runner's clock, so
 * "completed" never depends on when the suite runs.
 */
final class TeamMonthlyReportTest extends WP_UnitTestCase {

    private int $team_id = 0;

    public function set_up(): void {
        parent::set_up();
        ActivityRegisterProgress::forget();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Monthly U14', 'age_group' => 'U14' ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    private function player( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => 1, 'first_name' => 'Squad', 'last_name' => $last,
            'team_id' => $this->team_id, 'status' => 'active', 'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function training( string $date, string $title, string $status = 'completed' ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => 1,
            'team_id'             => $this->team_id,
            'title'               => $title,
            'session_date'        => $date,
            'activity_type_key'   => 'training',
            'activity_status_key' => $status,
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function present( int $activity_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id' => 1, 'activity_id' => $activity_id, 'player_id' => $player_id,
            'status' => 'Present', 'record_type' => 'actual', 'is_guest' => 0,
        ] );
    }

    /** Run a composition and return the SQL it issued. @return list<string> */
    private function capture( callable $fn ): array {
        $seen   = [];
        $filter = static function ( $sql ) use ( &$seen ) {
            $seen[] = (string) $sql;
            return $sql;
        };
        add_filter( 'query', $filter );
        try {
            $fn();
        } finally {
            remove_filter( 'query', $filter );
        }
        return $seen;
    }

    public function test_unknown_block_keys_are_refused(): void {
        $this->expectException( \InvalidArgumentException::class );
        ( new TeamMonthlyReport() )->forTeam( $this->team_id, '2020-03-01', '2020-03-31', [ 'kpi', 'rostr' ] );
    }

    public function test_a_malformed_window_is_refused(): void {
        $this->expectException( \InvalidArgumentException::class );
        ( new TeamMonthlyReport() )->forTeam( $this->team_id, '2020-03-31', '2020-03-01' );
    }

    public function test_letterhead_is_always_included_and_deselected_blocks_are_absent(): void {
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_id, '2020-03-01', '2020-03-31', [ 'kpi' ] );

        $this->assertSame( [ TeamMonthlyReportBlock::LETTERHEAD, TeamMonthlyReportBlock::KPI ], $report['blocks'] );
        $this->assertArrayHasKey( 'letterhead', $report['data'] );
        $this->assertArrayNotHasKey( 'roster', $report['data'] );
        $this->assertArrayNotHasKey( 'attendance', $report['data'] );
    }

    /**
     * The point of selection: the roster's goals read only happens when the
     * roster is asked for. Asserted on the SQL issued, not on the payload.
     */
    public function test_a_deselected_block_is_never_queried(): void {
        $this->player( 'One' );

        $without = $this->capture( fn() => ( new TeamMonthlyReport() )->forTeam( $this->team_id, '2020-03-01', '2020-03-31', [ 'letterhead' ] ) );
        $with    = $this->capture( fn() => ( new TeamMonthlyReport() )->forTeam( $this->team_id, '2020-03-01', '2020-03-31', [ 'roster' ] ) );

        $touches_goals = static fn( array $sqls ): bool => (bool) array_filter( $sqls, static fn( string $s ): bool => strpos( $s, 'tt_goals' ) !== false );

        $this->assertFalse( $touches_goals( $without ), 'A report without the roster must not read goals.' );
        $this->assertTrue( $touches_goals( $with ), 'Precondition: the roster block does read goals.' );
        $this->assertLessThan( count( $with ), count( $without ) );
    }

    public function test_a_calendar_month_compares_against_the_previous_calendar_month(): void {
        $this->assertSame( [ 'from' => '2020-02-01', 'to' => '2020-02-29' ], TeamMonthlyReport::previousWindow( '2020-03-01', '2020-03-31' ) );
        $this->assertSame( [ 'from' => '2019-12-01', 'to' => '2019-12-31' ], TeamMonthlyReport::previousWindow( '2020-01-01', '2020-01-31' ) );
    }

    public function test_a_custom_range_compares_against_the_preceding_equal_length(): void {
        $this->assertSame( [ 'from' => '2020-02-26', 'to' => '2020-03-06' ], TeamMonthlyReport::previousWindow( '2020-03-07', '2020-03-16' ) );
    }

    public function test_last_month_resolves_from_any_day_including_the_31st(): void {
        $this->assertSame( [ 'from' => '2020-02-01', 'to' => '2020-02-29' ], TeamMonthlyReport::periodWindow( 'last_month', '2020-03-31' ) );
        $this->assertSame( [ 'from' => '2019-12-01', 'to' => '2019-12-31' ], TeamMonthlyReport::periodWindow( 'last_month', '2020-01-15' ) );
    }

    /** A quiet team is a well-formed, empty report — not an error and not zeroes. */
    public function test_a_team_with_no_activities_gets_an_empty_report_not_zeroes(): void {
        $this->player( 'Quiet' );
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_id, '2020-03-01', '2020-03-31', [ 'coverage', 'kpi', 'minutes' ] );

        $this->assertSame( 'empty', $report['data']['coverage']['state'] );
        $this->assertSame( 0, $report['data']['kpi']['activities']['value'] );
        $this->assertNull( $report['data']['kpi']['activities']['delta'], 'No predecessor is a null delta, never 0.' );
        $this->assertNull( $report['data']['kpi']['attendance_pct']['value'] );
        $this->assertNull( $report['data']['minutes']['median_share_pct'], 'No recorded minutes is no share, not 0%.' );
    }

    /** The banner names the activity that completed with nobody on its register. */
    public function test_coverage_names_the_activity_without_a_register(): void {
        $player = $this->player( 'Registered' );
        $this->present( $this->training( '2020-03-03', 'Tuesday' ), $player );
        $empty = $this->training( '2020-03-05', 'Thursday' );

        $coverage = ( new TeamMonthlyReport() )->forTeam( $this->team_id, '2020-03-01', '2020-03-31', [ 'coverage' ] )['data']['coverage'];

        $this->assertSame( 'partial', $coverage['state'] );
        $this->assertSame( 2, $coverage['completed'] );
        $this->assertSame( 1, $coverage['with_register'] );
        $this->assertSame( [ $empty ], array_column( $coverage['missing'], 'activity_id' ) );
    }

    /**
     * #3746 — the three outcomes counted separately. A session nobody closed
     * is not "no register": it is its own failure and reads on its own line.
     */
    public function test_coverage_separates_a_never_closed_session_from_a_missing_register(): void {
        $player = $this->player( 'Registered' );
        $this->present( $this->training( '2020-03-03', 'Tuesday' ), $player );
        $no_register = $this->training( '2020-03-05', 'Thursday' );
        $unclosed    = $this->training( '2020-03-10', 'Tuesday after', 'planned' );
        $this->training( '2020-03-12', 'Called off', 'cancelled' );

        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_id, '2020-03-01', '2020-03-31', [ 'coverage', 'kpi', 'quality' ] );

        $this->assertSame( 3, $report['data']['letterhead']['activity_count'], 'Scheduled, not completed — and never the cancelled one.' );
        $this->assertSame( 3, $report['data']['kpi']['activities']['value'] );

        $coverage = $report['data']['coverage'];
        $this->assertSame( 'partial', $coverage['state'] );
        $this->assertSame( 3, $coverage['scheduled'] );
        $this->assertSame( 2, $coverage['completed'] );
        $this->assertSame( 1, $coverage['with_register'] );
        $this->assertSame( [ $no_register ], array_column( $coverage['missing'], 'activity_id' ) );
        $this->assertSame( [ $unclosed ], array_column( $coverage['never_closed'], 'activity_id' ) );

        $quality = $report['data']['quality'];
        $this->assertSame( [ $no_register ], array_column( $quality['activities_without_register'], 'activity_id' ) );
        $this->assertSame( [ $unclosed ], array_column( $quality['activities_never_closed'], 'activity_id' ) );
    }

    /** A month of sessions nobody closed is the opposite of nothing to report. */
    public function test_a_window_of_only_never_closed_sessions_is_not_an_empty_report(): void {
        $this->player( 'Waiting' );
        $this->training( '2020-03-03', 'Tuesday', 'planned' );
        $this->training( '2020-03-05', 'Thursday', 'planned' );

        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_id, '2020-03-01', '2020-03-31', [ 'coverage' ] );

        $this->assertSame( 2, $report['data']['letterhead']['activity_count'] );
        $this->assertSame( 'partial', $report['data']['coverage']['state'], 'Not "empty": two sessions were scheduled.' );
        $this->assertSame( 0, $report['data']['coverage']['completed'] );
        $this->assertSame( 0, $report['data']['coverage']['with_register'] );
        $this->assertCount( 2, $report['data']['coverage']['never_closed'] );
    }

    /** A session that has not happened yet is not a gap. */
    public function test_a_future_dated_activity_counts_but_is_never_a_gap(): void {
        $year = (int) gmdate( 'Y' ) + 5;
        $this->training( $year . '-03-03', 'Next season', 'planned' );

        $coverage = ( new TeamMonthlyReport() )->forTeam( $this->team_id, $year . '-03-01', $year . '-03-31', [ 'coverage' ] )['data']['coverage'];

        $this->assertSame( 1, $coverage['scheduled'] );
        $this->assertSame( [], $coverage['missing'] );
        $this->assertSame( [], $coverage['never_closed'] );
    }

    public function test_the_rest_route_refuses_a_caller_without_reports_read(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/teams/' . $this->team_id . '/monthly-report' )
        );

        $this->assertContains( $response->get_status(), [ 401, 403 ] );
    }
}
