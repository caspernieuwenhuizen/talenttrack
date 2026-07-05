<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #2268 / #2271 — REST-boundary guards for the match-execution overhaul:
 *
 *   - #2268 the substitution endpoint rejects a roster-impossible swap
 *     (player-off not on the pitch, player-on already on) with a 400, and
 *     both sub + goal endpoints reject an out-of-range minute with a 400.
 *   - #2271 the new `reopen` route transitions a FINALIZED execution back
 *     to PENDING_REVIEW (200) and refuses from any other state (409).
 */
final class MatchExecutionValidationRestTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8080;
    private const HALF_LENGTH = 35;

    private int $exec_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->seedMatch();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    /**
     * Seed an activity + prep (half length 35, first-half XI = players
     * 1..5) + a second-half execution so the write endpoints accept edits.
     */
    private function seedMatch(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => self::ACTIVITY_ID,
            'team_id'           => 1,
            'title'             => 'Test match',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'match',
        ] );

        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( self::ACTIVITY_ID, self::HALF_LENGTH );
        $prep_repo->replaceLineupForHalf( $prep_id, 1, [ 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5 ] );

        $exec_repo     = new MatchExecutionRepository();
        $this->exec_id = $exec_repo->ensureForActivity( self::ACTIVITY_ID, $prep_id );
        $exec_repo->update( $this->exec_id, [ 'state' => MatchExecutionState::SECOND_HALF ] );
    }

    private function post( string $action, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/' . $action );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    // ----- #2268 substitution roster validation -----

    public function test_valid_substitution_is_accepted(): void {
        // 5 is on the pitch (starting XI), 15 is on the bench.
        $res = $this->post( 'substitution', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 2,
            'minute'     => 20,
            'player_off' => 5,
            'player_on'  => 15,
        ] );
        $this->assertSame( 200, $res->get_status(), 'a roster-valid, in-range sub is accepted' );
    }

    public function test_substitution_rejects_player_off_not_on_pitch(): void {
        // 99 is not in the starting XI and never subbed on.
        $res = $this->post( 'substitution', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 2,
            'minute'     => 20,
            'player_off' => 99,
            'player_on'  => 15,
        ] );
        $this->assertSame( 400, $res->get_status() );
        $data = $res->get_data();
        $this->assertSame( 'player_off_not_on_pitch', $data['errors'][0]['code'] ?? '' );
    }

    public function test_substitution_rejects_player_on_already_on(): void {
        // 3 is already in the starting XI (on the pitch).
        $res = $this->post( 'substitution', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 2,
            'minute'     => 20,
            'player_off' => 5,
            'player_on'  => 3,
        ] );
        $this->assertSame( 400, $res->get_status() );
    }

    public function test_substitution_replay_of_accepted_uuid_stays_idempotent(): void {
        // First accept a valid sub (5 off, 15 on).
        $uuid = wp_generate_uuid4();
        $first = $this->post( 'substitution', [
            'event_uuid' => $uuid,
            'half'       => 2,
            'minute'     => 20,
            'player_off' => 5,
            'player_on'  => 15,
        ] );
        $this->assertSame( 200, $first->get_status() );

        // Replaying the SAME event_uuid (offline-queue flush) must not be
        // rejected by the roster validator even though 5 is now off-pitch.
        $replay = $this->post( 'substitution', [
            'event_uuid' => $uuid,
            'half'       => 2,
            'minute'     => 20,
            'player_off' => 5,
            'player_on'  => 15,
        ] );
        $this->assertSame( 200, $replay->get_status(), 'a replay of an accepted sub is idempotent, not a 400' );
    }

    public function test_substitution_rejects_out_of_range_minute(): void {
        // Half length 35 + 10 stoppage = 45 is the max; 200 is refused.
        $res = $this->post( 'substitution', [
            'event_uuid' => wp_generate_uuid4(),
            'half'       => 2,
            'minute'     => 200,
            'player_off' => 5,
            'player_on'  => 15,
        ] );
        $this->assertSame( 400, $res->get_status() );
    }

    // ----- #2268 goal minute validation -----

    public function test_goal_event_rejects_out_of_range_minute(): void {
        $res = $this->post( 'goal-event', [
            'event_uuid' => wp_generate_uuid4(),
            'player_id'  => 3,
            'half'       => 2,
            'minute'     => 999,
        ] );
        $this->assertSame( 400, $res->get_status() );
    }

    public function test_goal_event_in_range_is_accepted(): void {
        $res = $this->post( 'goal-event', [
            'event_uuid' => wp_generate_uuid4(),
            'player_id'  => 3,
            'half'       => 2,
            'minute'     => 40,
        ] );
        $this->assertSame( 200, $res->get_status() );
    }

    // ----- #2271 reopen transition -----

    public function test_reopen_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(
            '/talenttrack/v1/match-execution/(?P<activity_id>\d+)/reopen',
            $routes
        );
    }

    public function test_reopen_from_finalized_returns_to_pending_review(): void {
        ( new MatchExecutionRepository() )->update( $this->exec_id, [ 'state' => MatchExecutionState::FINALIZED ] );

        $res = $this->post( 'reopen', [] );
        $this->assertSame( 200, $res->get_status() );

        global $wpdb;
        $state = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT state FROM {$wpdb->prefix}tt_match_execution WHERE id = %d",
            $this->exec_id
        ) );
        $this->assertSame( MatchExecutionState::PENDING_REVIEW, $state );
    }

    public function test_reopen_from_non_finalized_state_is_rejected(): void {
        // Execution is SECOND_HALF from seed — nothing to re-open.
        $res = $this->post( 'reopen', [] );
        $this->assertSame( 409, $res->get_status() );
    }

    public function test_substitution_delete_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(
            '/talenttrack/v1/match-execution/(?P<activity_id>\d+)/substitution/(?P<event_uuid>[a-f0-9-]+)',
            $routes
        );
    }
}
