<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\REST\BaseController;

/**
 * #3689 — one body contract for write routes, and one envelope for it.
 *
 * `BaseController::checkBody()` refuses a key a route does not declare and a
 * required key sent empty. Core's own refusals (an absent required key, a
 * value of the wrong type) now answer in the same envelope on our namespace,
 * so a client handles one shape for one kind of mistake.
 */
final class RestBodyContractTest extends WP_UnitTestCase {

    private const ROUTE = '/talenttrack/v1/test-3689-contract';
    private const OTHER = '/tt-test-3689/v1/contract';

    private static int $calls = 0;

    public function set_up(): void {
        parent::set_up();
        self::$calls = 0;
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public static function routes(): void {
        $endpoint = [
            'methods'             => 'POST',
            'callback'            => static function () {
                self::$calls++;
                return new WP_REST_Response( [ 'success' => true ], 200 );
            },
            'permission_callback' => '__return_true',
            'args'                => [
                'name'  => [ 'type' => 'string', 'required' => true ],
                'count' => [ 'type' => 'integer' ],
            ],
        ];
        register_rest_route( 'talenttrack/v1', '/test-3689-contract', $endpoint );
        register_rest_route( 'tt-test-3689/v1', '/contract', $endpoint );
    }

    // checkBody()

    public function test_an_unknown_key_is_refused_with_what_the_route_takes(): void {
        $response = BaseController::checkBody( $this->request( [ 'title' => 'x', 'bogus' => 1 ] ), $this->args() );

        $error = $this->firstError( $response );
        $this->assertSame( 400, $response instanceof WP_REST_Response ? $response->get_status() : 0 );
        $this->assertSame( 'unknown_field', $error['code'] );
        $this->assertSame( [ 'bogus' ], $error['details']['fields'] );
        $this->assertSame( [ 'title', 'date', 'notes' ], $error['details']['allowed'] );
    }

    public function test_every_required_key_sent_empty_is_named_in_one_answer(): void {
        $response = BaseController::checkBody( $this->request( [ 'title' => '', 'date' => '' ] ), $this->args() );

        $error = $this->firstError( $response );
        $this->assertSame( 400, $response instanceof WP_REST_Response ? $response->get_status() : 0 );
        $this->assertSame( 'missing_fields', $error['code'] );
        $this->assertSame( [ 'title', 'date' ], $error['details']['fields'] );
    }

    public function test_the_unknown_check_runs_first(): void {
        $error = $this->firstError( BaseController::checkBody( $this->request( [ 'title' => '', 'bogus' => 1 ] ), $this->args() ) );
        $this->assertSame( 'unknown_field', $error['code'] );
    }

    public function test_a_valid_body_passes(): void {
        $this->assertNull( BaseController::checkBody( $this->request( [ 'title' => 'Training', 'date' => '2026-09-19' ] ), $this->args() ) );
    }

    public function test_url_segments_and_query_params_are_never_reported(): void {
        $request = $this->request( [ 'title' => 'Training', 'date' => '2026-09-19' ] );
        $request->set_url_params( [ 'id' => 12 ] );
        $request->set_query_params( [ 'context' => 'edit', 'id' => 12 ] );

        $this->assertNull( BaseController::checkBody( $request, $this->args() ) );
    }

    public function test_a_required_url_segment_is_not_reported_missing_from_the_body(): void {
        $request = $this->request( [ 'notes' => 'x' ] );
        $request->set_url_params( [ 'id' => 12 ] );

        $this->assertNull( BaseController::checkBody( $request, [
            'id'    => [ 'type' => 'integer', 'required' => true ],
            'notes' => [ 'type' => 'string' ],
        ] ) );
    }

    public function test_a_form_encoded_body_is_read_too(): void {
        $request = new WP_REST_Request( 'POST', self::ROUTE );
        $request->set_body_params( [ 'title' => 'x', 'date' => 'y', 'bogus' => '1' ] );

        $error = $this->firstError( BaseController::checkBody( $request, $this->args() ) );
        $this->assertSame( [ 'bogus' ], $error['details']['fields'] );
    }

    // Core's param errors, in the house envelope.

    public function test_an_absent_required_arg_answers_missing_fields(): void {
        [ $data, $status ] = $this->dispatch( self::ROUTE, [ 'count' => 3 ] );

        $this->assertSame( 400, $status );
        $this->assertFalse( $data['success'] );
        $this->assertSame( 'missing_fields', $data['errors'][0]['code'] ?? null );
        $this->assertSame( [ 'name' ], $data['errors'][0]['details']['fields'] ?? null );
        $this->assertSame( 0, self::$calls, 'the callback never ran' );
    }

    public function test_a_wrong_type_answers_invalid_field(): void {
        [ $data, $status ] = $this->dispatch( self::ROUTE, [ 'name' => 'Ajax', 'count' => 'many' ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'invalid_field', $data['errors'][0]['code'] ?? null );
        $this->assertSame( [ 'count' ], $data['errors'][0]['details']['fields'] ?? null );
        $this->assertArrayHasKey( 'count', (array) ( $data['errors'][0]['details']['reasons'] ?? [] ) );
        $this->assertSame( 0, self::$calls, 'the callback never ran' );
    }

    public function test_a_valid_request_reaches_the_callback(): void {
        [ , $status ] = $this->dispatch( self::ROUTE, [ 'name' => 'Ajax', 'count' => 3 ] );
        $this->assertSame( 200, $status );
        $this->assertSame( 1, self::$calls );
    }

    public function test_a_route_outside_our_namespace_is_untouched(): void {
        [ $data, $status ] = $this->dispatch( self::OTHER, [ 'count' => 3 ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'rest_missing_callback_param', $data['code'] ?? null );
    }

    /** @return array<string, array<string, mixed>> */
    private function args(): array {
        return [
            'title' => [ 'type' => 'string', 'required' => true ],
            'date'  => [ 'type' => 'string', 'required' => true ],
            'notes' => [ 'type' => 'string' ],
        ];
    }

    /** @param array<string,mixed> $body */
    private function request( array $body ): WP_REST_Request {
        $request = new WP_REST_Request( 'POST', self::ROUTE );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( (object) $body ) );
        return $request;
    }

    /** @return array<string,mixed> */
    private function firstError( ?WP_REST_Response $response ): array {
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return is_array( $data ) ? (array) ( $data['errors'][0] ?? [] ) : [];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function dispatch( string $route, array $body ): array {
        $request = $this->request( $body );
        $request->set_route( $route );
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
