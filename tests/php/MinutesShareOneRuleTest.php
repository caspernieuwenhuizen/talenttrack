<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\MinutesShareQuery;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportFigures;
use TT\Shared\Frontend\FrontendStandardReportsView;

/**
 * #4993 — the minutes share and the team monthly report read one median, the
 * SQL "has recorded minutes" gives the minutes read's answer, the matches
 * outside the window are counted in one query, and an empty report says
 * which of its two empty cases it is.
 */
final class MinutesShareOneRuleTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function tear_down(): void {
        $_GET = [];
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * Two players on 503 and 508 of 5000 available minutes: 10.06% and
     * 10.16%. Rounding each share first (10.1, 10.2) put the monthly report's
     * median at 10.2, against 10.1 here.
     */
    public function test_an_even_squad_reads_the_same_median_in_both_reports(): void {
        $rows = [
            $this->minutesRow( 503, 5000 ),
            $this->minutesRow( 508, 5000 ),
        ];
        $players = [
            [ 'share_pct' => 503 / 5000 * 100 ],
            [ 'share_pct' => 508 / 5000 * 100 ],
        ];

        $rounded_first = round( ( round( 10.06, 1 ) + round( 10.16, 1 ) ) / 2, 1 );
        $this->assertSame( 10.2, $rounded_first, 'precondition: rounding each share first gives 10.2' );

        $this->assertSame( 10.1, MinutesShareQuery::medianShare( $players ), 'the minutes share' );
        $this->assertSame( 10.1, MinutesShareQuery::medianShare( TeamReportFigures::shareRows( $rows ) ), 'the monthly report' );
    }

    public function test_a_squad_without_available_minutes_has_no_median(): void {
        $this->assertNull( MinutesShareQuery::medianShare( TeamReportFigures::shareRows( [ $this->minutesRow( 0, 0 ) ] ) ) );
    }

    public function test_one_median_implementation_remains(): void {
        $root  = dirname( __DIR__, 2 );
        $found = [];
        $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $files as $file ) {
            if ( ! $file instanceof \SplFileInfo || $file->getExtension() !== 'php' ) continue;
            if ( preg_match( '/function\s+medianShare\s*\(/', (string) file_get_contents( $file->getPathname() ) ) ) {
                $found[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
            }
        }
        $this->assertSame( [ 'src/Modules/Analytics/Reports/MinutesShareQuery.php' ], $found );
    }

    /**
     * The SQL predicate and `minutesForActivities()` say the same thing about
     * every kind of match the minutes read tells apart.
     */
    public function test_the_sql_predicate_agrees_with_the_minutes_read(): void {
        $team = $this->insertTeam( 'U13 agreement' );
        $a    = $this->insertPlayer( $team, 'Anna' );
        $b    = $this->insertPlayer( $team, 'Bram' );

        $cases = [];

        $m = $this->insertMatch( $team, '2026-02-01' );
        $this->insertAttendance( $m, $a, 40 );
        $cases['minutes on the register'] = [ $m, true ];

        $m = $this->insertMatch( $team, '2026-02-02' );
        $this->insertAttendance( $m, $a, 0 );
        $cases['a confirmed 0'] = [ $m, false ];

        $m = $this->insertMatch( $team, '2026-02-03' );
        $this->insertAttendance( $m, $a, 50 );
        $this->insertAttendance( $m, $a, 0 );
        $cases['a duplicate corrected to 0'] = [ $m, false ];

        $m = $this->insertMatch( $team, '2026-02-04' );
        $this->insertAttendance( $m, $a, 50, 1 );
        $cases['minutes on a guest only'] = [ $m, false ];

        $cases['no register'] = [ $this->insertMatch( $team, '2026-02-05' ), false ];

        $m = $this->insertMatch( $team, '2026-02-06' );
        $this->insertAttendance( $m, $a, null );
        $this->insertAttendance( $m, $b, null );
        $cases['a register without figures'] = [ $m, false ];

        $m = $this->insertFixture( $team, '2026-02-07', 24, '[12]', true, [ $a => 'CM' ] );
        $this->insertAttendance( $m, $a, null );
        $cases['a completed fixture, the plan answering'] = [ $m, true ];

        $m = $this->insertFixture( $team, '2026-02-08', 24, '[12]', true, [ $a => 'BENCH' ] );
        $this->insertAttendance( $m, $a, null );
        $cases['a completed fixture, benched in the plan'] = [ $m, false ];

        $m = $this->insertFixture( $team, '2026-02-09', 24, '[12]', false, [ $a => 'CM' ] );
        $this->insertAttendance( $m, $a, null );
        $cases['a fixture not completed'] = [ $m, false ];

        $m = $this->insertFixture( $team, '2026-02-10', 24, '[12]', true, [ $a => 'CM' ] );
        $this->insertAttendance( $m, $a, 0 );
        $cases['a confirmed 0 against the plan'] = [ $m, false ];

        $m = $this->insertFixture( $team, '2026-02-11', 24, '[12]', true, [ $b => 'CM' ] );
        $this->insertAttendance( $m, $a, null );
        $cases['planned, but not on the register'] = [ $m, false ];

        $m = $this->insertFixture( $team, '2026-02-12', 1, '[1,2,3]', true, [ $a => 'CM' ] );
        $this->insertAttendance( $m, $a, null );
        $cases['periods that round to 0 minutes'] = [ $m, false ];

        $m = $this->insertFixture( $team, '2026-02-13', 2, '[1,2,3]', true, [ $a => 'CM' ] );
        $this->insertAttendance( $m, $a, null );
        $cases['periods that round up to 1 minute'] = [ $m, true ];

        // A JSON object counts its members as windows, as `count()` of the
        // decoded array does: three periods of a 1-minute fixture round to 0.
        $m = $this->insertFixture( $team, '2026-02-14', 1, '{"a":1,"b":2}', true, [ $a => 'CM' ] );
        $this->insertAttendance( $m, $a, null );
        $cases['windows as an object'] = [ $m, false ];

        // A JSON scalar is no windows: one period, the whole minute.
        $m = $this->insertFixture( $team, '2026-02-15', 1, '5', true, [ $a => 'CM' ] );
        $this->insertAttendance( $m, $a, null );
        $cases['windows as a scalar'] = [ $m, true ];

        $ids  = array_map( static fn( array $c ): int => $c[0], $cases );
        $read = MinutesQuery::minutesForActivities( $ids );
        $sql  = $this->recordedBySql( $ids );

        foreach ( $cases as $name => [ $id, $expected ] ) {
            $this->assertSame( $expected, isset( $read[ $id ] ), "{$name}: the minutes read" );
            $this->assertSame( $expected, in_array( $id, $sql, true ), "{$name}: the SQL predicate" );
        }
    }

    public function test_the_outside_window_counts_take_one_query_whatever_the_history(): void {
        $team   = $this->insertTeam( 'U13 history' );
        $player = $this->insertPlayer( $team, 'Cas' );
        $query  = new MinutesShareQuery();

        $this->insertRecordedMatch( $team, $player, '2025-01-10' );
        $this->insertRecordedMatch( $team, $player, '2025-01-17' );
        $short = $this->countQueries( fn() => $query->outsideWindowForTeam( $team, '2026-03-01', '2026-03-31' ) );

        for ( $i = 1; $i <= 12; $i++ ) {
            $this->insertRecordedMatch( $team, $player, sprintf( '2025-02-%02d', $i ) );
        }
        $this->insertMatch( $team, '2025-04-01' ); // played, nothing recorded
        $long = $this->countQueries( fn() => $query->outsideWindowForTeam( $team, '2026-03-01', '2026-03-31' ) );

        $this->assertSame( 1, $short['queries'] );
        $this->assertSame( 1, $long['queries'], 'fourteen matches more, the same one query' );
        $this->assertSame(
            [ 'matches' => 14, 'played' => 15, 'earliest' => '2025-01-10', 'latest' => '2025-02-12' ],
            $long['result']
        );
    }

    public function test_played_matches_without_minutes_get_their_own_message(): void {
        $team = $this->insertTeam( 'U13 nothing recorded' );
        $p    = $this->insertPlayer( $team, 'Dirk' );
        $m    = $this->insertMatch( $team, '2025-05-01' );
        $this->insertAttendance( $m, $p, null );

        $data = ( new MinutesShareQuery() )->forTeam( $team, '2026-03-01', '2026-03-31' );
        $this->assertSame( [ 'matches' => 0, 'played' => 1, 'earliest' => '', 'latest' => '' ], $data['outside_window'] );

        $html = $this->renderMinutesShare( $team );
        $this->assertStringContainsString( 'This team has played matches, but none of them have minutes recorded.', $html );
        $this->assertStringNotContainsString( 'no played matches on record at all', $html );
    }

    public function test_a_team_without_played_matches_keeps_its_message(): void {
        $team = $this->insertTeam( 'U13 preseason' );
        $this->insertPlayer( $team, 'Eva' );
        $this->insertMatch( $team, gmdate( 'Y-m-d', strtotime( '+10 days' ) ) ); // not played yet

        $html = $this->renderMinutesShare( $team );
        $this->assertStringContainsString( 'This team has no played matches on record at all', $html );
        $this->assertStringNotContainsString( 'none of them have minutes recorded', $html );
    }

    /** @return array{player_id:int, total_minutes:int, available_minutes:int} */
    private function minutesRow( int $minutes, int $available ): array {
        return [ 'player_id' => 1, 'total_minutes' => $minutes, 'available_minutes' => $available ];
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function recordedBySql( array $ids ): array {
        global $wpdb;
        $in = implode( ',', array_map( 'intval', $ids ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $col = $wpdb->get_col( "SELECT a.id FROM {$this->p}tt_activities a WHERE a.id IN ({$in}) AND " . MinutesQuery::recordedMinutesSql( 'a' ) );
        return array_map( 'intval', (array) $col );
    }

    /**
     * @param callable(): mixed $fn
     * @return array{queries:int, result:mixed}
     */
    private function countQueries( callable $fn ): array {
        global $wpdb;
        $before = (int) $wpdb->num_queries;
        $result = $fn();
        return [ 'queries' => (int) $wpdb->num_queries - $before, 'result' => $result ];
    }

    private function renderMinutesShare( int $team ): string {
        ( new RolesService() )->ensureCapabilities();
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $_GET = [ 'tt_view' => 'standard-report', 'team_id' => (string) $team, 'from' => '2026-03-01', 'to' => '2026-03-31' ];
        $m    = new \ReflectionMethod( FrontendStandardReportsView::class, 'renderMinutesShare' );
        $m->setAccessible( true );
        ob_start();
        $m->invoke( null );
        return (string) ob_get_clean();
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Test',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMatch( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id' => $this->club, 'team_id' => $team_id, 'title' => 'Match ' . $date,
            'session_date' => $date, 'activity_type_key' => 'match',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertRecordedMatch( int $team_id, int $player_id, string $date ): void {
        $this->insertAttendance( $this->insertMatch( $team_id, $date ), $player_id, 60 );
    }

    private function insertAttendance( int $activity_id, int $player_id, ?int $minutes, int $is_guest = 0 ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id' => $this->club, 'activity_id' => $activity_id, 'player_id' => $player_id,
            'status' => 'present', 'is_guest' => $is_guest, 'record_type' => 'actual', 'minutes_played' => $minutes,
        ] );
    }

    /**
     * A tournament fixture on its own activity, its plan one position per
     * player for both periods.
     *
     * @param array<int,string> $plan player id => position code
     */
    private function insertFixture( int $team_id, string $date, int $duration, string $windows, bool $completed, array $plan ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_tournaments", [
            'club_id' => $this->club, 'uuid' => wp_generate_uuid4(), 'name' => 'Cup ' . $date,
            'start_date' => $date, 'end_date' => $date, 'team_id' => $team_id,
        ] );
        $tournament = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id' => $this->club, 'team_id' => $team_id, 'title' => 'Cup ' . $date, 'session_date' => $date,
            'activity_type_key' => 'match', 'activity_source_key' => 'tournament',
            'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        $activity = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_tournament_matches", [
            'club_id' => $this->club, 'tournament_id' => $tournament, 'sequence' => 1, 'label' => 'Wedstrijd 1',
            'opponent_name' => 'Ajax', 'opponent_level' => 'equal', 'duration_min' => $duration,
            'substitution_windows' => $windows, 'completed_at' => $completed ? $date . ' 12:00:00' : null,
            'activity_id' => $activity,
        ] );
        $match = (int) $wpdb->insert_id;
        foreach ( $plan as $pid => $position ) {
            foreach ( [ 0, 1 ] as $period ) {
                $wpdb->insert( "{$this->p}tt_tournament_assignments", [
                    'club_id' => $this->club, 'match_id' => $match, 'period_index' => $period,
                    'player_id' => $pid, 'position_code' => $position,
                ] );
            }
        }
        return $activity;
    }
}
