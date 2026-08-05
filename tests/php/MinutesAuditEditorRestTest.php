<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #2367 — smoke + envelope test for the per-match minutes-audit EDITOR read
 * surface `GET /reports/minutes-audit/{activity}/editor`.
 *
 * Asserts, at the REST boundary:
 *   - the route is registered on `rest_api_init`;
 *   - an unauthenticated caller is denied (never 200, never >=500) — the
 *     denial path the New-route smoke-test mandate (#1388) guards. The route
 *     is gated on `tt_edit_activities`, the SAME write cap the two minutes
 *     write paths enforce, so a viewer-only user never receives an editor
 *     payload they could not commit;
 *   - an authorized administrator gets the documented editor envelope
 *     ({ activity, owned_by_execution, half_length, players }) for a seeded
 *     game activity; the routing content is exercised by the arbiter test
 *     (MatchExecutionMinutesArbiterRestTest), this freezes the route + shape.
 */
final class MinutesAuditEditorRestTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 9471;

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        // A minimal game activity on team 1 so editorRows() resolves.
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => self::ACTIVITY_ID,
            'team_id'           => 1,
            'title'             => 'Editor smoke match',
            'session_date'      => '2020-05-01',
            'activity_type_key' => 'match',
        ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function test_editor_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(
            '/talenttrack/v1/reports/minutes-audit/(?P<activity_id>\d+)/editor',
            $routes,
            'the minutes-audit editor route is registered'
        );
    }

    public function test_unauthenticated_request_is_denied(): void {
        wp_set_current_user( 0 );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/minutes-audit/' . self::ACTIVITY_ID . '/editor' );
        $res = rest_do_request( $req );

        $status = $res->get_status();
        $this->assertNotSame( 200, $status, 'must NOT return 200 to an unauthenticated caller' );
        $this->assertLessThan( 500, $status, "must NOT 500 for an unauthenticated caller (got {$status})" );
    }

    public function test_admin_gets_editor_envelope(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/minutes-audit/' . self::ACTIVITY_ID . '/editor' );
        $res = rest_do_request( $req );

        $this->assertSame( 200, $res->get_status(), 'an authorized admin gets 200' );

        $data    = $res->get_data();
        $payload = $data['data'] ?? $data;
        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'activity', $payload, 'envelope carries the activity meta' );
        $this->assertArrayHasKey( 'owned_by_execution', $payload, 'envelope carries the arbiter flag' );
        $this->assertArrayHasKey( 'players', $payload, 'envelope carries the squad rows' );
    }
}
