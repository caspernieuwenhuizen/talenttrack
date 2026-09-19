<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #3674 — the threads routes say which thread types exist.
 *
 * A thread belongs to a goal, a player or a blueprint. Callers who guessed
 * another type (trial_case, activity, team) got WordPress's bare
 * "Invalid parameter(s): type" with nothing naming the valid ones, and the
 * route index showed `type` with no schema. These tests pin the contract:
 * the route describes its arguments, an unknown type names the valid ones,
 * a known type behaves as before, and a message needs a body.
 */
final class ThreadsRestTypeContractTest extends WP_UnitTestCase {

    private int $admin;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
        do_action( 'rest_api_init' );
    }

    public function test_the_route_describes_the_type_argument(): void {
        $response = $this->dispatch( 'OPTIONS', '/talenttrack/v1/threads/player/1' );
        $data     = (array) $response->get_data();

        $this->assertArrayHasKey( 'endpoints', $data );
        $args = (array) ( $data['endpoints'][0]['args'] ?? [] );
        $this->assertArrayHasKey( 'type', $args );

        $type = (array) $args['type'];
        $this->assertSame( 'string', $type['type'] ?? null );
        foreach ( [ 'goal', 'player', 'blueprint' ] as $known ) {
            $this->assertContains( $known, (array) ( $type['enum'] ?? [] ) );
        }
        $this->assertNotEmpty( $type['description'] ?? '' );
        $this->assertNotEmpty( ( (array) $args['id'] )['description'] ?? '' );
    }

    public function test_the_message_route_describes_body_and_visibility(): void {
        $response = $this->dispatch( 'OPTIONS', '/talenttrack/v1/threads/player/1/messages' );
        $data     = (array) $response->get_data();
        $args     = (array) ( $data['endpoints'][0]['args'] ?? [] );

        $this->assertTrue( (bool) ( ( (array) ( $args['body'] ?? [] ) )['required'] ?? false ) );
        $this->assertSame(
            [ 'public', 'private_to_coach' ],
            array_values( (array) ( ( (array) ( $args['visibility'] ?? [] ) )['enum'] ?? [] ) )
        );
    }

    public function test_an_unknown_type_names_the_valid_types(): void {
        $response = $this->dispatch( 'GET', '/talenttrack/v1/threads/team/52' );

        $this->assertSame( 400, $response->get_status() );
        $data = (array) $response->get_data();
        $this->assertSame( 'rest_invalid_param', $data['code'] ?? null );

        $message = (string) ( $data['data']['params']['type'] ?? '' );
        foreach ( [ 'goal', 'player', 'blueprint' ] as $known ) {
            $this->assertStringContainsString( $known, $message );
        }
        $this->assertSame( 'unknown_thread_type', $data['data']['details']['type']['code'] ?? null );
    }

    public function test_a_player_thread_still_lists_its_messages(): void {
        $player_id = $this->seedPlayer();

        $response = $this->dispatch( 'GET', '/talenttrack/v1/threads/player/' . $player_id );

        $this->assertSame( 200, $response->get_status() );
        $data = (array) $response->get_data();
        $this->assertArrayHasKey( 'messages', $data );
        $this->assertIsArray( $data['messages'] );
    }

    public function test_posting_without_a_body_is_refused(): void {
        $player_id = $this->seedPlayer();

        $response = $this->dispatch( 'POST', '/talenttrack/v1/threads/player/' . $player_id . '/messages' );

        $this->assertSame( 400, $response->get_status() );
        $data = (array) $response->get_data();
        $this->assertSame( 'rest_missing_callback_param', $data['code'] ?? null );
    }

    public function test_an_unknown_visibility_is_refused(): void {
        $player_id = $this->seedPlayer();

        $response = $this->dispatch(
            'POST',
            '/talenttrack/v1/threads/player/' . $player_id . '/messages',
            [ 'body' => 'Good week.', 'visibility' => 'everyone' ]
        );

        $this->assertSame( 400, $response->get_status() );
    }

    public function test_posting_with_a_body_still_posts(): void {
        $player_id = $this->seedPlayer();

        $response = $this->dispatch(
            'POST',
            '/talenttrack/v1/threads/player/' . $player_id . '/messages',
            [ 'body' => 'Strong first touch this week.' ]
        );

        $this->assertSame( 201, $response->get_status() );
        $data = (array) $response->get_data();
        $this->assertSame( 'player', $data['thread_type'] ?? null );
        $this->assertSame( 'public', $data['visibility'] ?? null );
    }

    private function seedPlayer(): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'first_name' => 'Thread',
            'last_name'  => 'Player',
            'club_id'    => 1,
            'status'     => 'active',
        ] );
        $this->assertNotFalse( $ok, 'player insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function dispatch( string $method, string $route, array $params = [] ): WP_REST_Response {
        $request = new WP_REST_Request( $method, $route );
        foreach ( $params as $k => $v ) {
            $request->set_param( $k, $v );
        }
        return rest_get_server()->dispatch( $request );
    }
}
