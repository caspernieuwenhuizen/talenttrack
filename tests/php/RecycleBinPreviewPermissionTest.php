<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #2413 — the recycle bin's cascade-preview route.
 *
 * The preview is the impact statement shown immediately before an
 * irreversible purge, so who may read it must line up with who may purge.
 * It used to gate on `tt_edit_settings` — the one outlier on a controller
 * where every other route gates on `tt_manage_recycle_bin`, and a cap that
 * is orthogonal to bin access in both directions. These tests pin the
 * corrected gate over the LIVE route table.
 */
final class RecycleBinPreviewPermissionTest extends WP_UnitTestCase {

    private const PREVIEW = '/talenttrack/v1/recycle-bin/preview/team/1';

    public function set_up(): void {
        parent::set_up();

        // Caps are granted on activation / admin_init, neither of which fires
        // in the wp-env bootstrap. Idempotent.
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function preview(): int {
        $res = rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::PREVIEW ) );
        return $res->get_status();
    }

    public function test_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();
        $found  = false;
        foreach ( array_keys( $routes ) as $route ) {
            if ( strpos( $route, '/recycle-bin/preview/' ) !== false ) { $found = true; break; }
        }
        $this->assertTrue( $found, 'the preview route should register on rest_api_init' );
    }

    public function test_denies_an_unauthenticated_caller(): void {
        wp_set_current_user( 0 );
        $this->assertContains( $this->preview(), [ 401, 403 ] );
    }

    public function test_denies_a_logged_in_user_without_the_bin_cap(): void {
        $user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $user );

        $this->assertContains(
            $this->preview(),
            [ 401, 403 ],
            'a user without tt_manage_recycle_bin must not read the preview'
        );
    }

    /**
     * The defect this issue reports: tt_edit_settings ALONE used to open the
     * preview. It must not — the cap is orthogonal to bin access.
     */
    public function test_edit_settings_alone_does_not_open_the_preview(): void {
        $user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $u    = new \WP_User( $user );
        $u->add_cap( 'tt_edit_settings' );
        wp_set_current_user( $user );

        $this->assertContains(
            $this->preview(),
            [ 401, 403 ],
            'tt_edit_settings is not a substitute for tt_manage_recycle_bin'
        );
    }

    /**
     * The other half of the defect: a legitimate bin manager WITHOUT
     * tt_edit_settings used to be refused the impact statement for a purge
     * they are allowed to run. They now get past the permission gate — the
     * status is no longer an auth failure (the id below need not exist, so a
     * 400/404 from the handler is a pass here).
     */
    public function test_bin_cap_alone_passes_the_permission_gate(): void {
        $user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $u    = new \WP_User( $user );
        $u->add_cap( 'tt_manage_recycle_bin' );
        wp_set_current_user( $user );

        $status = $this->preview();

        $this->assertNotContains(
            $status,
            [ 401, 403 ],
            'tt_manage_recycle_bin alone must open the preview'
        );
        $this->assertLessThan( 500, $status, 'the permission_callback must not crash' );
    }
}
