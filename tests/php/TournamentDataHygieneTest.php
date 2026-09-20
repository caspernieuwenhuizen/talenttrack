<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Configuration\LookupCanonicalSeeds;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\Generators\TournamentGenerator;
use TT\Shared\Frontend\Components\LookupColourChip;

/**
 * #3559 — four faults in tournament data that a per-player view would put
 * straight in front of families.
 *
 * The demo generator wrote 1-based periods into a 0-based planner, so every
 * demo player had zero starts and one assignment per match sat on a period
 * the fixture does not have. Its position codes were its own invention. The
 * opponent level was stored without ever being checked. And the chip that
 * shows it had no way to paint a level's colour without making it
 * unreadable.
 */
final class TournamentDataHygieneTest extends WP_UnitTestCase {

    /** The planner's set, plus the bench. */
    private const ALLOWED = [ 'GK', 'CB', 'LB', 'RB', 'DM', 'CM', 'AM', 'LW', 'RW', 'ST', 'BENCH' ];

    private const SQUAD_SIZE = 12;

    private string $p;
    private int $team_id = 0;

    /** @var int[] */
    private array $player_ids = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $this->p = $wpdb->prefix;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** @return object[] the player objects the generator is handed */
    private function seedSquad(): array {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id'   => 1,
            'name'      => 'Ajax JO13-1',
            'age_group' => 'JO13',
        ] );
        $this->team_id = (int) $wpdb->insert_id;

        $players = [];
        for ( $i = 0; $i < self::SQUAD_SIZE; $i++ ) {
            $wpdb->insert( "{$this->p}tt_players", [
                'club_id'    => 1,
                'team_id'    => $this->team_id,
                'first_name' => 'Speler',
                'last_name'  => 'Nummer ' . $i,
            ] );
            $id = (int) $wpdb->insert_id;
            $this->player_ids[] = $id;
            $players[] = (object) [ 'id' => $id, 'team_id' => $this->team_id ];
        }

        return $players;
    }

    /** @return int the generated tournament id */
    private function generateTournament(): int {
        global $wpdb;

        $players = $this->seedSquad();
        $team    = (object) [ 'id' => $this->team_id, 'name' => 'Ajax JO13-1', 'age_group' => 'JO13' ];

        $gen = new TournamentGenerator(
            new DemoBatchRegistry( 'hygiene-batch' ),
            $players,
            [ $team ],
            [ 'hjo' => 1, 'admin' => 1 ],
            8,
            'en_US'
        );
        $this->assertGreaterThan( 0, $gen->generate(), 'the generator wrote nothing' );

        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_tournaments WHERE team_id = %d ORDER BY id DESC LIMIT 1",
            $this->team_id
        ) );
        $this->assertGreaterThan( 0, $id, 'no tournament row' );
        return $id;
    }

    /** @return array<int,array<string,mixed>> assignment rows of one tournament */
    private function assignments( int $tournament_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.match_id, a.period_index, a.player_id, a.position_code,
                    m.substitution_windows
               FROM {$this->p}tt_tournament_assignments a
               JOIN {$this->p}tt_tournament_matches m ON m.id = a.match_id
              WHERE m.tournament_id = %d",
            $tournament_id
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    // ── the demo generator ─────────────────────────────────────────────

    /**
     * The fault the whole issue starts from. Period 0 is the opening
     * lineup and is what a start is counted from; generating 1 and 2 gave
     * every demo player zero starts.
     */
    public function test_periods_are_zero_based_and_inside_the_fixture(): void {
        $rows = $this->assignments( $this->generateTournament() );
        $this->assertNotEmpty( $rows );

        $seen = [];
        foreach ( $rows as $row ) {
            $windows = json_decode( (string) $row['substitution_windows'], true ) ?: [];
            $period  = (int) $row['period_index'];

            $this->assertLessThan(
                count( $windows ) + 1,
                $period,
                'an assignment landed on a period the fixture does not have'
            );
            $seen[ $period ] = true;
        }

        $this->assertArrayHasKey( 0, $seen, 'no opening lineup — every player has zero starts' );
    }

    public function test_a_generated_tournament_has_starts(): void {
        $tournament_id = $this->generateTournament();

        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/tournaments/' . $tournament_id . '/totals' );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );

        $body    = (array) $response->get_data();
        $players = (array) ( ( (array) ( $body['data'] ?? [] ) )['players'] ?? [] );
        $this->assertNotEmpty( $players, 'the totals came back with no squad' );

        $starts = 0;
        foreach ( $players as $row ) {
            $starts += (int) ( (array) $row )['starts'];
        }
        $this->assertGreaterThan( 0, $starts, 'nobody started a match on a freshly generated academy' );
    }

    public function test_every_position_code_is_one_the_planner_knows(): void {
        foreach ( $this->assignments( $this->generateTournament() ) as $row ) {
            $this->assertContains(
                (string) $row['position_code'],
                self::ALLOWED,
                'a demo assignment uses a position code no other surface can read'
            );
        }
    }

    public function test_eligible_positions_are_allowed_codes_too(): void {
        global $wpdb;
        $tournament_id = $this->generateTournament();

        $rows = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT eligible_positions FROM {$this->p}tt_tournament_squad WHERE tournament_id = %d",
            $tournament_id
        ) );
        $this->assertNotEmpty( $rows );

        foreach ( $rows as $json ) {
            $codes = json_decode( (string) $json, true );
            $this->assertIsArray( $codes );
            $this->assertNotEmpty( $codes );
            foreach ( $codes as $code ) {
                $this->assertContains( (string) $code, self::ALLOWED );
                $this->assertNotSame( 'BENCH', (string) $code, 'the bench is not a position a player covers' );
            }
        }
    }

    /**
     * Without a bench row a player's tournament history cannot tell "did
     * not play this period" from "was never in the squad".
     */
    public function test_every_squad_member_is_placed_in_every_period(): void {
        $rows = $this->assignments( $this->generateTournament() );

        $by_slot = [];
        foreach ( $rows as $row ) {
            $by_slot[ $row['match_id'] . ':' . $row['period_index'] ][] = (int) $row['player_id'];
        }
        $this->assertNotEmpty( $by_slot );

        $bench = 0;
        foreach ( $rows as $row ) {
            if ( (string) $row['position_code'] === 'BENCH' ) $bench++;
        }
        $this->assertGreaterThan( 0, $bench, 'no BENCH rows at all' );

        foreach ( $by_slot as $slot => $players ) {
            sort( $players );
            $expected = $this->player_ids;
            sort( $expected );
            $this->assertSame( $expected, $players, "squad members are missing from period {$slot}" );
        }
    }

    // ── the opponent level ─────────────────────────────────────────────

    /** @return array{0:int,1:int} tournament id, match id */
    private function makeFixture(): array {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_tournaments", [
            'club_id' => 1, 'team_id' => 552, 'name' => 'Autumn cup',
        ] );
        $tournament_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_tournament_matches", [
            'club_id'        => 1,
            'tournament_id'  => $tournament_id,
            'sequence'       => 1,
            'label'          => 'Quarter-final',
            'opponent_level' => 'stronger',
            'duration_min'   => 20,
            'substitution_windows' => '[10]',
        ] );

        return [ $tournament_id, (int) $wpdb->insert_id ];
    }

    /** @param array<string,mixed> $body */
    private function patch( int $tournament_id, int $match_id, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request(
            'PATCH',
            '/talenttrack/v1/tournaments/' . $tournament_id . '/matches/' . $match_id
        );
        foreach ( $body as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return rest_get_server()->dispatch( $req );
    }

    public function test_an_unknown_opponent_level_is_refused(): void {
        [ $tournament_id, $match_id ] = $this->makeFixture();

        $response = $this->patch( $tournament_id, $match_id, [ 'opponent_level' => 'banana' ] );

        $this->assertSame( 400, $response->get_status() );

        $body = (array) $response->get_data();
        $this->assertFalse( (bool) ( $body['success'] ?? true ) );

        $errors = (array) ( $body['errors'] ?? [] );
        $this->assertNotEmpty( $errors );
        $first = (array) $errors[0];
        $this->assertSame( 'opponent_level_invalid', $first['code'] ?? '' );
        $this->assertStringContainsString(
            'much_stronger',
            (string) ( $first['message'] ?? '' ),
            'the refusal should name what is allowed instead'
        );

        global $wpdb;
        $this->assertSame( 'stronger', (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT opponent_level FROM {$this->p}tt_tournament_matches WHERE id = %d",
            $match_id
        ) ), 'the row was written anyway' );
    }

    public function test_a_known_level_and_an_empty_one_are_both_accepted(): void {
        [ $tournament_id, $match_id ] = $this->makeFixture();

        $this->assertSame( 200, $this->patch( $tournament_id, $match_id, [ 'opponent_level' => 'much_stronger' ] )->get_status() );
        $this->assertSame( 200, $this->patch( $tournament_id, $match_id, [ 'opponent_level' => '' ] )->get_status() );

        global $wpdb;
        $this->assertNull( $wpdb->get_var( $wpdb->prepare(
            "SELECT opponent_level FROM {$this->p}tt_tournament_matches WHERE id = %d",
            $match_id
        ) ), 'an empty level should clear the column, not be refused' );
    }

    // ── the chip ───────────────────────────────────────────────────────

    /**
     * The seeded amber is 1.8:1 against white. A chip that painted the
     * lookup colour and assumed white text would be illegible at exactly
     * the level a coach most wants to notice.
     */
    public function test_the_chip_ink_clears_four_and_a_half_to_one_on_every_seeded_colour(): void {
        foreach ( [ '#16a34a', '#5b6e75', '#f59e0b', '#dc2626' ] as $hex ) {
            $ink   = LookupColourChip::ink( $hex );
            $ratio = LookupColourChip::contrast( $hex, $ink );

            $this->assertGreaterThanOrEqual(
                4.5,
                $ratio,
                sprintf( 'ink %s on %s is only %.2f:1', $ink, $hex, $ratio )
            );
        }
    }

    public function test_the_amber_specifically_does_not_get_white_ink(): void {
        $this->assertSame( LookupColourChip::INK_DARK, LookupColourChip::ink( '#f59e0b' ) );
        $this->assertSame( LookupColourChip::INK_LIGHT, LookupColourChip::ink( '#dc2626' ) );
    }

    public function test_a_colourless_level_contributes_no_inline_style(): void {
        $this->assertSame( '', LookupColourChip::styleFor( '' ) );
        $this->assertSame( '', LookupColourChip::styleFor( 'not-a-colour' ) );
        $this->assertStringContainsString( '--tt-chip-bg:#f59e0b', LookupColourChip::styleFor( 'F59E0B' ) );
    }

    // ── the seed key ───────────────────────────────────────────────────

    public function test_the_canonical_seed_is_keyed_by_the_lookup_type(): void {
        $this->assertNotEmpty( LookupCanonicalSeeds::canonicalFor( 'tournament_opponent_level' ) );
        $this->assertSame( [], LookupCanonicalSeeds::canonicalFor( 'opponent_level' ) );
    }
}
