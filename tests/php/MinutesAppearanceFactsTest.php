<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;
use TT\Modules\MatchExecution\Domain\MatchStints;

/**
 * #4059 / #4060 — starts, substitute appearances and substitutions.
 *
 * The minutes report read both half line-ups into one starts counter, so a
 * player who came on at half time was credited with a start: five
 * eleven-a-side games reported 69 starts. The substitution columns read only
 * the substitution log, where a half-time change never appears, so the same
 * player showed no substitute appearance and the player they replaced no
 * substitution.
 *
 * All three are now read off `MatchStints` — the timeline the persisted
 * minutes are summed from — where a match-execution record exists, and off
 * the first-half line-up alone where it does not.
 */
final class MinutesAppearanceFactsTest extends WP_UnitTestCase {

    private string $p = '';
    private int $club = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    // ── the derivation itself ──────────────────────────────────────────

    public function test_a_player_on_at_kick_off_for_the_whole_match_started_and_nothing_else(): void {
        $facts = MinutesQuery::appearanceFacts( [ 1 => [ 7 ], 2 => [ 7 ] ], [], 35 );

        $this->assertSame( [ 'started' => true, 'came_on' => false, 'went_off' => false ], $facts[7] );
    }

    public function test_a_player_on_only_from_the_second_half_came_on_and_did_not_start(): void {
        $facts = MinutesQuery::appearanceFacts( [ 1 => [ 7 ], 2 => [ 8 ] ], [], 35 );

        $this->assertSame( [ 'started' => false, 'came_on' => true, 'went_off' => false ], $facts[8] );
        $this->assertSame( [ 'started' => true, 'came_on' => false, 'went_off' => true ], $facts[7], 'the player replaced at the break went off' );
    }

    public function test_a_player_off_at_sixty_of_seventy_went_off(): void {
        $subs  = [ $this->sub( 2, 25, 7, 9 ) ];
        $facts = MinutesQuery::appearanceFacts( [ 1 => [ 7 ], 2 => [ 7 ] ], $subs, 35 );

        $this->assertTrue( $facts[7]['started'] );
        $this->assertTrue( $facts[7]['went_off'] );
        $this->assertFalse( $facts[7]['came_on'] );
        $this->assertTrue( $facts[9]['came_on'] );
    }

    public function test_off_at_twenty_and_back_at_twenty_five_is_one_of_each(): void {
        $subs = [
            $this->sub( 1, 20, 7, 9 ),
            $this->sub( 1, 25, 9, 7 ),
        ];
        $facts = MinutesQuery::appearanceFacts( [ 1 => [ 7 ], 2 => [ 7 ] ], $subs, 35 );

        $this->assertSame( [ 'started' => true, 'came_on' => true, 'went_off' => true ], $facts[7] );
    }

    public function test_a_quarters_match_is_read_period_by_period(): void {
        $lineups = [
            1 => [ 1, 3 ],
            2 => [ 1, 3 ],
            3 => [ 2, 3 ],
            4 => [ 2, 3 ],
        ];
        $facts = MinutesQuery::appearanceFacts( $lineups, [], 15, 4 );

        $this->assertSame( [ 'started' => true, 'came_on' => false, 'went_off' => true ], $facts[1], 'off after the second quarter' );
        $this->assertSame( [ 'started' => false, 'came_on' => true, 'went_off' => false ], $facts[2], 'on for the third quarter, not a start' );
        $this->assertSame( [ 'started' => true, 'came_on' => false, 'went_off' => false ], $facts[3], 'all four quarters is one stay on the pitch' );
    }

    public function test_without_an_execution_record_the_first_half_line_up_is_the_start_and_nothing_more(): void {
        $facts = MinutesQuery::appearanceFacts( [ 1 => [ 7 ], 2 => [ 8 ] ], null, 35 );

        $this->assertSame( [ 'started' => true, 'came_on' => false, 'went_off' => false ], $facts[7] );
        $this->assertArrayNotHasKey( 8, $facts, 'no timeline, so a half-time entrant is not invented as a substitute' );
    }

    public function test_touching_spells_are_one_stay_on_the_pitch(): void {
        $facts = MatchStints::appearances( [ 5 => [ [ 0, 35 ], [ 35, 70 ] ] ], 70 );

        $this->assertSame( [ 'started' => true, 'came_on' => false, 'went_off' => false ], $facts[5] );
    }

    public function test_the_two_half_walk_is_unchanged_by_the_period_walk(): void {
        $subs = [ $this->sub( 1, 20, 7, 9 ) ];

        $this->assertSame(
            MatchStints::intervalsForPeriods( $subs, [ 1 => [ 7 ], 2 => [ 9 ] ], [ 1 => 35, 2 => 35 ] ),
            MatchStints::intervals( $subs, [ 7 ], [ 9 ], 35, 35 )
        );
    }

    // ── the report ─────────────────────────────────────────────────────

    /**
     * Half 1 / half 2 / both / neither, in one match run through match
     * execution.
     */
    public function test_each_line_up_shape_is_counted_once_and_correctly(): void {
        $team  = $this->insertTeam( 'U14 shapes' );
        $both  = $this->insertPlayer( $team, 'Both' );
        $first = $this->insertPlayer( $team, 'First' );
        $second = $this->insertPlayer( $team, 'Second' );
        $neither = $this->insertPlayer( $team, 'Neither' );

        $match = $this->insertMatch( $team, '2026-03-01' );
        $prep  = $this->insertPrep( $match, 35 );
        $this->insertLineup( $prep, 1, 1, $both );
        $this->insertLineup( $prep, 1, 2, $first );
        $this->insertLineup( $prep, 2, 1, $both );
        $this->insertLineup( $prep, 2, 2, $second );
        $this->insertExecution( $match, $prep );

        $this->insertMinutes( $match, $both, 70 );
        $this->insertMinutes( $match, $first, 35 );
        $this->insertMinutes( $match, $second, 35 );
        $this->insertMinutes( $match, $neither, 10 );

        $rows = $this->byPlayer( ( new MinutesQuery() )->forTeam( $team, '2026-01-01', '2026-12-31' ) );

        $this->assertSame( [ 1, 0, 0 ], $this->facts( $rows[ $both ] ), 'both halves: one start, nothing else' );
        $this->assertSame( [ 1, 0, 1 ], $this->facts( $rows[ $first ] ), 'first half only: a start, and off at the break' );
        $this->assertSame( [ 0, 1, 0 ], $this->facts( $rows[ $second ] ), 'second half only: no start, one substitute appearance' );
        $this->assertSame( [ 0, 0, 0 ], $this->facts( $rows[ $neither ] ), 'no line-up and no substitution: no appearance facts' );
        $this->assertSame( 1, (int) $rows[ $neither ]['matches'], 'but recorded minutes are still an appearance' );
    }

    public function test_the_same_match_without_an_execution_record_falls_back_to_the_first_half(): void {
        $team   = $this->insertTeam( 'U14 paper' );
        $first  = $this->insertPlayer( $team, 'First' );
        $second = $this->insertPlayer( $team, 'Second' );

        $match = $this->insertMatch( $team, '2026-03-01' );
        $prep  = $this->insertPrep( $match, 35 );
        $this->insertLineup( $prep, 1, 1, $first );
        $this->insertLineup( $prep, 2, 1, $second );
        $this->insertMinutes( $match, $first, 35 );
        $this->insertMinutes( $match, $second, 35 );

        $rows = $this->byPlayer( ( new MinutesQuery() )->forTeam( $team, '2026-01-01', '2026-12-31' ) );

        $this->assertSame( [ 1, 0, 0 ], $this->facts( $rows[ $first ] ) );
        $this->assertSame( [ 0, 0, 0 ], $this->facts( $rows[ $second ] ), 'no record of the change, so 0 — not a start' );
    }

    /**
     * The reported shape: five eleven-a-side games with three half-time
     * changes each. The old counter produced 14 a game, 70 in all.
     */
    public function test_five_eleven_a_side_games_cannot_exceed_fifty_five_starts(): void {
        $team    = $this->insertTeam( 'U14 season' );
        $players = [];
        for ( $i = 1; $i <= 14; $i++ ) {
            $players[] = $this->insertPlayer( $team, 'P' . $i );
        }

        for ( $g = 1; $g <= 5; $g++ ) {
            $match = $this->insertMatch( $team, sprintf( '2026-04-%02d', $g ) );
            $prep  = $this->insertPrep( $match, 35 );
            $this->insertExecution( $match, $prep );

            // Half 1: players 0..10. Half 2: 0..7 stay, 11..13 replace 8..10.
            foreach ( array_slice( $players, 0, 11 ) as $slot => $pid ) {
                $this->insertLineup( $prep, 1, $slot + 1, $pid );
            }
            $second = array_merge( array_slice( $players, 0, 8 ), array_slice( $players, 11, 3 ) );
            foreach ( $second as $slot => $pid ) {
                $this->insertLineup( $prep, 2, $slot + 1, $pid );
            }

            foreach ( $players as $idx => $pid ) {
                $this->insertMinutes( $match, $pid, $idx < 8 ? 70 : 35 );
            }
        }

        $rows = ( new MinutesQuery() )->forTeam( $team, '2026-01-01', '2026-12-31' );

        $starts   = array_sum( array_column( $rows, 'starts' ) );
        $subs_in  = array_sum( array_column( $rows, 'subs_in' ) );
        $subs_off = array_sum( array_column( $rows, 'subs_off' ) );

        $this->assertLessThanOrEqual( 55, $starts );
        $this->assertSame( 55, $starts, 'eleven starts a game, five games' );
        $this->assertSame( 15, $subs_in, 'three half-time entrants a game appear as substitutes' );
        $this->assertSame( 15, $subs_off, 'and the three they replaced as substitutions' );

        foreach ( $rows as $row ) {
            $this->assertLessThanOrEqual( (int) $row['matches'], (int) $row['starts'], 'starts never outrun matches' );
        }
    }

    /**
     * A consumer, read end to end: the team statistics appearances block
     * takes `starts` / `subs_in` from MinutesQuery and must show the
     * corrected figures.
     */
    public function test_team_match_stats_appearances_carry_the_corrected_figures(): void {
        $team   = $this->insertTeam( 'U14 stats' );
        $first  = $this->insertPlayer( $team, 'First' );
        $second = $this->insertPlayer( $team, 'Second' );

        $match = $this->insertMatch( $team, '2026-05-01' );
        $prep  = $this->insertPrep( $match, 35 );
        $this->insertLineup( $prep, 1, 1, $first );
        $this->insertLineup( $prep, 2, 1, $second );
        $this->insertExecution( $match, $prep );
        $this->insertMinutes( $match, $first, 35 );
        $this->insertMinutes( $match, $second, 35 );

        $stats = ( new TeamMatchStatsQuery() )->forTeam( $team, [ 'from' => '2026-01-01', 'to' => '2026-12-31' ] );

        $by = [];
        foreach ( $stats['appearances'] as $row ) {
            $by[ (int) $row['player_id'] ] = $row;
        }

        $this->assertSame( 1, (int) $by[ $first ]['starts'] );
        $this->assertSame( 0, (int) $by[ $second ]['starts'], 'a half-time entrant is not a starter on the statistics tab either' );
        $this->assertSame( 1, (int) $by[ $second ]['subs_in'] );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function sub( int $half, int $minute, int $off, int $on ): object {
        return (object) [
            'half'           => $half,
            'minute_in_half' => $minute,
            'player_off_id'  => $off,
            'player_on_id'   => $on,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int, array<string,mixed>>
     */
    private function byPlayer( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row['player_id'] ] = $row;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return list<int> [ starts, subs_in, subs_off ]
     */
    private function facts( array $row ): array {
        return [ (int) $row['starts'], (int) $row['subs_in'], (int) $row['subs_off'] ];
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
            'last_name'  => 'Appearance',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMatch( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Match ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'match',
            'game_subtype_key'    => 'League',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPrep( int $activity_id, int $half_length ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_match_prep", [
            'uuid'                => wp_generate_uuid4(),
            'club_id'             => $this->club,
            'activity_id'         => $activity_id,
            'half_length_minutes' => $half_length,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertLineup( int $prep_id, int $half, int $slot, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_match_prep_lineup", [
            'club_id'       => $this->club,
            'match_prep_id' => $prep_id,
            'half'          => $half,
            'slot_number'   => $slot,
            'player_id'     => $player_id,
        ] );
    }

    private function insertExecution( int $activity_id, int $prep_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_match_execution", [
            'uuid'          => wp_generate_uuid4(),
            'club_id'       => $this->club,
            'activity_id'   => $activity_id,
            'match_prep_id' => $prep_id,
            'state'         => 'finalized',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMinutes( int $activity_id, int $player_id, int $minutes ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => 'present',
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'minutes_played' => $minutes,
        ] );
    }
}
