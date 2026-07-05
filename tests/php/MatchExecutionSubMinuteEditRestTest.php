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
 * #2273 — PATCH /match-execution/{activity}/substitution/{uuid} corrects the
 * half + minute of an already-logged sub. Coaches routinely log a sub late, so
 * the recorded minute is wrong; because minutes_played is derived from the sub
 * log, fixing the minute must flow through to both players' totals. These tests
 * assert the derived minutes follow the edit, plus the write-endpoint guards
 * (range, finalized, unknown uuid).
 */
final class MatchExecutionSubMinuteEditRestTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8181;
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
     * Activity + prep (half length 35, first-half XI = players 1..5) + a
     * PENDING_REVIEW execution so the write endpoints accept edits and the
     * recompute side-effect fires.
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
        $exec_repo->update( $this->exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );
    }

    private function post( string $action, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/' . $action );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    private function patch( string $path, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PATCH', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/' . $path );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    private function logHalf1Sub( string $uuid, int $minute ): void {
        $res = $this->post( 'substitution', [
            'event_uuid' => $uuid,
            'half'       => 1,
            'minute'     => $minute,
            'player_off' => 5,   // on the pitch (starting XI)
            'player_on'  => 15,  // on the bench
        ] );
        $this->assertSame( 200, $res->get_status(), 'seed sub logged' );
    }

    public function test_patch_method_is_registered_on_the_substitution_route(): void {
        $routes = rest_get_server()->get_routes();
        $key    = '/talenttrack/v1/match-execution/(?P<activity_id>\d+)/substitution/(?P<event_uuid>[a-f0-9-]+)';
        $this->assertArrayHasKey( $key, $routes );
        $methods = [];
        foreach ( $routes[ $key ] as $endpoint ) {
            $methods = array_merge( $methods, array_keys( (array) $endpoint['methods'] ) );
        }
        $this->assertContains( 'PATCH', $methods, 'the substitution route exposes PATCH' );
    }

    public function test_editing_the_minute_moves_both_players_derived_minutes(): void {
        $repo = new MatchExecutionRepository();
        $uuid = wp_generate_uuid4();
        $this->logHalf1Sub( $uuid, 10 );

        $before = $repo->computeMinutes( $this->exec_id, [ 1, 2, 3, 4, 5 ], [], self::HALF_LENGTH, self::HALF_LENGTH );
        $this->assertSame( 10, $before[5],  'player off at 10 has played 10 minutes' );
        $this->assertSame( 25, $before[15], 'player on at 10 has played 35-10=25 minutes' );

        $res = $this->patch( 'substitution/' . $uuid, [ 'half' => 1, 'minute' => 20 ] );
        $this->assertSame( 200, $res->get_status() );

        $after = $repo->computeMinutes( $this->exec_id, [ 1, 2, 3, 4, 5 ], [], self::HALF_LENGTH, self::HALF_LENGTH );
        $this->assertSame( 20, $after[5],  'off minute moved 10 -> 20' );
        $this->assertSame( 15, $after[15], 'on player now 35-20=15 minutes' );
    }

    public function test_patch_rejects_out_of_range_minute(): void {
        $uuid = wp_generate_uuid4();
        $this->logHalf1Sub( $uuid, 10 );
        $res = $this->patch( 'substitution/' . $uuid, [ 'half' => 1, 'minute' => 200 ] );
        $this->assertSame( 400, $res->get_status() );
    }

    public function test_patch_unknown_uuid_returns_404(): void {
        $res = $this->patch( 'substitution/' . wp_generate_uuid4(), [ 'half' => 1, 'minute' => 10 ] );
        $this->assertSame( 404, $res->get_status() );
    }

    public function test_patch_rejected_once_finalized(): void {
        $uuid = wp_generate_uuid4();
        $this->logHalf1Sub( $uuid, 10 );
        ( new MatchExecutionRepository() )->update( $this->exec_id, [ 'state' => MatchExecutionState::FINALIZED ] );
        $res = $this->patch( 'substitution/' . $uuid, [ 'half' => 1, 'minute' => 20 ] );
        $this->assertSame( 409, $res->get_status() );
    }
}
