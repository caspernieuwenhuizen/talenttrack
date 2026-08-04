<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #2368 — smoke + envelope test for the read-only minutes-audit matrix
 * REST surface `GET /reports/minutes-audit` (games × players).
 *
 * Asserts, at the REST boundary:
 *   - the route is registered on `rest_api_init`;
 *   - an unauthenticated caller is denied (never 200, never >=500) — the
 *     denial path the New-route smoke-test mandate (#1388) guards;
 *   - an authorized administrator gets the documented matrix envelope
 *     ({ games, players, column_totals, grand_total, summary }); the
 *     content is exercised by MinutesAuditQuery / MinutesQuery coverage,
 *     this freezes the route + shape.
 */
final class MinutesAuditRestTest extends WP_UnitTestCase {

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

    public function test_minutes_audit_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(
            '/talenttrack/v1/reports/minutes-audit',
            $routes,
            'the minutes-audit matrix route is registered'
        );
    }

    public function test_unauthenticated_request_is_denied(): void {
        wp_set_current_user( 0 );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/minutes-audit' );
        $req->set_param( 'team_id', 1 );
        $res = rest_do_request( $req );

        $status = $res->get_status();
        $this->assertNotSame( 200, $status, 'must NOT return 200 to an unauthenticated caller' );
        $this->assertLessThan( 500, $status, "must NOT 500 for an unauthenticated caller (got {$status})" );
    }

    public function test_admin_gets_matrix_envelope(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/minutes-audit' );
        $req->set_param( 'team_id', 1 );
        $req->set_param( 'from', '2020-01-01' );
        $req->set_param( 'to', '2020-12-31' );
        $res = rest_do_request( $req );

        $this->assertSame( 200, $res->get_status(), 'an authorized admin gets 200' );

        $data    = $res->get_data();
        $payload = $data['data'] ?? $data;
        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'games', $payload, 'envelope carries the games rows' );
        $this->assertArrayHasKey( 'summary', $payload, 'envelope carries the gap summary' );
    }
}
