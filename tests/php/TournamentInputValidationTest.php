<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #4020 — the tournament write routes used to accept values the planner
 * cannot use and answer 200 anyway.
 *
 * Two of them, both silent. A position code that is not on the internal list
 * was dropped without a word, so a U7 squad sent as `["GK","DF","MF"]` stored
 * `["GK"]` and the auto-planner filled the keeper slot and benched thirteen
 * children at 0 expected minutes. And a formation was never checked against
 * the `tournament_formation` lookup, so `1-2-2-1` saved with a 200 and then
 * answered `422 no_formation` at auto-plan time — on a fixture that plainly
 * had a formation.
 */
final class TournamentInputValidationTest extends WP_UnitTestCase {

    private const TEAM_ID = 4020;

    private int $tournament_id = 0;
    private int $match_id      = 0;
    private int $player_id     = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'team_id' => self::TEAM_ID, 'first_name' => 'Sem', 'last_name' => 'de Vries',
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_tournaments', [
            'club_id' => 1, 'team_id' => self::TEAM_ID, 'name' => 'Validation cup', 'start_date' => '2026-10-03',
        ] );
        $this->tournament_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_tournament_matches', [
            'club_id'              => 1,
            'tournament_id'        => $this->tournament_id,
            'sequence'             => 1,
            'opponent_name'        => 'De Treffers',
            'duration_min'         => 20,
            'substitution_windows' => '[]',
        ] );
        $this->match_id = (int) $wpdb->insert_id;

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @param array<string,mixed> $body */
    private function dispatch( string $method, string $route, array $body = [] ): \WP_REST_Response {
        $req = new WP_REST_Request( $method, $route );
        foreach ( $body as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return rest_get_server()->dispatch( $req );
    }

    /** @return array<string,mixed> */
    private function payload( \WP_REST_Response $res ): array {
        $data = $res->get_data();
        return is_array( $data ) ? $data : [];
    }

    private function firstErrorCode( \WP_REST_Response $res ): string {
        $body = $this->payload( $res );
        return (string) ( $body['errors'][0]['code'] ?? '' );
    }

    private function firstErrorMessage( \WP_REST_Response $res ): string {
        $body = $this->payload( $res );
        return (string) ( $body['errors'][0]['message'] ?? '' );
    }

    private function squadPositions(): ?string {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT eligible_positions FROM {$wpdb->prefix}tt_tournament_squad
              WHERE tournament_id = %d AND player_id = %d",
            $this->tournament_id, $this->player_id
        ) );
        return $value === null ? null : (string) $value;
    }

    // ── positions ─────────────────────────────────────────────────────

    public function test_creating_a_tournament_with_an_unknown_position_is_refused(): void {
        $res = $this->dispatch( 'POST', '/talenttrack/v1/tournaments', [
            'name'       => 'Silent drop',
            'team_id'    => self::TEAM_ID,
            'start_date' => '2026-10-10',
            'squad'      => [
                [ 'player_id' => $this->player_id, 'eligible_positions' => [ 'DF' ] ],
            ],
        ] );

        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'invalid_positions', $this->firstErrorCode( $res ) );

        $message = $this->firstErrorMessage( $res );
        $this->assertStringContainsString( 'DF', $message, 'the rejected code is named' );
        $this->assertStringContainsString( 'GK', $message, 'the accepted codes are named' );

        global $wpdb;
        $this->assertSame(
            0,
            (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tt_tournaments WHERE name = %s",
                'Silent drop'
            ) ),
            'nothing is stored'
        );
    }

    public function test_replacing_the_squad_with_an_unknown_position_is_refused(): void {
        $res = $this->dispatch( 'PATCH', '/talenttrack/v1/tournaments/' . $this->tournament_id . '/squad', [
            'squad' => [
                [ 'player_id' => $this->player_id, 'eligible_positions' => [ 'GK', 'MF' ] ],
            ],
        ] );

        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'invalid_positions', $this->firstErrorCode( $res ) );
        $this->assertNull( $this->squadPositions(), 'a refused squad PUT writes no row' );
    }

    public function test_one_squad_members_unknown_position_is_refused(): void {
        $res = $this->dispatch(
            'PATCH',
            '/talenttrack/v1/tournaments/' . $this->tournament_id . '/squad/' . $this->player_id,
            [ 'eligible_positions' => [ 'FW' ] ]
        );

        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'invalid_positions', $this->firstErrorCode( $res ) );
        $this->assertNull( $this->squadPositions() );
    }

    public function test_the_specific_codes_and_the_legacy_types_are_still_stored(): void {
        $res = $this->dispatch( 'PATCH', '/talenttrack/v1/tournaments/' . $this->tournament_id . '/squad', [
            'squad' => [
                [ 'player_id' => $this->player_id, 'eligible_positions' => [ 'GK', 'DEF', 'MID', 'FWD', 'ST' ] ],
            ],
        ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame(
            [ 'GK', 'CB', 'CM', 'ST' ],
            json_decode( (string) $this->squadPositions(), true ),
            'the legacy types still coerce, and a duplicate does not double up'
        );
    }

    // ── formations ────────────────────────────────────────────────────

    public function test_creating_a_tournament_with_an_unknown_formation_is_refused(): void {
        $res = $this->dispatch( 'POST', '/talenttrack/v1/tournaments', [
            'name'              => 'Six a side',
            'team_id'           => self::TEAM_ID,
            'start_date'        => '2026-10-10',
            'default_formation' => '1-2-2-1',
        ] );

        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'unknown_formation', $this->firstErrorCode( $res ) );
        $this->assertStringContainsString(
            '1-1-3-1',
            $this->firstErrorMessage( $res ),
            'the message lists the formations this academy does have'
        );
    }

    public function test_patching_a_fixture_to_an_unknown_formation_is_refused(): void {
        $res = $this->dispatch(
            'PATCH',
            '/talenttrack/v1/tournaments/' . $this->tournament_id . '/matches/' . $this->match_id,
            [ 'formation' => '1-2-2-1' ]
        );

        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'unknown_formation', $this->firstErrorCode( $res ) );

        global $wpdb;
        $this->assertNull( $wpdb->get_var( $wpdb->prepare(
            "SELECT formation FROM {$wpdb->prefix}tt_tournament_matches WHERE id = %d",
            $this->match_id
        ) ), 'nothing is stored' );
    }

    public function test_a_known_formation_and_a_blank_one_are_both_accepted(): void {
        $known = $this->dispatch(
            'PATCH',
            '/talenttrack/v1/tournaments/' . $this->tournament_id . '/matches/' . $this->match_id,
            [ 'formation' => '1-1-3-1' ]
        );
        $this->assertSame( 200, $known->get_status() );

        $blank = $this->dispatch(
            'PATCH',
            '/talenttrack/v1/tournaments/' . $this->tournament_id . '/matches/' . $this->match_id,
            [ 'formation' => '' ]
        );
        $this->assertSame( 200, $blank->get_status(), 'blank means "fall back to the tournament\'s"' );
    }
}
