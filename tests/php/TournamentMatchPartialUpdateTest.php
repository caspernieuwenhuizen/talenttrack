<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #3557 — the fixture update is a partial update.
 *
 * The score boxes added in #3532 PATCH a single field when they lose focus.
 * The handler behind them rebuilt the whole row from the request, so a coach
 * typing "3" into a scoreline lost the fixture's opponent, level, kickoff
 * time and substitution windows, and had its duration reset to the create
 * default of 20 minutes. That is the same contract
 * `AutosaveWriteContractTest` pins for evaluations and PDP conversations: a
 * write that does not mention a field must not change it.
 *
 * The duration and the windows are the one pair that cannot be judged in
 * isolation — the windows are minute marks inside the duration — so they get
 * their own cases here.
 */
final class TournamentMatchPartialUpdateTest extends WP_UnitTestCase {

    private const TEAM_ID = 552;

    private int $tournament_id = 0;
    private int $match_id      = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $wpdb->insert( $wpdb->prefix . 'tt_tournaments', [
            'club_id' => 1, 'team_id' => self::TEAM_ID, 'name' => 'Autumn cup',
        ] );
        $this->tournament_id = (int) $wpdb->insert_id;

        // A fully-filled fixture: every column a score-only PATCH used to
        // blank carries a value worth losing.
        $wpdb->insert( $wpdb->prefix . 'tt_tournament_matches', [
            'club_id'              => 1,
            'tournament_id'        => $this->tournament_id,
            'sequence'             => 1,
            'label'                => 'Quarter-final',
            'opponent_name'        => 'Ajax O13-1',
            'opponent_level'       => 'stronger',
            'formation'            => '1-3-2-1',
            'duration_min'         => 40,
            'substitution_windows' => '[10,20,30]',
            'scheduled_at'         => '2026-09-26 11:30:00',
            'notes'                => 'Play the back three high.',
        ] );
        $this->match_id = (int) $wpdb->insert_id;

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    /** @param array<string,mixed> $body */
    private function patch( array $body ): \WP_REST_Response {
        $req = new WP_REST_Request(
            'PATCH',
            '/talenttrack/v1/tournaments/' . $this->tournament_id . '/matches/' . $this->match_id
        );
        foreach ( $body as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return rest_get_server()->dispatch( $req );
    }

    /** @return array<string,mixed> */
    private function row(): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_tournament_matches WHERE id = %d",
            $this->match_id
        ), ARRAY_A );
    }

    /**
     * The bug, stated as a test: record a score, read every other column back.
     */
    public function test_a_score_only_patch_leaves_the_rest_of_the_fixture_alone(): void {
        $res = $this->patch( [ 'our_score' => 3 ] );
        $this->assertSame( 200, $res->get_status() );

        $row = $this->row();

        $this->assertSame( '3', (string) $row['our_score'] );
        $this->assertSame( 'Quarter-final', $row['label'] );
        $this->assertSame( 'Ajax O13-1', $row['opponent_name'] );
        $this->assertSame( 'stronger', $row['opponent_level'] );
        $this->assertSame( '1-3-2-1', $row['formation'] );
        $this->assertSame( '40', (string) $row['duration_min'] );
        $this->assertSame( [ 10, 20, 30 ], json_decode( (string) $row['substitution_windows'], true ) );
        $this->assertSame( '2026-09-26 11:30:00', $row['scheduled_at'] );
        $this->assertSame( 'Play the back three high.', $row['notes'] );
        $this->assertSame( '1', (string) $row['sequence'] );
    }

    /**
     * The windows are minute marks inside the duration. Shortening the
     * fixture drops the ones that no longer fit and keeps the ones that do —
     * it does not throw the lot away because the request was silent on them.
     */
    public function test_a_duration_only_patch_renormalises_the_existing_windows(): void {
        $this->patch( [ 'duration_min' => 25 ] );

        $row = $this->row();
        $this->assertSame( '25', (string) $row['duration_min'] );
        $this->assertSame( [ 10, 20 ], json_decode( (string) $row['substitution_windows'], true ) );
        $this->assertSame( 'Ajax O13-1', $row['opponent_name'] );
    }

    /**
     * The mirror image: new windows arrive alone and are measured against the
     * duration already on the row, not against the create default of 20.
     */
    public function test_a_windows_only_patch_normalises_against_the_stored_duration(): void {
        $this->patch( [ 'substitution_windows' => [ 15, 25, 45 ] ] );

        $row = $this->row();
        $this->assertSame( '40', (string) $row['duration_min'] );
        $this->assertSame(
            [ 15, 25 ],
            json_decode( (string) $row['substitution_windows'], true ),
            '45 falls outside a 40-minute fixture and drops'
        );
    }

    /**
     * An explicit empty value still clears a column. "Do not mention it" and
     * "set it to nothing" have to stay different things, or there is no way
     * to correct a mistyped opponent.
     */
    public function test_an_explicit_empty_value_still_clears_the_column(): void {
        $this->patch( [ 'opponent_name' => '' ] );

        $row = $this->row();
        $this->assertSame( '', (string) $row['opponent_name'] );
        $this->assertSame( 'stronger', $row['opponent_level'], 'the neighbouring column is untouched' );
    }

    /**
     * A full payload — what the fixture form sends — still replaces
     * everything it carries.
     */
    public function test_a_full_patch_replaces_every_field_it_carries(): void {
        $this->patch( [
            'label'                => 'Semi-final',
            'opponent_name'        => 'PSV O13-2',
            'opponent_level'       => 'similar',
            'formation'            => '1-2-3-1',
            'duration_min'         => 30,
            'substitution_windows' => [ 15 ],
            'scheduled_at'         => '2026-09-26 14:00:00',
            'notes'                => 'Rotate the keeper.',
        ] );

        $row = $this->row();
        $this->assertSame( 'Semi-final', $row['label'] );
        $this->assertSame( 'PSV O13-2', $row['opponent_name'] );
        $this->assertSame( 'similar', $row['opponent_level'] );
        $this->assertSame( '1-2-3-1', $row['formation'] );
        $this->assertSame( '30', (string) $row['duration_min'] );
        $this->assertSame( [ 15 ], json_decode( (string) $row['substitution_windows'], true ) );
        $this->assertSame( '2026-09-26 14:00:00', $row['scheduled_at'] );
        $this->assertSame( 'Rotate the keeper.', $row['notes'] );
    }

    /**
     * A request that names no mutable column is a no-op that hands the row
     * back, not an UPDATE with no columns — wpdb reports that as a failure
     * and the score box would paint a save error over a fixture nothing went
     * wrong with.
     */
    public function test_a_patch_with_nothing_to_write_returns_the_row_unchanged(): void {
        $res = $this->patch( [] );

        $this->assertSame( 200, $res->get_status() );

        $data = $res->get_data();
        $body = $data['data'] ?? $data;
        $this->assertSame( 'Ajax O13-1', $body['opponent_name'] );
        $this->assertSame( 40, $body['duration_min'] );

        $row = $this->row();
        $this->assertSame( 'Quarter-final', $row['label'] );
        $this->assertSame( '40', (string) $row['duration_min'] );
    }

    /**
     * Creating a fixture is the other half of the contract: there is no row
     * to preserve, so an absent key still means "use the default".
     */
    public function test_creating_a_fixture_still_applies_the_defaults(): void {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/tournaments/' . $this->tournament_id . '/matches' );
        $req->set_param( 'opponent_name', 'Feyenoord O13-1' );
        $res = rest_get_server()->dispatch( $req );

        $this->assertSame( 200, $res->get_status() );

        $data = $res->get_data();
        $body = $data['data'] ?? $data;
        $this->assertSame( 'Feyenoord O13-1', $body['opponent_name'] );
        $this->assertSame( 20, $body['duration_min'] );
        $this->assertSame( [], $body['substitution_windows'] );
        $this->assertSame( 2, $body['sequence'] );
    }
}
