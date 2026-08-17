<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Filters\SavedViewsDefaults;
use TT\Infrastructure\Filters\SavedViewsRepository;

/**
 * #2450 — one saved view per surface can be marked the user's default and is
 * applied when they open that surface with no filters of their own.
 *
 * Two invariants matter most and are what these tests guard:
 *
 *   1. At most one default per (club, user, surface). MySQL cannot express
 *      that as a partial unique index, so it lives in the repository and can
 *      only be trusted if it is tested.
 *   2. The escape hatch works. The bar's Clear link lands on the surface's
 *      param-free URL, which is exactly the condition that re-applies a
 *      default — without `tt_views=off` the user could never reach an
 *      unfiltered list.
 */
final class SavedViewsDefaultTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1/filter-presets';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb; $wpdb->hide_errors();
        global $wp_rest_server; $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        $_GET = [];
        global $wp_rest_server; $wp_rest_server = null;
        parent::tear_down();
    }

    private function admin(): int {
        return self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    private function save( string $view_key, string $name, array $filters ): int {
        $req = new WP_REST_Request( 'POST', self::BASE );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [ 'view_key' => $view_key, 'name' => $name, 'filters' => $filters ] ) );
        return (int) ( rest_do_request( $req )->get_data()['data']['id'] ?? 0 );
    }

    private function setDefault( int $id, bool $on = true ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PATCH', self::BASE . '/' . $id );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [ 'is_default' => $on ] ) );
        return rest_do_request( $req );
    }

    // --- the one-default invariant --------------------------------------

    public function test_marking_a_default_clears_the_previous_one(): void {
        $uid = $this->admin();
        wp_set_current_user( $uid );

        $a = $this->save( 'attendance_team', 'A', [ 'period' => 'this_season' ] );
        $b = $this->save( 'attendance_team', 'B', [ 'period' => 'this_month' ] );

        $this->assertSame( 200, $this->setDefault( $a )->get_status() );
        $repo = new SavedViewsRepository();
        $this->assertSame( $a, (int) $repo->findDefault( $uid, 'attendance_team' )->id );

        $this->assertSame( 200, $this->setDefault( $b )->get_status() );
        $current = $repo->findDefault( $uid, 'attendance_team' );
        $this->assertSame( $b, (int) $current->id, 'the newer default must win' );

        // And A must actually have been cleared, not merely out-ranked.
        $this->assertFalse( (bool) $repo->find( $a, $uid )->is_default );
    }

    public function test_default_can_be_cleared(): void {
        $uid = $this->admin();
        wp_set_current_user( $uid );
        $a = $this->save( 'attendance_team', 'A', [ 'period' => 'this_season' ] );

        $this->setDefault( $a, true );
        $this->setDefault( $a, false );

        $this->assertNull( ( new SavedViewsRepository() )->findDefault( $uid, 'attendance_team' ) );
    }

    public function test_defaults_are_per_surface(): void {
        $uid = $this->admin();
        wp_set_current_user( $uid );
        $a = $this->save( 'attendance_team', 'A', [ 'period' => 'this_season' ] );
        $b = $this->save( 'minutes_team', 'B', [ 'period' => 'this_month' ] );

        $this->setDefault( $a );
        $this->setDefault( $b );

        $repo = new SavedViewsRepository();
        $this->assertSame( $a, (int) $repo->findDefault( $uid, 'attendance_team' )->id );
        $this->assertSame( $b, (int) $repo->findDefault( $uid, 'minutes_team' )->id,
            'setting a default on one surface must not clear another surface' );
    }

    public function test_defaults_are_per_user(): void {
        $user_a = $this->admin();
        wp_set_current_user( $user_a );
        $a = $this->save( 'attendance_team', 'A', [ 'period' => 'this_season' ] );
        $this->setDefault( $a );

        $user_b = $this->admin();
        wp_set_current_user( $user_b );
        $b = $this->save( 'attendance_team', 'B', [ 'period' => 'this_month' ] );
        $this->setDefault( $b );

        $repo = new SavedViewsRepository();
        wp_set_current_user( $user_a );
        $this->assertSame( $a, (int) $repo->findDefault( $user_a, 'attendance_team' )->id,
            "another user's default must not disturb this one" );
    }

    public function test_a_rename_and_a_default_can_ride_one_patch(): void {
        $uid = $this->admin();
        wp_set_current_user( $uid );
        $a = $this->save( 'attendance_team', 'Before', [ 'period' => 'this_season' ] );

        $req = new WP_REST_Request( 'PATCH', self::BASE . '/' . $a );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [ 'name' => 'After', 'is_default' => true ] ) );
        $this->assertSame( 200, rest_do_request( $req )->get_status() );

        $row = ( new SavedViewsRepository() )->findDefault( $uid, 'attendance_team' );
        $this->assertSame( 'After', (string) $row->name );
    }

    public function test_cannot_default_another_users_view(): void {
        $owner = $this->admin();
        wp_set_current_user( $owner );
        $a = $this->save( 'attendance_team', 'Mine', [ 'period' => 'this_season' ] );

        wp_set_current_user( $this->admin() );
        $this->assertSame( 403, $this->setDefault( $a )->get_status() );
        $this->assertNull( ( new SavedViewsRepository() )->findDefault( $owner, 'attendance_team' ) );
    }

    // --- request-matching rules ------------------------------------------

    public function test_surface_is_resolved_from_the_route(): void {
        $_GET = [ 'tt_view' => 'attendance-report-team' ];
        $this->assertSame( 'attendance_team', SavedViewsDefaults::surfaceForRequest() );

        $_GET = [ 'tt_view' => 'minutes-audit' ];
        $this->assertSame( 'minutes_audit', SavedViewsDefaults::surfaceForRequest() );
    }

    public function test_unregistered_route_resolves_to_nothing(): void {
        $_GET = [ 'tt_view' => 'players' ];
        $this->assertSame( '', SavedViewsDefaults::surfaceForRequest() );

        $_GET = [];
        $this->assertSame( '', SavedViewsDefaults::surfaceForRequest() );
    }

    public function test_routing_params_do_not_count_as_filters(): void {
        // tt_view / tt_back / slug describe where you are, not what you filtered.
        $_GET = [ 'tt_view' => 'attendance-report-team', 'tt_back' => 'players', 'slug' => 'x' ];
        $this->assertFalse( SavedViewsDefaults::hasOwnFilters( 'attendance_team' ) );
    }

    public function test_any_own_filter_suppresses_the_default(): void {
        // A deep link or a shared URL already says what to show.
        $_GET = [ 'tt_view' => 'attendance-report-team', 'period' => 'this_week' ];
        $this->assertTrue( SavedViewsDefaults::hasOwnFilters( 'attendance_team' ) );

        $_GET = [ 'tt_view' => 'attendance-report-team', 'from' => '2026-01-01' ];
        $this->assertTrue( SavedViewsDefaults::hasOwnFilters( 'attendance_team' ) );
    }

    public function test_an_empty_filter_param_does_not_count(): void {
        $_GET = [ 'tt_view' => 'attendance-report-team', 'period' => '' ];
        $this->assertFalse( SavedViewsDefaults::hasOwnFilters( 'attendance_team' ) );
    }

    public function test_a_param_belonging_to_another_surface_does_not_count(): void {
        // `n` is the leaderboard's top-N, not the team report's.
        $_GET = [ 'tt_view' => 'attendance-report-team', 'n' => '10' ];
        $this->assertFalse( SavedViewsDefaults::hasOwnFilters( 'attendance_team' ) );
        $this->assertTrue( SavedViewsDefaults::hasOwnFilters( 'attendance_leaderboard' ) );
    }

    // --- the escape hatch --------------------------------------------------

    public function test_off_param_suppresses_application(): void {
        $_GET = [ 'tt_view' => 'attendance-report-team', 'tt_views' => 'off' ];
        $this->assertTrue( SavedViewsDefaults::suppressed() );
    }

    public function test_absent_off_param_does_not_suppress(): void {
        $_GET = [ 'tt_view' => 'attendance-report-team' ];
        $this->assertFalse( SavedViewsDefaults::suppressed() );
    }

    public function test_every_registered_route_declares_its_params(): void {
        // A route with no params would treat every entry as filter-free and
        // re-apply the default over the user's own choices.
        foreach ( SavedViewsDefaults::routes() as $key => $route ) {
            $this->assertNotEmpty( $route['tt_view'] ?? '', "{$key} has no tt_view" );
            $this->assertNotEmpty( $route['params'] ?? [], "{$key} declares no filter params" );
        }
    }
}
