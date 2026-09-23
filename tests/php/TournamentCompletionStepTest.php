<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #4032 (with #4006 folded in) — completing a tournament fixture is a confirm
 * step, not a one-tap commit.
 *
 * On the reported U7 fixture the grid held one goalkeeper per period and
 * everybody else on the bench. Completing it returned 200, wrote fifteen
 * attendance rows and put two keepers on ten minutes and thirteen children on
 * nil into the record — when in fact they all played about twelve. Nothing
 * warned anybody, and no minutes were ever written at all, so every minutes
 * surface read the whole squad as nil.
 *
 * So: a lineup that does not fill the formation is refused with 409 and nothing
 * is written; the pre-commit read says which periods are short and what minutes
 * each player would get, pre-filled from the rotation plan; and the confirmed
 * figures are what lands on the register.
 */
final class TournamentCompletionStepTest extends WP_UnitTestCase {

    private const TEAM_ID = 4032;

    /** `1-2-3-1`: seven on the pitch. */
    private const SLOT_LABELS = [ [ 'GK' ], [ 'LB', 'RB' ], [ 'LM', 'CM', 'RM' ], [ 'ST' ] ];

    private int $tournament_id = 0;
    private int $match_id      = 0;
    /** @var list<int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        // The formation, with its slot labels, so the test does not depend on
        // what the install happens to have seeded.
        $wpdb->insert( $wpdb->prefix . 'tt_lookups', [
            'club_id'     => 1,
            'lookup_type' => 'tournament_formation',
            'name'        => '1-2-3-1',
            'sort_order'  => 1,
            'meta'        => (string) wp_json_encode( [ 'slot_labels' => self::SLOT_LABELS ] ),
        ] );

        for ( $i = 1; $i <= 8; $i++ ) {
            $wpdb->insert( $wpdb->prefix . 'tt_players', [
                'club_id'    => 1,
                'team_id'    => self::TEAM_ID,
                'first_name' => 'Speler',
                'last_name'  => 'Nummer ' . $i,
            ] );
            $this->players[] = (int) $wpdb->insert_id;
        }

        $wpdb->insert( $wpdb->prefix . 'tt_tournaments', [
            'club_id'           => 1,
            'team_id'           => self::TEAM_ID,
            'name'              => 'Confirm cup',
            'start_date'        => '2026-11-14',
            'default_formation' => '1-2-3-1',
        ] );
        $this->tournament_id = (int) $wpdb->insert_id;

        foreach ( $this->players as $player_id ) {
            $wpdb->insert( $wpdb->prefix . 'tt_tournament_squad', [
                'club_id'            => 1,
                'tournament_id'      => $this->tournament_id,
                'player_id'          => $player_id,
                'eligible_positions' => '["GK","CB","CM","ST"]',
            ] );
        }

        // 20 minutes, one substitution window: two periods of ten.
        $wpdb->insert( $wpdb->prefix . 'tt_tournament_matches', [
            'club_id'              => 1,
            'tournament_id'        => $this->tournament_id,
            'sequence'             => 1,
            'opponent_name'        => 'De Treffers',
            'duration_min'         => 20,
            'substitution_windows' => '[10]',
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

    private function route( string $suffix ): string {
        return '/talenttrack/v1/tournaments/' . $this->tournament_id
            . '/matches/' . $this->match_id . $suffix;
    }

    /** @return array<string,mixed> */
    private function payload( \WP_REST_Response $res ): array {
        $data = $res->get_data();
        return (array) ( $data['data'] ?? $data );
    }

    private function assign( int $period, int $player_id, string $position ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_tournament_assignments', [
            'club_id'       => 1,
            'match_id'      => $this->match_id,
            'period_index'  => $period,
            'player_id'     => $player_id,
            'position_code' => $position,
        ] );
    }

    /** One keeper per period, everybody else benched — the reported grid. */
    private function keeperOnlyGrid(): void {
        $this->assign( 0, $this->players[0], 'GK' );
        $this->assign( 1, $this->players[1], 'GK' );
        foreach ( $this->players as $index => $player_id ) {
            if ( $index === 0 ) continue;
            $this->assign( 0, $player_id, 'BENCH' );
        }
        foreach ( $this->players as $index => $player_id ) {
            if ( $index === 1 ) continue;
            $this->assign( 1, $player_id, 'BENCH' );
        }
    }

    /** The first seven players in the seven slots, both periods. */
    private function fullGrid(): void {
        $flat = [];
        foreach ( self::SLOT_LABELS as $line ) {
            foreach ( $line as $code ) $flat[] = $code;
        }
        foreach ( [ 0, 1 ] as $period ) {
            foreach ( $flat as $slot => $code ) {
                $this->assign( $period, $this->players[ $slot ], $code );
            }
        }
        $this->assign( 0, $this->players[7], 'BENCH' );
        $this->assign( 1, $this->players[7], 'BENCH' );
    }

    private function attendanceCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_attendance a
               JOIN {$wpdb->prefix}tt_tournament_matches m ON m.activity_id = a.activity_id
              WHERE m.id = %d",
            $this->match_id
        ) );
    }

    /** @return array<int,int> player id => recorded minutes */
    private function recordedMinutes(): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT a.player_id, a.minutes_played
               FROM {$wpdb->prefix}tt_attendance a
               JOIN {$wpdb->prefix}tt_tournament_matches m ON m.activity_id = a.activity_id
              WHERE m.id = %d AND a.record_type = %s",
            $this->match_id, 'actual'
        ) );
        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row->player_id ] = $row->minutes_played === null ? -1 : (int) $row->minutes_played;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function matchRow(): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT completed_at, activity_id FROM {$wpdb->prefix}tt_tournament_matches WHERE id = %d",
            $this->match_id
        ), ARRAY_A );
    }

    // ── the refusal ───────────────────────────────────────────────────

    public function test_an_under_filled_lineup_is_refused_and_nothing_is_written(): void {
        $this->keeperOnlyGrid();

        $res = $this->dispatch( 'POST', $this->route( '/complete' ) );

        $this->assertSame( 409, $res->get_status() );
        $body = $this->payload( $res );
        $this->assertSame( 'lineup_incomplete', (string) ( $body['errors'][0]['code'] ?? '' ) );

        $this->assertSame( 0, $this->attendanceCount(), 'no register is written' );
        $row = $this->matchRow();
        $this->assertNull( $row['completed_at'], 'the fixture is not marked completed' );
        $this->assertNull( $row['activity_id'], 'and it is not promoted to an activity either' );
    }

    public function test_the_refusal_names_every_short_period(): void {
        $this->keeperOnlyGrid();

        $res  = $this->dispatch( 'POST', $this->route( '/complete' ) );
        $body = $this->payload( $res );

        $details = (array) ( $body['errors'][0]['details'] ?? [] );
        $short   = (array) ( $details['short_periods'] ?? [] );
        $this->assertCount( 2, $short, 'both periods field one of seven' );
        $this->assertSame( 1, (int) $short[0]['filled'] );
        $this->assertSame( 7, (int) $short[0]['slots'] );

        $message = (string) ( $body['errors'][0]['message'] ?? '' );
        $this->assertStringContainsString( '1 of 7', $message );
    }

    public function test_force_completes_an_incomplete_grid(): void {
        $this->keeperOnlyGrid();

        $res = $this->dispatch( 'POST', $this->route( '/complete' ), [ 'force' => 1 ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 8, $this->attendanceCount(), 'the whole squad is registered' );
        $this->assertNotNull( $this->matchRow()['completed_at'] );
    }

    public function test_a_full_grid_completes_and_records_the_planned_minutes(): void {
        $this->fullGrid();

        $res = $this->dispatch( 'POST', $this->route( '/complete' ) );

        $this->assertSame( 200, $res->get_status() );
        $minutes = $this->recordedMinutes();
        $this->assertSame( 20, $minutes[ $this->players[0] ], 'both periods of a 20-minute fixture' );
        $this->assertSame( 0, $minutes[ $this->players[7] ], 'a squad member who sat out records nil, not nothing' );
    }

    /** A fixture with no formation has nothing to be short of. */
    public function test_a_fixture_with_no_formation_is_not_refused(): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_tournaments',
            [ 'default_formation' => null ],
            [ 'id' => $this->tournament_id ]
        );
        $this->keeperOnlyGrid();

        $res = $this->dispatch( 'POST', $this->route( '/complete' ) );

        $this->assertSame( 200, $res->get_status() );
    }

    // ── the confirm step's read ───────────────────────────────────────

    public function test_the_completion_read_prefills_the_planned_minutes(): void {
        $this->fullGrid();

        $res = $this->dispatch( 'GET', $this->route( '/completion' ) );
        $this->assertSame( 200, $res->get_status() );

        $body = $this->payload( $res );
        $this->assertTrue( $body['lineup_complete'] );
        $this->assertSame( [], $body['short_periods'] );
        $this->assertSame( 7, $body['formation']['slots'] );
        $this->assertSame( 2, $body['periods'] );
        $this->assertSame( 10, $body['minutes_per_period'] );
        $this->assertCount( 8, $body['players'] );

        $by_player = [];
        foreach ( $body['players'] as $player ) {
            $by_player[ (int) $player['player_id'] ] = $player;
        }
        $this->assertSame( 20, (int) $by_player[ $this->players[0] ]['minutes'] );
        $this->assertSame( 'start', (string) $by_player[ $this->players[0] ]['role'] );
        $this->assertSame( 0, (int) $by_player[ $this->players[7] ]['minutes'] );
        $this->assertSame( 'bench', (string) $by_player[ $this->players[7] ]['role'] );
    }

    public function test_the_completion_read_names_the_short_periods_and_writes_nothing(): void {
        $this->keeperOnlyGrid();

        $body = $this->payload( $this->dispatch( 'GET', $this->route( '/completion' ) ) );

        $this->assertFalse( $body['lineup_complete'] );
        $this->assertCount( 2, $body['short_periods'] );
        $this->assertSame( 0, $this->attendanceCount() );
        $this->assertNull( $this->matchRow()['activity_id'] );
    }

    // ── the confirmed figures ─────────────────────────────────────────

    public function test_confirmed_minutes_override_the_plan(): void {
        $this->fullGrid();

        $res = $this->dispatch( 'POST', $this->route( '/complete' ), [
            'minutes' => [
                [ 'player_id' => $this->players[0], 'minutes' => 12 ],
                [ 'player_id' => $this->players[7], 'minutes' => 8 ],
            ],
        ] );

        $this->assertSame( 200, $res->get_status() );
        $minutes = $this->recordedMinutes();
        $this->assertSame( 12, $minutes[ $this->players[0] ], 'what the coach confirmed' );
        $this->assertSame( 8, $minutes[ $this->players[7] ], 'a player the plan benched but who came on' );
        $this->assertSame( 20, $minutes[ $this->players[1] ], 'a player the payload leaves out keeps the plan' );
    }

    public function test_a_map_payload_is_accepted_too(): void {
        $this->fullGrid();

        $res = $this->dispatch( 'POST', $this->route( '/complete' ), [
            'minutes' => [ (string) $this->players[0] => 14 ],
        ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 14, $this->recordedMinutes()[ $this->players[0] ] );
    }

    public function test_minutes_cannot_exceed_the_fixtures_own_length(): void {
        $this->fullGrid();

        $this->dispatch( 'POST', $this->route( '/complete' ), [
            'minutes' => [ [ 'player_id' => $this->players[0], 'minutes' => 999 ] ],
        ] );

        $this->assertSame(
            20,
            $this->recordedMinutes()[ $this->players[0] ],
            'nobody plays more minutes than the fixture lasted'
        );
    }

    public function test_a_figure_for_somebody_outside_the_squad_is_ignored(): void {
        $this->fullGrid();

        $res = $this->dispatch( 'POST', $this->route( '/complete' ), [
            'minutes' => [ [ 'player_id' => 999999, 'minutes' => 15 ] ],
        ] );

        $this->assertSame( 200, $res->get_status() );
        $this->assertCount( 8, $this->recordedMinutes(), 'the squad, and nobody else' );
    }
}
