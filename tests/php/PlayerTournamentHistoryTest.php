<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Tournaments\PlayerTournamentAccess;
use TT\Modules\Tournaments\Services\TournamentMinutesCalculator;

/**
 * #3561 (epic #3558) — `GET /players/{id}/tournaments`, and the one copy of
 * the minutes maths behind it.
 *
 * The constraint the epic names first is **parity**: the player file and
 * the tournament ticker have to agree, because they are the same rotation
 * plan read from two ends. So the first test here fetches both endpoints
 * for the same seeded tournament and compares them per player, and it is
 * the test to read before changing either.
 *
 * ## The fixture
 *
 * The caller is a `tt_club_admin`, which resolves to the `academy_admin`
 * persona and holds `tournaments` and `player_tournaments` at global scope
 * in the seed — a WordPress `administrator` does not resolve to that
 * persona, and `AuthorizationModule::filterUserHasCap` overwrites
 * `$allcaps` with the matrix's answer, so an `add_cap()` would grant
 * nothing. `PlayerTournamentsAccessTest` (#3560) builds its coach, player
 * and parent fixtures the same way; the seeding helpers below follow it.
 *
 * Every refusal assertion is paired with one that succeeds, so a fixture
 * that could not read at all would fail rather than pass over a 403.
 */
final class PlayerTournamentHistoryTest extends WP_UnitTestCase {

    private const TEAM = 35610;

    /** @var int */
    private $admin = 0;

    /** @var int */
    private $tournament = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        $this->admin = self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        wp_set_current_user( $this->admin );

        $this->seedTeam( self::TEAM );

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── parity: the two ends of the same rotation plan ─────────────────

    /**
     * The constraint the epic names first. If this fails, the minutes maths
     * has been copied rather than shared — put it back in
     * `TournamentMinutesCalculator` before changing either number.
     */
    public function test_the_player_endpoint_agrees_with_the_tournaments_totals(): void {
        [ $a, $b ] = $this->seedTournamentWithTwoPlayers();

        $totals = $this->totalsByPlayer( $this->tournament );
        $this->assertArrayHasKey( $a, $totals, 'the ticker must be readable, or this test proves nothing' );
        $this->assertArrayHasKey( $b, $totals );

        foreach ( [ $a, $b ] as $player_id ) {
            $history = $this->history( $player_id );
            $mine    = $this->tournamentEntry( $history, $this->tournament );

            $this->assertSame(
                (int) $totals[ $player_id ]['played_minutes'],
                (int) $mine['played_minutes'],
                'played minutes agree for player ' . $player_id
            );
            $this->assertSame(
                (int) $totals[ $player_id ]['starts'],
                (int) $mine['starts'],
                'starts agree for player ' . $player_id
            );
            $this->assertSame(
                (int) $totals[ $player_id ]['full_matches'],
                (int) $mine['full_matches'],
                'full matches agree for player ' . $player_id
            );
        }
    }

    /** The ticker's own response shape is untouched. */
    public function test_the_tournaments_totals_response_keeps_its_shape(): void {
        [ $a ] = $this->seedTournamentWithTwoPlayers();

        $totals = $this->totalsByPlayer( $this->tournament );
        $row    = $totals[ $a ];

        foreach ( [
            'player_id', 'first_name', 'last_name', 'full_name', 'photo_url',
            'eligible_positions', 'target_minutes', 'played_minutes',
            'expected_minutes', 'starts', 'full_matches',
        ] as $key ) {
            $this->assertArrayHasKey( $key, $row, $key . ' is part of the ticker contract' );
        }
        $this->assertArrayNotHasKey( '_periods_played', $row, 'the internal accumulator never leaked and must not start' );
    }

    // ── what the calculator answers ────────────────────────────────────

    public function test_a_bench_only_fixture_is_no_minutes_and_the_bench_role(): void {
        [ , $b ] = $this->seedTournamentWithTwoPlayers();

        $history = $this->history( $b );
        $entry   = $this->tournamentEntry( $history, $this->tournament );
        $benched = $this->fixtureBySequence( $entry, 2 );

        $this->assertSame( 0, (int) $benched['minutes'] );
        $this->assertSame( TournamentMinutesCalculator::ROLE_BENCH, $benched['role'] );
        $this->assertSame( [], $benched['positions'] );
    }

    public function test_a_player_who_came_on_later_is_a_sub_not_a_start(): void {
        [ , $b ] = $this->seedTournamentWithTwoPlayers();

        $entry = $this->tournamentEntry( $this->history( $b ), $this->tournament );
        $first = $this->fixtureBySequence( $entry, 1 );

        $this->assertSame( TournamentMinutesCalculator::ROLE_SUB, $first['role'] );
        $this->assertGreaterThan( 0, (int) $first['minutes'] );
    }

    /**
     * #3532's rule, on the player's own record: a goalless draw and a
     * fixture nobody typed in are different facts.
     */
    public function test_a_fixture_with_no_result_reports_null_scores_not_nil_nil(): void {
        [ $a ] = $this->seedTournamentWithTwoPlayers();

        $entry     = $this->tournamentEntry( $this->history( $a ), $this->tournament );
        $scored    = $this->fixtureBySequence( $entry, 1 );
        $unplayed  = $this->fixtureBySequence( $entry, 3 );

        $this->assertSame( 3, $scored['our_score'] );
        $this->assertSame( 1, $scored['their_score'] );
        $this->assertNull( $unplayed['our_score'] );
        $this->assertNull( $unplayed['their_score'] );
    }

    public function test_the_upcoming_block_names_the_fixtures_still_to_play(): void {
        [ $a ] = $this->seedTournamentWithTwoPlayers();

        $history = $this->history( $a );

        $this->assertNotNull( $history['upcoming'] );
        $this->assertSame( $this->tournament, (int) $history['upcoming']['tournament_id'] );
        $this->assertSame( 1, (int) $history['upcoming']['fixture_count'], 'fixture 3 is the only one left' );
        $this->assertGreaterThan( 0, (int) $history['upcoming']['scheduled_minutes'] );
    }

    public function test_played_minutes_exclude_the_fixtures_not_yet_completed(): void {
        [ $a ] = $this->seedTournamentWithTwoPlayers();

        $entry = $this->tournamentEntry( $this->history( $a ), $this->tournament );

        $this->assertGreaterThan( 0, (int) $entry['played_minutes'] );
        $this->assertGreaterThan( 0, (int) $entry['scheduled_minutes'] );
        $this->assertSame( 2, (int) $entry['completed_count'] );
        $this->assertSame( 3, (int) $entry['fixture_count'] );
    }

    public function test_an_archived_tournament_is_in_neither_the_history_nor_the_upcoming(): void {
        global $wpdb;
        [ $a ] = $this->seedTournamentWithTwoPlayers();

        $this->assertNotSame( [], $this->history( $a )['tournaments'], 'it is there before it is archived' );

        $wpdb->update(
            $wpdb->prefix . 'tt_tournaments',
            [ 'archived_at' => '2026-01-01 00:00:00' ],
            [ 'id' => $this->tournament ]
        );

        $history = $this->history( $a );
        $this->assertSame( [], $history['tournaments'] );
        $this->assertNull( $history['upcoming'] );
        $this->assertSame( 0, (int) $history['totals']['minutes'] );
    }

    public function test_a_trashed_tournament_is_gone_too(): void {
        global $wpdb;
        [ $a ] = $this->seedTournamentWithTwoPlayers();

        $wpdb->update(
            $wpdb->prefix . 'tt_tournaments',
            [ 'trashed_at' => '2026-01-01 00:00:00' ],
            [ 'id' => $this->tournament ]
        );

        $this->assertSame( [], $this->history( $a )['tournaments'] );
    }

    /**
     * Keyed on `player_id`, never on a team or an account: a player who
     * left and came back keeps what they did.
     */
    public function test_a_player_with_no_team_keeps_their_history(): void {
        global $wpdb;
        [ $a ] = $this->seedTournamentWithTwoPlayers();

        $wpdb->update( $wpdb->prefix . 'tt_players', [ 'team_id' => 0 ], [ 'id' => $a ] );

        $history = $this->history( $a );
        $this->assertCount( 1, $history['tournaments'] );
        $this->assertGreaterThan( 0, (int) $history['totals']['minutes'] );
    }

    public function test_a_player_never_in_a_squad_gets_an_empty_answer_not_a_404(): void {
        $nobody = $this->seedPlayer( self::TEAM );

        [ $data, $status ] = $this->fetch( $nobody );

        $this->assertSame( 200, $status );
        $this->assertSame( [], $data['data']['tournaments'] );
        $this->assertNull( $data['data']['upcoming'] );
        $this->assertSame( 0, (int) $data['data']['totals']['tournaments'] );
    }

    // ── who may read it ────────────────────────────────────────────────

    public function test_a_coach_of_another_team_is_refused_and_the_coach_of_this_one_is_not(): void {
        [ $a ] = $this->seedTournamentWithTwoPlayers();

        $other_team = self::TEAM + 1;
        $this->seedTeam( $other_team );

        $mine   = $this->seedCoachOnTeam( self::TEAM );
        $theirs = $this->seedCoachOnTeam( $other_team );

        $this->assertTrue(
            PlayerTournamentAccess::canRead( $mine, $a ),
            'the coach of the player\'s squad reads it'
        );
        $this->assertFalse(
            PlayerTournamentAccess::canRead( $theirs, $a ),
            'and a coach of another squad does not'
        );
    }

    public function test_a_parent_whose_child_closed_the_section_gets_section_private(): void {
        global $wpdb;
        [ $a ] = $this->seedTournamentWithTwoPlayers();

        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( $wpdb->prefix . 'tt_player_parents', [
            'club_id'        => 1,
            'player_id'      => $a,
            'parent_user_id' => $parent,
        ] );

        wp_set_current_user( $parent );

        [ $data, $status ] = $this->fetch( $a );
        $this->assertSame( 200, $status, 'the section is shared by default' );
        $this->assertCount( 1, $data['data']['tournaments'] );

        ( new PlayerParentVisibilityRepository() )->setVisibility( $a, 'tournaments', false );

        [ $data, $status ] = $this->fetch( $a );
        $this->assertSame( 403, $status );
        $this->assertSame( 'section_private', $data['errors'][0]['code'] ?? null );
    }

    public function test_the_player_reads_their_own_record(): void {
        global $wpdb;
        [ $a ] = $this->seedTournamentWithTwoPlayers();

        $uid = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->update( $wpdb->prefix . 'tt_players', [ 'wp_user_id' => $uid ], [ 'id' => $a ] );

        $this->assertTrue( PlayerTournamentAccess::canRead( $uid, $a ) );

        $other = $this->seedPlayer( self::TEAM );
        $this->assertFalse(
            PlayerTournamentAccess::canRead( $uid, $other ),
            'and nobody else\'s'
        );
    }

    // ── the route's own contract ───────────────────────────────────────

    public function test_the_route_declares_its_args(): void {
        $routes = rest_get_server()->get_routes();
        $key    = '/talenttrack/v1/players/(?P<id>\d+)/tournaments';

        $this->assertArrayHasKey( $key, $routes );
        $this->assertArrayHasKey( 'id', $routes[ $key ][0]['args'] ?? [] );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @return array{0:array<string,mixed>,1:int} */
    private function fetch( int $player_id ): array {
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $player_id . '/tournaments' );
        $response = rest_get_server()->dispatch( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }

    /** @return array<string,mixed> */
    private function history( int $player_id ): array {
        [ $data, $status ] = $this->fetch( $player_id );
        $this->assertSame( 200, $status, 'the history must be readable, or the assertions below prove nothing' );

        return (array) $data['data'];
    }

    /** @return array<int, array<string,mixed>> player id => ticker row */
    private function totalsByPlayer( int $tournament_id ): array {
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/tournaments/' . $tournament_id . '/totals' );
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
     * @param array<string,mixed> $history
     * @return array<string,mixed>
     */
    private function tournamentEntry( array $history, int $tournament_id ): array {
        foreach ( (array) ( $history['tournaments'] ?? [] ) as $entry ) {
            if ( (int) $entry['tournament_id'] === $tournament_id ) return (array) $entry;
        }

        $this->fail( 'the history carried no entry for tournament ' . $tournament_id );
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function fixtureBySequence( array $entry, int $sequence ): array {
        foreach ( (array) ( $entry['fixtures'] ?? [] ) as $fixture ) {
            if ( (int) $fixture['sequence'] === $sequence ) return (array) $fixture;
        }

        $this->fail( 'the tournament carried no fixture at sequence ' . $sequence );
    }

    /**
     * One tournament, three fixtures of two periods each, two players.
     *
     *   fixture 1 (completed, 3-1): A both periods, B second period only
     *   fixture 2 (completed, 0-0): A first period, B on the bench
     *   fixture 3 (not completed, no result): A first period, B both
     *
     * @return array{0:int,1:int} the two player ids
     */
    private function seedTournamentWithTwoPlayers(): array {
        global $wpdb;

        $a = $this->seedPlayer( self::TEAM );
        $b = $this->seedPlayer( self::TEAM );

        $wpdb->insert( $wpdb->prefix . 'tt_tournaments', [
            'club_id'    => 1,
            'uuid'       => wp_generate_uuid4(),
            'name'       => 'Paastoernooi',
            'start_date' => '2026-04-06',
            'end_date'   => '2026-04-06',
            'team_id'    => self::TEAM,
            'created_by' => $this->admin,
        ] );
        $this->tournament = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $this->tournament, 'the tournament fixture must write' );

        foreach ( [ $a, $b ] as $player_id ) {
            $wpdb->insert( $wpdb->prefix . 'tt_tournament_squad', [
                'club_id'            => 1,
                'tournament_id'      => $this->tournament,
                'player_id'          => $player_id,
                'eligible_positions' => '["MID"]',
                'target_minutes'     => 30,
            ] );
        }

        // One substitution window makes two periods of 10 minutes each.
        $one   = $this->seedMatch( 1, true,  3, 1 );
        $two   = $this->seedMatch( 2, true,  0, 0 );
        $three = $this->seedMatch( 3, false, null, null );

        $this->assign( $one, 0, $a, 'MID' );
        $this->assign( $one, 1, $a, 'MID' );
        $this->assign( $one, 0, $b, 'BENCH' );
        $this->assign( $one, 1, $b, 'MID' );

        $this->assign( $two, 0, $a, 'MID' );
        $this->assign( $two, 0, $b, 'BENCH' );
        $this->assign( $two, 1, $b, 'BENCH' );

        $this->assign( $three, 0, $a, 'MID' );
        $this->assign( $three, 0, $b, 'MID' );
        $this->assign( $three, 1, $b, 'MID' );

        return [ $a, $b ];
    }

    private function seedMatch( int $sequence, bool $completed, ?int $ours, ?int $theirs ): int {
        global $wpdb;

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_tournament_matches', [
            'club_id'              => 1,
            'tournament_id'        => $this->tournament,
            'sequence'             => $sequence,
            'label'                => 'Wedstrijd ' . $sequence,
            'opponent_name'        => 'Ajax ' . $sequence,
            'opponent_level'       => $sequence === 1 ? 'stronger' : 'equal',
            'duration_min'         => 20,
            'substitution_windows' => '[10]',
            'completed_at'         => $completed ? '2026-04-06 12:00:00' : null,
            'our_score'            => $ours,
            'their_score'          => $theirs,
        ] );

        $this->assertNotFalse( $ok, 'the fixture row must write' );

        return (int) $wpdb->insert_id;
    }

    private function assign( int $match_id, int $period, int $player_id, string $position ): void {
        global $wpdb;

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_tournament_assignments', [
            'club_id'       => 1,
            'match_id'      => $match_id,
            'period_index'  => $period,
            'player_id'     => $player_id,
            'position_code' => $position,
        ] );

        $this->assertNotFalse( $ok, 'the assignment fixture must write' );
    }

    private function seedTeam( int $team_id ): void {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'id'      => $team_id,
            'club_id' => 1,
            'name'    => 'Team ' . $team_id,
        ] );
    }

    private function seedPlayer( int $team_id ): int {
        global $wpdb;

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => 'Toernooi',
            'last_name'  => 'Speler',
            'team_id'    => $team_id,
            'status'     => 'active',
        ] );

        $this->assertNotFalse( $ok, 'the player fixture must write' );

        return (int) $wpdb->insert_id;
    }

    private function seedCoachOnTeam( int $team_id ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Squad',
            'last_name'  => 'Coach',
            'role_type'  => 'coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => 1,
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        return $uid;
    }
}
