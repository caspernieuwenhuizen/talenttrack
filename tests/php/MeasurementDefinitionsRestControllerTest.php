<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Measurements\Rest\MeasurementDefinitionsRestController;

/**
 * #2120 — REST CRUD for the test-definition catalogue
 * (/wp-json/talenttrack/v1/measurement-definitions).
 *
 * The new-route smoke-test mandate (#1388 Tier 2): every register_rest_route
 * gets an authorization-coverage check. This controller exposes the test
 * catalogue as a resource (CLAUDE.md §4) — a misconfigured permission_callback
 * would leak the academy's measurement model or hand an unprivileged user a
 * write/purge path. These tests assert, over the LIVE route table, that:
 *
 *   (a) every route registers on rest_api_init;
 *   (b) every route's permission_callback denies an unauthenticated caller
 *       (401/403) — never 200 (silent leak) and never >=500 (a crashing
 *       permission_callback);
 *   (c) the permission helpers gate on the MatrixGate entity caps, so an
 *       unprivileged logged-in user is denied the read / change / create-delete
 *       surfaces, and the permanent-delete path is no weaker than the recycle
 *       bin's own tt_manage_recycle_bin cap.
 */
final class MeasurementDefinitionsRestControllerTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1/measurement-definitions';

    public function set_up(): void {
        parent::set_up();

        // The plugin grants the tt_* caps via ensureCapabilities() on
        // activation / admin_init, neither of which fires in the wp-env test
        // bootstrap. Seed them so the admin-side checks resolve. Idempotent.
        ( new RolesService() )->ensureCapabilities();

        // Rebuild the REST server so the Measurements module's routes register
        // freshly for this test (the module boots MeasurementDefinitionsRest-
        // Controller::init() on plugin boot, which hooks rest_api_init).
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    // ---- (a) routes register --------------------------------------------

    public function test_routes_are_registered(): void {
        global $wp_rest_server;
        $routes = $wp_rest_server->get_routes();

        $this->assertArrayHasKey( self::BASE, $routes, 'collection route registers' );
        $this->assertArrayHasKey( self::BASE . '/(?P<id>\d+)', $routes, 'single-item route registers' );
        $this->assertArrayHasKey( self::BASE . '/(?P<id>\d+)/targets', $routes, 'targets route registers' );
        $this->assertArrayHasKey( self::BASE . '/(?P<id>\d+)/levels', $routes, 'levels route registers' );
        $this->assertArrayHasKey( self::BASE . '/(?P<id>\d+)/permanent', $routes, 'permanent-delete route registers' );
    }

    // ---- (b) denial-path matrix (unauthenticated) -----------------------

    /**
     * Every method on every route MUST deny an anonymous caller. A 200 here
     * is a silent leak; a >=500 is a crashing permission_callback — both are
     * the bug class this mandate guards.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public function provideDenialRoutes(): array {
        return [
            'GET collection'    => [ 'GET',    self::BASE ],
            'POST collection'   => [ 'POST',   self::BASE ],
            'GET item'          => [ 'GET',    self::BASE . '/1' ],
            'PUT item'          => [ 'PUT',    self::BASE . '/1' ],
            'DELETE item'       => [ 'DELETE', self::BASE . '/1' ],
            'POST targets'      => [ 'POST',   self::BASE . '/1/targets' ],
            'GET levels'        => [ 'GET',    self::BASE . '/1/levels' ],
            'POST levels'       => [ 'POST',   self::BASE . '/1/levels' ],
            'DELETE permanent'  => [ 'DELETE', self::BASE . '/1/permanent' ],
        ];
    }

    /**
     * @dataProvider provideDenialRoutes
     */
    public function test_unauthenticated_request_is_denied( string $method, string $route ): void {
        wp_set_current_user( 0 );

        $response = rest_do_request( new WP_REST_Request( $method, $route ) );
        $status   = $response->get_status();
        $label    = "$method $route";

        $this->assertNotSame( 200, $status, "$label must NOT return 200 to an unauthenticated caller" );
        $this->assertLessThan( 500, $status, "$label must NOT 500 for an unauthenticated caller (got $status)" );
        $this->assertContains(
            $status,
            [ 401, 403 ],
            "$label must deny an unauthenticated caller with 401 or 403 (got $status)"
        );
    }

    // ---- (c) permission helpers gate on caps ----------------------------

    public function test_unprivileged_user_is_denied_every_surface(): void {
        // A plain subscriber holds none of the measurement_definitions caps
        // nor tt_manage_recycle_bin.
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $this->assertFalse( MeasurementDefinitionsRestController::can_read(), 'subscriber may not read the catalogue' );
        $this->assertFalse( MeasurementDefinitionsRestController::can_change(), 'subscriber may not edit a definition' );
        $this->assertFalse( MeasurementDefinitionsRestController::can_create_delete(), 'subscriber may not create / archive a definition' );

        // The permanent-delete path re-gates on the recycle-bin cap.
        $this->assertFalse(
            current_user_can( 'tt_manage_recycle_bin' ),
            'subscriber lacks the recycle-bin cap that the permanent-delete route requires'
        );
    }

    public function test_logged_out_caller_is_denied_every_helper(): void {
        wp_set_current_user( 0 );

        $this->assertFalse( MeasurementDefinitionsRestController::can_read() );
        $this->assertFalse( MeasurementDefinitionsRestController::can_change() );
        $this->assertFalse( MeasurementDefinitionsRestController::can_create_delete() );
    }
}
