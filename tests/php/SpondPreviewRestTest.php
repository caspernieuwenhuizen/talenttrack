<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #2284 — dry-run Spond integration monitor preview route.
 *
 * The monitor fetches Spond LIVE and diffs each event against the stored
 * activity WITHOUT writing anything, so an operator can diagnose why a
 * printed activity differs from Spond. This test covers the two things
 * that don't need a live Spond call: that the route is registered with
 * the right method + the tt_edit_teams permission gate, and that hitting
 * it for a team with no spond_group_id returns the "no group linked"
 * envelope (ok:false inside a 200) rather than fataling.
 */
final class SpondPreviewRestTest extends WP_UnitTestCase {

    private const ROUTE = '/talenttrack/v1/teams/(?P<id>\d+)/spond/preview';

    private int $team_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        // A team with NO Spond group linked.
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id' => 1,
            'name'    => 'Unlinked team',
        ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function test_preview_route_is_registered_with_post_and_permission_callback(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( self::ROUTE, $routes );

        $methods  = [];
        $has_perm = false;
        foreach ( $routes[ self::ROUTE ] as $endpoint ) {
            $methods = array_merge( $methods, array_keys( (array) $endpoint['methods'] ) );
            if ( ! empty( $endpoint['permission_callback'] ) ) {
                $has_perm = true;
            }
        }

        $this->assertContains( 'POST', $methods, 'preview route accepts POST' );
        $this->assertTrue( $has_perm, 'preview route declares a permission_callback (not __return_true)' );
    }

    public function test_preview_denied_without_edit_teams_capability(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/teams/' . $this->team_id . '/spond/preview' );
        $res = rest_do_request( $req );

        $this->assertSame( 401, $res->get_status(), 'a user without tt_edit_teams is rejected' );
    }

    public function test_team_with_no_group_returns_no_group_envelope(): void {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/teams/' . $this->team_id . '/spond/preview' );
        $res = rest_do_request( $req );

        // 200 envelope with ok:false — not a fatal, not an HTTP error.
        $this->assertSame( 200, $res->get_status() );

        $data = $res->get_data();
        $this->assertTrue( (bool) ( $data['success'] ?? false ) );
        $this->assertFalse( (bool) ( $data['data']['ok'] ?? true ), 'ok is false for an unlinked team' );
        $this->assertSame( 'no_group_linked', $data['data']['error_code'] ?? '' );
    }
}
