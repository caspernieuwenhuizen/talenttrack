<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\FactQuery;
use TT\Modules\Analytics\FactRegistry;
use TT\Modules\Analytics\KpiResolver;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;

/**
 * #4584 — the semantic layer reads the same rows as the reports beside it.
 *
 * The evaluation-coverage fact read `tt_activities.start_at`, a column that
 * does not exist, so the KPI and its explorer were empty. The attendance
 * fact's measures counted trashed, unfinished and off-roster rows that the
 * attendance report leaves out, so the KPI and the report disagreed.
 */
final class AnalyticsFactsSharedRulesTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        wp_cache_flush();
    }

    public function test_evaluation_coverage_groups_by_the_activity_date(): void {
        $this->assertNotNull( FactRegistry::find( 'evaluations_per_session' ) );

        $team     = $this->insertTeam( 'U14 coverage' );
        $player   = $this->insertPlayer( $team, 'Cov' );
        $activity = $this->insertActivity( $team, '2020-05-10', 'completed' );
        $this->insertEvaluation( $activity, $player );
        $this->insertEvaluation( $activity, $player );

        $rows = FactQuery::run(
            'evaluations_per_session',
            [ 'month' ],
            [ 'count_evaluations' ],
            [ 'date_after' => '2020-05-01', 'date_before' => '2020-05-31' ]
        );

        $this->assertCount( 1, $rows );
        $this->assertSame( '2020-05', (string) $rows[0]->month );
        $this->assertSame( 2, (int) $rows[0]->count_evaluations );

        $kpi = KpiResolver::value( 'evaluation_coverage', [ 'date_after' => '2020-05-01', 'date_before' => '2020-05-31' ] );
        $this->assertSame( 2.0, $kpi, 'the Evaluation coverage KPI returns a value' );
    }

    /**
     * `tt_evaluations` has `coach_id`, not `evaluator_id`; the dimension keeps
     * its key so saved explorer URLs still resolve.
     */
    public function test_grouping_by_evaluator_reads_the_coach(): void {
        $team     = $this->insertTeam( 'U16 evaluator' );
        $player   = $this->insertPlayer( $team, 'Eva' );
        $activity = $this->insertActivity( $team, '2020-06-10', 'completed' );
        $this->insertEvaluation( $activity, $player, 11 );
        $this->insertEvaluation( $activity, $player, 11 );
        $this->insertEvaluation( $activity, $player, 12 );

        $window = [ 'date_after' => '2020-06-01', 'date_before' => '2020-06-30' ];
        $rows   = FactQuery::run( 'evaluations_per_session', [ 'evaluator_id' ], [ 'count_evaluations' ], $window );
        $counts = [];
        foreach ( $rows as $r ) $counts[ (int) $r->evaluator_id ] = (int) $r->count_evaluations;
        ksort( $counts );
        $this->assertSame( [ 11 => 2, 12 => 1 ], $counts );

        $only = FactQuery::run( 'evaluations_per_session', [], [ 'count_evaluations' ], $window + [ 'evaluator_id_eq' => 12 ] );
        $this->assertSame( 1, (int) $only[0]->count_evaluations, 'filtering by evaluator reads the coach too' );

        $all = FactQuery::run( 'evaluations', [ 'evaluator_id' ], [ 'count' ], [ 'evaluator_id_in' => [ 11, 12 ] ] );
        $this->assertCount( 2, $all, 'the evaluations fact groups by the coach as well' );
    }

    public function test_attendance_measures_equal_the_attendance_report(): void {
        $team    = $this->insertTeam( 'U15 attendance-rules' );
        $old     = $this->insertTeam( 'U14 attendance-rules' );
        $player  = $this->insertPlayer( $team, 'Rule' );

        // Two countable rows: one present, one absent.
        $this->insertAttendance( $this->insertActivity( $team, '2020-03-01', 'completed' ), $player, 'Present' );
        $this->insertAttendance( $this->insertActivity( $team, '2020-03-08', 'completed' ), $player, 'Absent' );

        // Four rows the report leaves out, all present so that counting any
        // of them moves the percentage.
        $this->insertAttendance( $this->insertActivity( $team, '2020-03-15', 'planned' ), $player, 'Present' );
        $trashed = $this->insertActivity( $team, '2020-03-22', 'completed' );
        $this->insertAttendance( $trashed, $player, 'Present' );
        global $wpdb;
        $wpdb->update( "{$this->p}tt_activities", [ 'trashed_at' => '2020-03-23 10:00:00' ], [ 'id' => $trashed ] );
        // The player's former team: not on that roster any more.
        $this->insertAttendance( $this->insertActivity( $old, '2020-03-29', 'completed' ), $player, 'Present' );
        // A planned squad row, never a register.
        $this->insertAttendance( $this->insertActivity( $team, '2020-04-05', 'completed' ), $player, 'Present', 'expected' );

        $report = null;
        foreach ( ( new AttendanceRankingQuery() )->rows( '2020-01-01', '2020-12-31' ) as $r ) {
            if ( (int) $r['player_id'] === $player ) { $report = $r; break; }
        }
        $this->assertNotNull( $report );
        $this->assertSame( 50.0, $report['present_pct'] );

        $rows = FactQuery::run(
            'attendance',
            [],
            [ 'attendance_pct', 'count_present', 'count_absent' ],
            [ 'player_id_eq' => $player, 'date_after' => '2020-01-01', 'date_before' => '2020-12-31' ]
        );
        $this->assertCount( 1, $rows );
        $this->assertSame( $report['present_pct'], round( (float) $rows[0]->attendance_pct, 1 ), 'KPI attendance % equals the report' );
        $this->assertSame( $report['present'], (int) $rows[0]->count_present );
        $this->assertSame( $report['absent'], (int) $rows[0]->count_absent );

        // The fact itself stays unscoped (#3451): the data browser shows every row.
        $this->assertSame( 6, FactQuery::countRows( 'attendance', [ 'player_id_eq' => $player ] ) );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => $first,
            'last_name'  => 'Facts',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $team_id, string $date, string $status ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Training ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'training',
            'activity_status_key' => $status,
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $status, string $record_type = 'actual' ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'is_guest'    => 0,
            'record_type' => $record_type,
        ] );
    }

    private function insertEvaluation( int $activity_id, int $player_id, int $coach_id = 1 ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'coach_id'    => $coach_id,
            'eval_date'   => '2020-05-10',
        ] );
    }
}
