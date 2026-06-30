<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\REST\MeasurementsRestController;
use TT\Infrastructure\Security\RolesService;

/**
 * #2145 — REST smoke for GET /talenttrack/v1/measurement-results, the Test
 * results browse contract behind FrontendTestResultsView.
 *
 * The new-route smoke-test mandate (#1388 Tier 2): every register_rest_route
 * gets an authorization-coverage check. A misconfigured permission_callback
 * here would leak the academy's measurement results to an unauthorised
 * caller. These tests assert, over the LIVE route table, that:
 *
 *   (a) the route registers on rest_api_init;
 *   (b) its permission_callback denies an unauthenticated caller (401/403) —
 *       never 200 (silent leak) and never >=500 (a crashing callback);
 *   (c) the permission helper gates on the MatrixGate `measurements`/`read`
 *       entity cap, so a plain subscriber is denied.
 */
final class MeasurementResultsBrowseRestTest extends WP_UnitTestCase {

    private const ROUTE = '/talenttrack/v1/measurement-results';

    public function set_up(): void {
        parent::set_up();

        // The plugin grants tt_* caps via ensureCapabilities() on activation /
        // admin_init, neither of which fires in the wp-env test bootstrap.
        // Seed them so the admin-side checks resolve. Idempotent.
        ( new RolesService() )->ensureCapabilities();

        // Rebuild the REST server so the Measurements module's routes register
        // freshly for this test (the module boots MeasurementsRestController::
        // init() on plugin boot, which hooks rest_api_init).
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    // ---- (a) route registers --------------------------------------------

    public function test_route_is_registered(): void {
        global $wp_rest_server;
        $routes = $wp_rest_server->get_routes();
        $this->assertArrayHasKey( self::ROUTE, $routes, 'browse-results route registers' );
    }

    // ---- (b) unauthenticated callers are denied -------------------------

    public function test_unauthenticated_request_is_denied(): void {
        wp_set_current_user( 0 );

        $request = new WP_REST_Request( 'GET', self::ROUTE );
        $request->set_param( 'definition_id', 1 );
        $response = rest_do_request( $request );
        $status   = $response->get_status();

        $this->assertNotSame( 200, $status, 'must NOT return 200 to an unauthenticated caller' );
        $this->assertLessThan( 500, $status, "must NOT 500 for an unauthenticated caller (got $status)" );
        $this->assertContains( $status, [ 401, 403 ], "must deny with 401 or 403 (got $status)" );
    }

    // ---- (c) permission helper gates on the matrix cap ------------------

    public function test_subscriber_is_denied_by_the_permission_helper(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $this->assertFalse(
            MeasurementsRestController::can_browse_results(),
            'subscriber holds no measurements/read scope and must be denied'
        );
    }

    public function test_logged_out_caller_is_denied_by_the_permission_helper(): void {
        wp_set_current_user( 0 );
        $this->assertFalse( MeasurementsRestController::can_browse_results() );
    }
}
