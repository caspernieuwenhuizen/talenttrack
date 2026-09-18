<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #3532 — per-fixture results on a tournament day.
 *
 * Two rules carry this and both are places the obvious implementation is
 * wrong: an empty box records **no result** rather than 0–0, and a caller that
 * predates the columns must not wipe them by not knowing they exist — the
 * fixture update is otherwise a full replace.
 */
final class TournamentFixtureScoresTest extends WP_UnitTestCase {

    private const TEAM_ID = 551;

    private int $tournament_id = 0;
    private int $match_id      = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $wpdb->insert( $wpdb->prefix . 'tt_tournaments', [
            'club_id' => 1, 'team_id' => self::TEAM_ID, 'name' => 'Spring cup',
        ] );
        $this->tournament_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_tournament_matches', [
            'club_id'       => 1,
            'tournament_id' => $this->tournament_id,
            'sequence'      => 1,
            'opponent_name' => 'Ajax',
            'duration_min'  => 20,
            'substitution_windows' => '[]',
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
            "SELECT our_score, their_score FROM {$wpdb->prefix}tt_tournament_matches WHERE id = %d",
            $this->match_id
        ), ARRAY_A );
    }

    public function test_a_fixture_starts_with_no_result(): void {
        $row = $this->row();

        $this->assertNull( $row['our_score'] );
        $this->assertNull( $row['their_score'] );
    }

    public function test_a_result_is_recorded_on_the_fixture(): void {
        $res = $this->patch( [ 'our_score' => 2, 'their_score' => 1 ] );

        $this->assertSame( 200, $res->get_status() );

        $row = $this->row();
        $this->assertSame( '2', (string) $row['our_score'] );
        $this->assertSame( '1', (string) $row['their_score'] );
    }

    public function test_clearing_a_box_records_no_result_rather_than_zero(): void {
        $this->patch( [ 'our_score' => 3, 'their_score' => 0 ] );
        $this->patch( [ 'our_score' => '' ] );

        $row = $this->row();
        $this->assertNull( $row['our_score'], 'an emptied box is "no result recorded", not 0' );
        $this->assertSame( '0', (string) $row['their_score'], 'a real nil stays a nil' );
    }

    /**
     * The fixture update replaces every other field it is given. The scores
     * must not join that: a caller written before these columns existed would
     * otherwise wipe a recorded result simply by saving the duration.
     */
    public function test_an_update_that_does_not_mention_the_score_leaves_it_alone(): void {
        $this->patch( [ 'our_score' => 4, 'their_score' => 2 ] );

        $this->patch( [ 'duration_min' => 25 ] );

        $row = $this->row();
        $this->assertSame( '4', (string) $row['our_score'] );
        $this->assertSame( '2', (string) $row['their_score'] );
    }

    public function test_the_scores_travel_in_the_rest_payload(): void {
        $this->patch( [ 'our_score' => 5, 'their_score' => 1 ] );

        $res  = $this->patch( [ 'our_score' => 5 ] );
        $data = $res->get_data();
        $body = $data['data'] ?? $data;

        $this->assertSame( 5, $body['our_score'] );
        $this->assertSame( 1, $body['their_score'] );
    }
}
