<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Filters\SavedViewsRegistry;

/**
 * #2385 / #2448 — personal named saved views for the shared FilterBar.
 *
 * Carries forward the #2385 coverage (save → list → delete round-trip, a
 * relative period stays a key, views are per-user and per-surface) against
 * the promoted `/filter-presets` route, and adds what the generalisation
 * introduced: the retired `/reports/filter-presets` alias, the registry-driven
 * capability gate, and the opaque-payload validation that replaced the old
 * six-key report whitelist.
 */
final class SavedViewsRestTest extends WP_UnitTestCase {

    private const BASE  = '/talenttrack/v1/filter-presets';
    private const ALIAS = '/talenttrack/v1/reports/filter-presets';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb; $wpdb->hide_errors();
        global $wp_rest_server; $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        SavedViewsRegistry::resetForTests();
        global $wp_rest_server; $wp_rest_server = null;
        parent::tear_down();
    }

    private function admin(): int {
        return self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    private function save( string $view_key, string $name, array $filters, string $base = self::BASE ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', $base );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [ 'view_key' => $view_key, 'name' => $name, 'filters' => $filters ] ) );
        return rest_do_request( $req );
    }

    /** @return array<int,array<string,mixed>> */
    private function listViews( string $view_key, string $base = self::BASE ): array {
        $req = new WP_REST_Request( 'GET', $base );
        $req->set_param( 'view_key', $view_key );
        $res  = rest_do_request( $req );
        $data = $res->get_data();
        $inner = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : [];
        return isset( $inner['views'] ) && is_array( $inner['views'] ) ? $inner['views'] : [];
    }

    public function test_routes_registered_with_permission_callback(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( self::BASE, $routes );
        foreach ( $routes[ self::BASE ] as $endpoint ) {
            $this->assertNotSame( '__return_true', $endpoint['permission_callback'] ?? null );
        }
    }

    public function test_retired_reports_route_is_still_registered_as_an_alias(): void {
        // Kept for one release so a page loaded just before a deploy keeps
        // working against the old path.
        $this->assertArrayHasKey( self::ALIAS, rest_get_server()->get_routes() );
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

        $views = $this->listViews( 'attendance_team' );
        $this->assertCount( 1, $views );
        $this->assertSame( 'U17 league games', $views[0]['name'] );
        // The period is stored as a KEY (relative), not resolved dates.
        $this->assertSame( 'this_season', $views[0]['filters']['period'] );
        $this->assertSame( 'game', $views[0]['filters']['activity_type_key'] );

        $del = rest_do_request( new WP_REST_Request( 'DELETE', self::BASE . '/' . (int) $saved['id'] ) );
        $this->assertSame( 200, $del->get_status() );
        $this->assertCount( 0, $this->listViews( 'attendance_team' ) );
    }

    public function test_views_are_per_user(): void {
        $user_a = $this->admin();
        $user_b = $this->admin();

        wp_set_current_user( $user_a );
        $this->assertSame( 200, $this->save( 'attendance_team', 'A view', [ 'period' => 'this_month' ] )->get_status() );
        $this->assertCount( 1, $this->listViews( 'attendance_team' ) );

        wp_set_current_user( $user_b );
        $this->assertCount( 0, $this->listViews( 'attendance_team' ), "another user must not see A's views" );
    }

    public function test_list_is_scoped_by_view_key(): void {
        wp_set_current_user( $this->admin() );
        $this->save( 'attendance_team', 'Team view', [ 'period' => 'this_season' ] );

        $this->assertCount( 1, $this->listViews( 'attendance_team' ) );
        $this->assertCount( 0, $this->listViews( 'minutes_team' ), 'a view belongs to exactly one surface' );
    }

    public function test_alias_route_accepts_the_retired_report_key_param(): void {
        wp_set_current_user( $this->admin() );

        $req = new WP_REST_Request( 'POST', self::ALIAS );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [
            'report_key' => 'attendance_team',
            'name'       => 'Legacy payload',
            'filters'    => [ 'period' => 'this_season' ],
        ] ) );
        $this->assertSame( 200, rest_do_request( $req )->get_status() );
        $this->assertCount( 1, $this->listViews( 'attendance_team' ) );
    }

    // --- registry-driven capability gate (#2448) -----------------------

    public function test_unregistered_surface_is_refused(): void {
        // Fail-closed: an unknown key must not become a way around the gate.
        wp_set_current_user( $this->admin() );
        $this->assertSame( 403, $this->save( 'not_a_real_surface', 'Nope', [] )->get_status() );
    }

    public function test_surface_is_gated_on_its_own_capability_not_analytics(): void {
        // A surface registered against a capability the user lacks is refused,
        // even though the user holds tt_view_analytics (#2385's fixed gate).
        SavedViewsRegistry::register( 'gated_surface', 'tt_capability_nobody_has' );

        $editor = self::factory()->user->create( [ 'role' => 'editor' ] );
        wp_set_current_user( $editor );

        $this->assertSame( 403, $this->save( 'gated_surface', 'Nope', [] )->get_status() );
    }

    public function test_logged_out_is_refused(): void {
        // WordPress distinguishes the two: 401 for an anonymous caller, 403
        // once authenticated but unauthorised (the assertions above). Pinning
        // 401 here keeps that distinction rather than accepting either.
        wp_set_current_user( 0 );
        $this->assertSame( 401, $this->save( 'attendance_team', 'Nope', [] )->get_status() );
    }

    public function test_cannot_delete_another_users_view(): void {
        $owner = $this->admin();
        wp_set_current_user( $owner );
        $saved = $this->save( 'attendance_team', 'Mine', [ 'period' => 'this_season' ] )->get_data()['data'];

        wp_set_current_user( $this->admin() );
        $del = rest_do_request( new WP_REST_Request( 'DELETE', self::BASE . '/' . (int) $saved['id'] ) );
        $this->assertSame( 403, $del->get_status(), "a user must not delete someone else's view" );

        wp_set_current_user( $owner );
        $this->assertCount( 1, $this->listViews( 'attendance_team' ), 'the row must survive the refused delete' );
    }

    // --- opaque payload validation (#2448) -----------------------------

    public function test_list_view_param_shapes_are_accepted(): void {
        // The old six-key report whitelist would have dropped every one of
        // these, silently saving an empty view on a list surface.
        SavedViewsRegistry::register( 'players_list', 'read' );
        wp_set_current_user( $this->admin() );

        $this->save( 'players_list', 'Archived U17', [
            'filter[team_id]'  => '12',
            'filter[archived]' => '1',
            'search'           => 'jansen',
            'orderby'          => 'last_name',
            'order'            => 'asc',
        ] );

        $filters = $this->listViews( 'players_list' )[0]['filters'];
        $this->assertSame( '12', $filters['filter[team_id]'] );
        $this->assertSame( '1', $filters['filter[archived]'] );
        $this->assertSame( 'jansen', $filters['search'] );
        $this->assertSame( 'last_name', $filters['orderby'] );
    }

    public function test_malformed_keys_and_non_scalars_are_dropped(): void {
        wp_set_current_user( $this->admin() );

        $this->save( 'attendance_team', 'Mixed', [
            'period'          => 'this_season',
            'bad key'         => 'x',
            'inject);drop'    => 'x',
            'nested'          => [ 'a' => 'b' ],
            'empty'           => '',
        ] );

        $filters = $this->listViews( 'attendance_team' )[0]['filters'];
        $this->assertSame( [ 'period' => 'this_season' ], $filters );
    }

    public function test_payload_is_capped(): void {
        wp_set_current_user( $this->admin() );

        $big = [];
        for ( $i = 0; $i < 40; $i++ ) { $big[ 'k' . $i ] = 'v'; }
        $this->save( 'attendance_team', 'Too many', $big );

        $this->assertLessThanOrEqual( 20, count( $this->listViews( 'attendance_team' )[0]['filters'] ) );
    }

    public function test_overlong_values_are_dropped(): void {
        wp_set_current_user( $this->admin() );

        $this->save( 'attendance_team', 'Long', [
            'period' => 'this_season',
            'huge'   => str_repeat( 'x', 500 ),
        ] );

        $filters = $this->listViews( 'attendance_team' )[0]['filters'];
        $this->assertArrayHasKey( 'period', $filters );
        $this->assertArrayNotHasKey( 'huge', $filters );
    }

    public function test_unnamed_view_is_rejected(): void {
        wp_set_current_user( $this->admin() );
        $this->assertSame( 400, $this->save( 'attendance_team', '   ', [ 'period' => 'x' ] )->get_status() );
    }
}
