<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #2385 — personal named filter presets for the standard reports' filter
 * bar. Covers the REST round-trip (save → list → delete), that a saved
 * period stays a relative key, and that presets are per-user (never shared).
 */
final class SavedFiltersRestTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1/reports/filter-presets';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb; $wpdb->hide_errors();
        global $wp_rest_server; $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server; $wp_rest_server = null;
        parent::tear_down();
    }

    private function admin(): int {
        return self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    private function save( string $report_key, string $name, array $filters ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', self::BASE );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [ 'report_key' => $report_key, 'name' => $name, 'filters' => $filters ] ) );
        return rest_do_request( $req );
    }

    private function listPresets( string $report_key ): array {
        $req = new WP_REST_Request( 'GET', self::BASE );
        $req->set_param( 'report_key', $report_key );
        $res  = rest_do_request( $req );
        // RestResponse::success wraps the payload under `data`.
        $data = $res->get_data();
        $inner = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : [];
        return isset( $inner['presets'] ) && is_array( $inner['presets'] ) ? $inner['presets'] : [];
    }

    public function test_routes_registered_with_permission_callback(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( self::BASE, $routes );
        foreach ( $routes[ self::BASE ] as $endpoint ) {
            $this->assertNotSame( '__return_true', $endpoint['permission_callback'] ?? null );
        }
    }

    public function test_save_list_delete_roundtrip_keeps_relative_period(): void {
        wp_set_current_user( $this->admin() );

        $res = $this->save( 'attendance_team', 'U17 league games', [
            'period'            => 'this_season',
            'activity_type_key' => 'game',
        ] );
        $this->assertSame( 200, $res->get_status() );
        $saved = $res->get_data()['data'] ?? [];
        $this->assertArrayHasKey( 'id', $saved );

        $presets = $this->listPresets( 'attendance_team' );
        $this->assertCount( 1, $presets );
        $this->assertSame( 'U17 league games', $presets[0]['name'] );
        // The period is stored as a KEY (relative), not resolved dates.
        $this->assertSame( 'this_season', $presets[0]['filters']['period'] );
        $this->assertSame( 'game', $presets[0]['filters']['activity_type_key'] );

        $del = rest_do_request( new WP_REST_Request( 'DELETE', self::BASE . '/' . (int) $saved['id'] ) );
        $this->assertSame( 200, $del->get_status() );
        $this->assertCount( 0, $this->listPresets( 'attendance_team' ) );
    }

    public function test_presets_are_per_user(): void {
        $user_a = $this->admin();
        $user_b = $this->admin();

        wp_set_current_user( $user_a );
        $this->assertSame( 200, $this->save( 'attendance_team', 'A view', [ 'period' => 'this_month' ] )->get_status() );
        $this->assertCount( 1, $this->listPresets( 'attendance_team' ) );

        wp_set_current_user( $user_b );
        $this->assertCount( 0, $this->listPresets( 'attendance_team' ), "another user must not see A's presets" );
    }

    public function test_list_is_scoped_by_report_key(): void {
        wp_set_current_user( $this->admin() );
        $this->save( 'attendance_team', 'Team view', [ 'period' => 'this_season' ] );

        $this->assertCount( 1, $this->listPresets( 'attendance_team' ) );
        $this->assertCount( 0, $this->listPresets( 'minutes_team' ), 'a preset belongs to exactly one report' );
    }
}
