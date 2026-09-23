<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Analytics\EvalCoverageService;
use TT\Modules\Analytics\EvalWindowsRepository;

/**
 * #4026 — a round that has not opened is not a gap.
 *
 * Every window without an evaluation counted as one, and the windows run
 * the length of a season, so on 23 September a player already evaluated in
 * the round that was open still reported `gap_count: 3` — for October,
 * January and April. `total_gaps`, the "Gaps by coach" strip and the
 * Coverage % KPI were inflated identically, which cost the report its whole
 * purpose: a player who was up to date looked exactly like a player nobody
 * had seen, so there was no way to tell who was actually behind.
 *
 * The inverse matters just as much and is asserted here too: an open or
 * closed window with no evaluation is still a gap. A fix that excused those
 * would turn the report green by refusing to ask.
 */
final class EvalCoverageUpcomingWindowsTest extends WP_UnitTestCase {

    private string $p      = '';
    private int $teamId    = 0;
    private int $evaluated = 0;
    private int $missed    = 0;
    private int $coach     = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        $this->coach = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => 1, 'name' => 'Upcoming U12' ] );
        $this->teamId = (int) $wpdb->insert_id;

        $this->evaluated = $this->player( 'Seen' );
        $this->missed    = $this->player( 'Unseen' );
    }

    private function player( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => 1,
            'team_id'    => $this->teamId,
            'first_name' => 'Window',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function evaluate( int $player_id, string $date ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id'   => 1,
            'player_id' => $player_id,
            'coach_id'  => $this->coach,
            'eval_date' => $date,
        ] );
    }

    private function day( int $offset ): string {
        return gmdate( 'Y-m-d', strtotime( (string) current_time( 'Y-m-d' ) ) + $offset * DAY_IN_SECONDS );
    }

    /**
     * One window open today, one starting tomorrow — the shape the real
     * season has for most of the year.
     *
     * @param list<array{name:string,start:string,end:string}> $extra
     */
    private function windows( array $extra = [] ): void {
        ( new ConfigService() )->setJson( EvalWindowsRepository::CONFIG_KEY, array_merge( [
            [ 'name' => 'Open',     'start' => $this->day( -30 ), 'end' => $this->day( 30 ) ],
            [ 'name' => 'Upcoming', 'start' => $this->day( 31 ),  'end' => $this->day( 90 ) ],
        ], $extra ) );
    }

    /** The row for one player out of the matrix. */
    private function row( array $coverage, int $player_id ): array {
        foreach ( $coverage['teams'] as $team ) {
            foreach ( $team['players'] as $player ) {
                if ( (int) $player['player_id'] === $player_id ) return $player;
            }
        }
        $this->fail( 'player ' . $player_id . ' missing from the coverage matrix' );
    }

    // -----------------------------------------------------------------

    public function test_a_window_that_has_not_started_is_not_a_gap(): void {
        $this->windows();
        $this->evaluate( $this->evaluated, $this->day( -1 ) );

        $row = $this->row( ( new EvalCoverageService() )->coverage(), $this->evaluated );

        $this->assertSame( 0, $row['gap_count'], 'the open round is covered and the next one has not begun' );
        $this->assertSame( 'covered',  $row['cells'][0]['state'] );
        $this->assertSame( 'upcoming', $row['cells'][1]['state'] );
    }

    public function test_an_open_window_with_no_evaluation_is_still_a_gap(): void {
        $this->windows();

        $row = $this->row( ( new EvalCoverageService() )->coverage(), $this->missed );

        $this->assertSame( 1, $row['gap_count'], 'exactly one round is due, and it is empty' );
        $this->assertSame( 'gap',      $row['cells'][0]['state'] );
        $this->assertSame( 'upcoming', $row['cells'][1]['state'] );
    }

    /** A window that has closed with nothing in it is the worst case. */
    public function test_a_closed_window_with_no_evaluation_is_still_a_gap(): void {
        ( new ConfigService() )->setJson( EvalWindowsRepository::CONFIG_KEY, [
            [ 'name' => 'Closed',   'start' => $this->day( -60 ), 'end' => $this->day( -31 ) ],
            [ 'name' => 'Upcoming', 'start' => $this->day( 31 ),  'end' => $this->day( 90 ) ],
        ] );

        $row = $this->row( ( new EvalCoverageService() )->coverage(), $this->missed );

        $this->assertSame( 1, $row['gap_count'] );
        $this->assertSame( 'gap', $row['cells'][0]['state'] );
    }

    /**
     * The cell follows the data, not the calendar: an evaluation dated
     * inside a window that has not opened yet still covers it.
     */
    public function test_an_evaluation_inside_an_upcoming_window_covers_it(): void {
        $this->windows();
        $this->evaluate( $this->evaluated, $this->day( -1 ) );
        $this->evaluate( $this->evaluated, $this->day( 40 ) );

        $row = $this->row( ( new EvalCoverageService() )->coverage(), $this->evaluated );

        $this->assertSame( 'covered', $row['cells'][1]['state'] );
        $this->assertTrue( $row['cells'][1]['covered'] );
        $this->assertSame( 0, $row['gap_count'] );
    }

    public function test_the_totals_and_the_coach_strip_count_only_due_cells(): void {
        $this->windows();
        $this->evaluate( $this->evaluated, $this->day( -1 ) );

        $coverage = ( new EvalCoverageService() )->coverage();

        // Two players × two windows = four cells; two of them are upcoming.
        $this->assertSame( 2, $coverage['total_players'] );
        $this->assertSame( 2, $coverage['due_cells'], 'one due cell per player' );
        $this->assertSame( 1, $coverage['total_gaps'], 'only the unseen player is behind' );

        $tally = 0;
        foreach ( $coverage['coach_gaps'] as $g ) {
            $tally += (int) $g['gap_count'];
        }
        $this->assertSame( 1, $tally, 'the per-coach strip must agree with total_gaps' );
    }

    /**
     * The KPI the Head of Development reads. Coverage is covered ÷ due, so
     * an academy that is fully up to date reads 100% in September rather
     * than 25% because January exists.
     */
    public function test_a_fully_up_to_date_academy_reads_as_complete(): void {
        $this->windows();
        $this->evaluate( $this->evaluated, $this->day( -1 ) );
        $this->evaluate( $this->missed,    $this->day( -2 ) );

        $coverage = ( new EvalCoverageService() )->coverage();

        $this->assertSame( 0, $coverage['total_gaps'] );
        $this->assertSame( 2, $coverage['due_cells'] );
        $this->assertSame( [], $coverage['coach_gaps'], 'nobody is behind, so the strip is empty' );
        $this->assertSame(
            100.0,
            round( ( $coverage['due_cells'] - $coverage['total_gaps'] ) / $coverage['due_cells'] * 100, 1 ),
            'the Coverage KPI'
        );
    }

    /** Every window ahead: due is zero, and zero gaps is then honest. */
    public function test_a_season_that_has_not_begun_reports_no_gaps_and_nothing_due(): void {
        ( new ConfigService() )->setJson( EvalWindowsRepository::CONFIG_KEY, [
            [ 'name' => 'First',  'start' => $this->day( 10 ), 'end' => $this->day( 40 ) ],
            [ 'name' => 'Second', 'start' => $this->day( 41 ), 'end' => $this->day( 80 ) ],
        ] );

        $coverage = ( new EvalCoverageService() )->coverage();

        $this->assertTrue( $coverage['configured'], 'windows exist, so the question was asked' );
        $this->assertSame( 0, $coverage['due_cells'] );
        $this->assertSame( 0, $coverage['total_gaps'] );
    }

    /** With one open round, every player has exactly one cell to fill. */
    public function test_one_open_window_makes_every_player_due_once(): void {
        ( new ConfigService() )->setJson( EvalWindowsRepository::CONFIG_KEY, [
            [ 'name' => 'Open', 'start' => $this->day( -30 ), 'end' => $this->day( 30 ) ],
        ] );

        $coverage = ( new EvalCoverageService() )->coverage();

        $this->assertSame( 2, $coverage['due_cells'] );
        $this->assertSame( 2, $coverage['total_gaps'] );
    }
}
