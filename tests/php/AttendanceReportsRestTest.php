<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #2137 — smoke + envelope test for the new per-player attendance REST
 * surface `GET /reports/attendance` (the route the team report's inline
 * drill-down accordion fetches), plus the #2136 `activity_type_key`
 * parameter shared across the attendance endpoints.
 *
 * Asserts, at the REST boundary:
 *   - the route is registered on `rest_api_init`;
 *   - an unauthenticated caller is denied (never 200, never >=500) — the
 *     bug class the wider RestSmokeTest guards;
 *   - an authorized administrator gets the documented `{ players, threshold }`
 *     envelope shape (content is exercised by AttendanceRankingQuery's own
 *     unit coverage; this freezes the route + shape).
 */
final class AttendanceReportsRestTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();

        // Grant the administrator the tt_* caps (the bootstrap runs
        // migrations only, not ensureCapabilities()). Idempotent.
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        // Fresh REST server so plugin routes register for this test.
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function test_attendance_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(
            '/talenttrack/v1/reports/attendance',
            $routes,
            'the per-player attendance route is registered'
        );
    }

    public function test_unauthenticated_request_is_denied(): void {
        wp_set_current_user( 0 );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/attendance' );
        $req->set_param( 'team_id', 1 );
        $res = rest_do_request( $req );

        $status = $res->get_status();
        $this->assertNotSame( 200, $status, 'must NOT return 200 to an unauthenticated caller' );
        $this->assertLessThan( 500, $status, "must NOT 500 for an unauthenticated caller (got {$status})" );
    }

    public function test_admin_gets_players_and_threshold_envelope(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/attendance' );
        $req->set_param( 'from', '2020-01-01' );
        $req->set_param( 'to', '2020-12-31' );
        // The #2136 type filter param must be accepted (sanitized to a key).
        $req->set_param( 'activity_type_key', 'training' );
        $res = rest_do_request( $req );

        $this->assertSame( 200, $res->get_status(), 'an authorized admin gets 200' );

        $data = $res->get_data();
        $this->assertIsArray( $data );
        // RestResponse envelope: { success, data: { players, threshold } }.
        $payload = $data['data'] ?? $data;
        $this->assertArrayHasKey( 'players', $payload, 'envelope carries a players list' );
        $this->assertArrayHasKey( 'threshold', $payload, 'envelope carries the at-risk threshold' );
        $this->assertIsArray( $payload['players'] );
    }
}
