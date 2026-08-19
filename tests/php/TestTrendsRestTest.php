<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #2537 — smoke + envelope test for `GET /reports/test-trends`.
 *
 * Freezes the route and its gate: registered on rest_api_init, never open to
 * an unauthenticated caller, and — for an authorized caller — refusing a
 * request with no test chosen rather than guessing one. The numbers
 * themselves are exercised by TestTrendsQueryTest; this pins the boundary.
 */
final class TestTrendsRestTest extends WP_UnitTestCase {

    private const ROUTE = '/talenttrack/v1/reports/test-trends';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function test_route_is_registered(): void {
        $this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
    }

    public function test_unauthenticated_caller_is_denied(): void {
        wp_set_current_user( 0 );
        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );

        $this->assertNotSame( 200, $response->get_status(), 'the report must not answer an anonymous caller' );
        $this->assertLessThan( 500, $response->get_status(), 'and must refuse cleanly, not crash' );
    }

    public function test_authorized_caller_without_a_test_gets_a_400(): void {
        wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );

        $this->assertSame( 400, $response->get_status(), 'a report about no test is a bad request, not an empty page' );
    }
}
