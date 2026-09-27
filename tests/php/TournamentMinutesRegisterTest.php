<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Reports\MinutesGridQuery;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Tournaments\Services\TournamentMinutesCalculator;
use TT\Modules\Tournaments\Services\TournamentMinutesResolver;

/**
 * #4053 — a player's tournament minutes come from the fixture's register, and
 * from the rotation plan only where the register holds nothing.
 *
 * The bug this pins: the completion step (#4032) asks a coach to confirm what
 * each child actually played and writes it to the fixture's register, and the
 * player's own Tournaments tab read the rotation plan instead — so a
 * correction never reached the record it was made for.
 *
 * Four states, because getting any one of them wrong is a wrong number on a
 * child's file:
 *
 *   1. **corrected** — the coach changed the figure; that figure wins.
 *   2. **confirmed zero** — the coach said the child did not get on; `0` is a
 *      real answer and must not fall back to the plan's twenty minutes.
 *   3. **legacy empty** — a fixture completed before v4.135.0 has a register
 *      row with no minutes on it; the plan answers, so the squad does not read
 *      as nil.
 *   4. **uncompleted** — nothing is confirmed yet; the plan answers and the
 *      figure is labelled as planned (#3713).
 *
 * And the surfaces, because the whole issue is that two of them disagreed:
 * the player endpoint, the coach's ticker, the minutes grid and the minutes
 * reports all come through `TournamentMinutesResolver`.
 *
 * The caller is a `tt_club_admin`, which resolves to the `academy_admin`
 * persona and holds `tournaments` + `player_tournaments` at global scope. A
 * WordPress `administrator` does not resolve to that persona and an
 * `add_cap()` would grant nothing, because `filterUserHasCap` overwrites
 * `$allcaps` with the matrix's answer — `PlayerTournamentHistoryTest` builds
 * its fixtures the same way.
 */
final class TournamentMinutesRegisterTest extends WP_UnitTestCase {

    private const TEAM = 40530;
    private const DATE = '2026-03-14';

    /** A fixture is 20 minutes with one substitution window: two periods of 10. */
    private const PERIOD = 10;

    /** @var string */
    private $p = '';

    /** @var int */
    private $admin = 0;

    /** @var int */
    private $tournament = 0;

    /** @var int */
    private $player = 0;

    /** @var int */
    private $teammate = 0;

    /** @var array<string, array{match_id:int, activity_id:int}> */
    private $fixtures = [];

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $this->p = $wpdb->prefix;
        $wpdb->hide_errors();

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        $this->admin = self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        wp_set_current_user( $this->admin );

        $wpdb->insert( "{$this->p}tt_teams", [ 'id' => self::TEAM, 'club_id' => 1, 'name' => 'JO11-1' ] );

        $this->seedTournament();

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the rule itself ────────────────────────────────────────────────

    /**
     * The one test to read before changing anything here: a confirmed zero is
     * not an absent value. Keying the fallback on `minutes === 0` would
     * silently replace a coach's "he did not get on" with the plan's twenty
     * minutes, which is the same class of wrong number the confirm step exists
     * to fix.
     */
    public function test_a_confirmed_zero_beats_the_plan_and_an_absent_register_does_not(): void {
        $resolved = TournamentMinutesResolver::arbitrate(
            [ 10 => 0, 20 => null, 30 => 15 ],
            [ 10 => 20, 20 => 20, 30 => 20, 40 => 20 ]
        );

        $this->assertSame( 0, $resolved[10]['minutes'], 'a confirmed 0 is a real figure' );
        $this->assertSame( TournamentMinutesResolver::SOURCE_REGISTER, $resolved[10]['source'] );

        $this->assertSame( 20, $resolved[20]['minutes'], 'a register row with no minutes falls back' );
        $this->assertSame( TournamentMinutesResolver::SOURCE_PLAN, $resolved[20]['source'] );

        $this->assertSame( 15, $resolved[30]['minutes'], 'a correction wins' );
        $this->assertSame( 20, $resolved[40]['minutes'], 'a player with only a plan still gets one' );
    }

    /** A player in neither is absent, not present at nil (#4021's sibling rule). */
    public function test_a_player_in_neither_the_register_nor_the_plan_is_absent(): void {
        $this->assertSame( [], TournamentMinutesResolver::arbitrate( [ 7 => null ], [] ) );
    }

    // ── the player's own record ────────────────────────────────────────

    public function test_the_four_states_each_read_correctly_on_the_player_endpoint(): void {
        $fixtures = $this->fixturesBySequence( $this->history( $this->player ) );

        $this->assertSame( 15, (int) $fixtures[1]['minutes'], 'the corrected figure, not the plan' );
        $this->assertSame( TournamentMinutesResolver::SOURCE_REGISTER, $fixtures[1]['minutes_source'] );

        $this->assertSame( 0, (int) $fixtures[2]['minutes'], 'a confirmed nil stays nil' );
        $this->assertSame( TournamentMinutesResolver::SOURCE_REGISTER, $fixtures[2]['minutes_source'] );

        $this->assertSame( 2 * self::PERIOD, (int) $fixtures[3]['minutes'], 'a pre-v4.135.0 fixture falls back to its plan' );
        $this->assertSame( TournamentMinutesResolver::SOURCE_PLAN, $fixtures[3]['minutes_source'] );

        $this->assertSame( self::PERIOD, (int) $fixtures[4]['minutes'], 'and a fixture still to come is its plan' );
        $this->assertSame( TournamentMinutesResolver::SOURCE_PLAN, $fixtures[4]['minutes_source'] );
        $this->assertFalse( (bool) $fixtures[4]['completed'], 'planned minutes must not read as played' );
    }

    /**
     * A coach who confirmed nil for a player the plan had starting: the minutes
     * are the register's and the role describes what happened, while `starts`
     * stays plan-derived so the ticker and the tab still agree on it.
     */
    public function test_a_confirmed_nil_reads_as_bench_although_the_plan_started_them(): void {
        $fixtures = $this->fixturesBySequence( $this->history( $this->player ) );

        $this->assertSame( TournamentMinutesCalculator::ROLE_BENCH, $fixtures[2]['role'] );
        $this->assertSame( TournamentMinutesCalculator::ROLE_START, $fixtures[1]['role'] );
    }

    public function test_the_tournament_total_is_the_confirmed_minutes_plus_the_fallback(): void {
        $entry = $this->tournamentEntry( $this->history( $this->player ) );

        // 15 corrected + 0 confirmed + 20 fallback; the uncompleted fixture is
        // scheduled, never played.
        $this->assertSame( 35, (int) $entry['played_minutes'] );
        $this->assertSame( self::PERIOD, (int) $entry['scheduled_minutes'] );
    }

    // ── the coach's ticker reads the same figure ───────────────────────

    public function test_the_ticker_and_the_players_own_record_agree_on_a_corrected_fixture(): void {
        $totals = $this->totalsByPlayer();
        $entry  = $this->tournamentEntry( $this->history( $this->player ) );

        $this->assertArrayHasKey( $this->player, $totals, 'the ticker must be readable, or this proves nothing' );
        $this->assertSame(
            (int) $entry['played_minutes'],
            (int) $totals[ $this->player ]['played_minutes'],
            'a correction reaches both screens or it reaches neither'
        );
        $this->assertSame( 35, (int) $totals[ $this->player ]['played_minutes'] );
        $this->assertSame( self::PERIOD, (int) $totals[ $this->player ]['expected_minutes'], 'expected stays the plan' );
    }

    /** The teammate never left the bench in the plan and nothing confirmed otherwise. */
    public function test_a_benched_teammate_is_nil_on_both_ends(): void {
        $totals = $this->totalsByPlayer();

        $this->assertArrayHasKey( $this->teammate, $totals );
        $this->assertSame( 0, (int) $totals[ $this->teammate ]['played_minutes'] );
    }

    // ── the minutes grid ──────────────────────────────────────────────

    public function test_the_grid_shows_the_corrected_figure_the_nil_and_the_fallback(): void {
        $matrix = ( new MinutesGridQuery() )->matrix( self::TEAM, '2026-03-01', '2026-03-31' );
        $cells  = $matrix['cells'][ $this->player ] ?? [];

        $this->assertSame( 15, (int) ( $cells[ $this->fixtures['corrected']['activity_id'] ]['minutes'] ?? -1 ) );
        $this->assertSame( 0, (int) ( $cells[ $this->fixtures['confirmed_zero']['activity_id'] ]['minutes'] ?? -1 ) );
        $this->assertSame(
            2 * self::PERIOD,
            (int) ( $cells[ $this->fixtures['legacy_empty']['activity_id'] ]['minutes'] ?? -1 ),
            'the legacy fixture read 0 for a child who played twenty minutes'
        );
    }

    /**
     * The grid is a data-entry surface, so an uncompleted fixture must not
     * arrive with its plan pre-printed in the box — that is planned minutes
     * reading as played (#3713).
     */
    public function test_the_grid_invents_no_cell_for_a_fixture_still_to_come(): void {
        $matrix = ( new MinutesGridQuery() )->matrix( self::TEAM, '2026-03-01', '2026-03-31' );
        $cells  = $matrix['cells'][ $this->player ] ?? [];

        $this->assertArrayNotHasKey(
            $this->fixtures['uncompleted']['activity_id'],
            $cells,
            'an uncompleted fixture has nothing played to report'
        );
    }

    // ── the minutes reports ───────────────────────────────────────────

    public function test_the_minutes_report_counts_the_legacy_fixture_it_used_to_drop(): void {
        $rows = ( new MinutesQuery() )->forTeam( self::TEAM, '2026-03-01', '2026-03-31' );

        $mine = null;
        foreach ( $rows as $row ) {
            if ( (int) $row['player_id'] === $this->player ) $mine = $row;
        }

        $this->assertNotNull( $mine, 'the player must be in the report, or this proves nothing' );
        // 15 corrected + 20 fallback. The confirmed nil is not an appearance,
        // which is how every other match already reads.
        $this->assertSame( 35, (int) $mine['total_minutes'] );
        $this->assertSame( 2, (int) $mine['matches'] );
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function history( int $player_id ): array {
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $player_id . '/tournaments' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status(), 'the history must be readable' );

        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        return (array) ( is_array( $data ) ? ( $data['data'] ?? [] ) : [] );
    }

    /**
     * @param array<string,mixed> $history
     * @return array<string,mixed>
     */
    private function tournamentEntry( array $history ): array {
        foreach ( (array) ( $history['tournaments'] ?? [] ) as $entry ) {
            if ( (int) $entry['tournament_id'] === $this->tournament ) return (array) $entry;
        }

        $this->fail( 'the history carried no entry for tournament ' . $this->tournament );
    }

    /**
     * @param array<string,mixed> $history
     * @return array<int, array<string,mixed>> sequence => fixture
     */
    private function fixturesBySequence( array $history ): array {
        $out = [];
        foreach ( (array) ( $this->tournamentEntry( $history )['fixtures'] ?? [] ) as $fixture ) {
            $out[ (int) $fixture['sequence'] ] = (array) $fixture;
        }

        $this->assertCount( 4, $out, 'all four fixtures are on the record' );

        return $out;
    }

    /** @return array<int, array<string,mixed>> player id => ticker row */
    private function totalsByPlayer(): array {
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/tournaments/' . $this->tournament . '/totals' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status(), 'the ticker must be readable' );

        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        $out  = [];
        foreach ( (array) ( $data['data']['players'] ?? [] ) as $row ) {
            $out[ (int) $row['player_id'] ] = (array) $row;
        }

        return $out;
    }

    /**
     * One tournament, four fixtures of two ten-minute periods, one player down
     * for all four and a teammate benched throughout.
     */
    private function seedTournament(): void {
        global $wpdb;

        $this->player   = $this->seedPlayer( 7 );
        $this->teammate = $this->seedPlayer( 9 );

        $wpdb->insert( "{$this->p}tt_tournaments", [
            'club_id'    => 1,
            'uuid'       => wp_generate_uuid4(),
            'name'       => 'Voorjaarstoernooi',
            'start_date' => self::DATE,
            'end_date'   => self::DATE,
            'team_id'    => self::TEAM,
            'created_by' => $this->admin,
        ] );
        $this->tournament = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $this->tournament, 'the tournament fixture must write' );

        foreach ( [ $this->player, $this->teammate ] as $player_id ) {
            $wpdb->insert( "{$this->p}tt_tournament_squad", [
                'club_id'            => 1,
                'tournament_id'      => $this->tournament,
                'player_id'          => $player_id,
                'eligible_positions' => '["CM"]',
            ] );
        }

        // 1. Corrected: the plan gives 20, the coach confirmed 15.
        $this->fixtures['corrected'] = $this->seedFixture( 1, true );
        $this->assignBothPeriods( $this->fixtures['corrected']['match_id'] );
        $this->register( $this->fixtures['corrected']['activity_id'], $this->player, 15 );
        $this->register( $this->fixtures['corrected']['activity_id'], $this->teammate, 0 );

        // 2. Confirmed zero: the plan starts them, the coach confirmed nil.
        $this->fixtures['confirmed_zero'] = $this->seedFixture( 2, true );
        $this->assignBothPeriods( $this->fixtures['confirmed_zero']['match_id'] );
        $this->register( $this->fixtures['confirmed_zero']['activity_id'], $this->player, 0 );
        $this->register( $this->fixtures['confirmed_zero']['activity_id'], $this->teammate, 0 );

        // 3. Legacy empty: completed before the confirm step, so the register
        //    rows carry no minutes at all.
        $this->fixtures['legacy_empty'] = $this->seedFixture( 3, true );
        $this->assignBothPeriods( $this->fixtures['legacy_empty']['match_id'] );
        $this->register( $this->fixtures['legacy_empty']['activity_id'], $this->player, null );
        $this->register( $this->fixtures['legacy_empty']['activity_id'], $this->teammate, null );

        // 4. Uncompleted: one period planned, nothing confirmed.
        $this->fixtures['uncompleted'] = $this->seedFixture( 4, false );
        $this->assign( $this->fixtures['uncompleted']['match_id'], 0, $this->player, 'CM' );
        $this->assign( $this->fixtures['uncompleted']['match_id'], 1, $this->player, 'BENCH' );
        $this->assign( $this->fixtures['uncompleted']['match_id'], 0, $this->teammate, 'BENCH' );
    }

    /** @return array{match_id:int, activity_id:int} */
    private function seedFixture( int $sequence, bool $completed ): array {
        global $wpdb;

        $activity_id = $this->seedFixtureActivity( $sequence, $completed );

        $ok = $wpdb->insert( "{$this->p}tt_tournament_matches", [
            'club_id'              => 1,
            'tournament_id'        => $this->tournament,
            'sequence'             => $sequence,
            'label'                => 'Wedstrijd ' . $sequence,
            'opponent_name'        => 'Ajax ' . $sequence,
            'opponent_level'       => 'equal',
            'duration_min'         => 2 * self::PERIOD,
            'substitution_windows' => '[' . self::PERIOD . ']',
            'completed_at'         => $completed ? self::DATE . ' 12:00:00' : null,
            'activity_id'          => $activity_id,
        ] );

        $this->assertNotFalse( $ok, 'the fixture row must write' );

        return [ 'match_id' => (int) $wpdb->insert_id, 'activity_id' => $activity_id ];
    }

    /**
     * The activity a kicked-off fixture gets: type `match`, source
     * `tournament`, which is what `kickoff` writes.
     */
    private function seedFixtureActivity( int $sequence, bool $completed ): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => 1,
            'team_id'             => self::TEAM,
            'title'               => 'Toernooiwedstrijd ' . $sequence,
            'session_date'        => self::DATE,
            'activity_type_key'   => 'match',
            'activity_source_key' => 'tournament',
            'activity_status_key' => $completed ? 'completed' : 'scheduled',
            'plan_state'          => $completed ? 'completed' : 'planned',
            'opponent'            => 'Ajax ' . $sequence,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function assignBothPeriods( int $match_id ): void {
        $this->assign( $match_id, 0, $this->player, 'CM' );
        $this->assign( $match_id, 1, $this->player, 'CM' );
        $this->assign( $match_id, 0, $this->teammate, 'BENCH' );
        $this->assign( $match_id, 1, $this->teammate, 'BENCH' );
    }

    private function assign( int $match_id, int $period, int $player_id, string $position ): void {
        global $wpdb;

        $ok = $wpdb->insert( "{$this->p}tt_tournament_assignments", [
            'club_id'       => 1,
            'match_id'      => $match_id,
            'period_index'  => $period,
            'player_id'     => $player_id,
            'position_code' => $position,
        ] );

        $this->assertNotFalse( $ok, 'the assignment fixture must write' );
    }

    /** A register row. `null` minutes is the pre-v4.135.0 shape. */
    private function register( int $activity_id, int $player_id, ?int $minutes ): void {
        global $wpdb;

        $ok = $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => 1,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'status'         => 'present',
            'minutes_played' => $minutes,
        ] );

        $this->assertNotFalse( $ok, 'the register fixture must write' );
    }

    private function seedPlayer( int $shirt ): int {
        global $wpdb;

        $ok = $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => 1,
            'team_id'       => self::TEAM,
            'first_name'    => 'Toernooi',
            'last_name'     => 'Speler ' . $shirt,
            'jersey_number' => $shirt,
            'status'        => 'active',
        ] );

        $this->assertNotFalse( $ok, 'the player fixture must write' );

        return (int) $wpdb->insert_id;
    }
}
