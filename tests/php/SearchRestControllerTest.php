<?php
/**
 * Cross-entity search (#2458).
 *
 * These are minors, and a search box is the easiest place in a product to
 * leak a record someone may not see. The tests that matter here are the
 * privacy ones: the endpoint must filter per row through the same
 * authorization the detail views use, not merely gate the route.
 */

use TT\Shared\Tiles\TileRegistry;

class SearchRestControllerTest extends WP_UnitTestCase {

    private int $admin_id = 0;
    private int $outsider_id = 0;

    public function set_up(): void {
        parent::set_up();
        do_action( 'rest_api_init' );

        $this->admin_id    = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->outsider_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        TileRegistry::clear();
        TileRegistry::register( [
            'slug'      => 'players',
            'view_slug' => 'players',
            'kind'      => 'work',
            'label'     => 'Players',
            'group'     => 'Performance',
            'order'     => 10,
            'url'       => home_url( '/?tt_view=players' ),
        ] );
        TileRegistry::register( [
            'slug'      => 'measurements',
            'view_slug' => 'measurements',
            'kind'      => 'work',
            'label'     => 'Measurements',
            'group'     => 'Performance',
            'order'     => 20,
            'url'       => home_url( '/?tt_view=measurements' ),
        ] );
    }

    public function tear_down(): void {
        TileRegistry::clear();
        parent::tear_down();
    }

    private function get( string $q = '', string $types = '' ): array {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/search' );
        if ( $q !== '' )     $req->set_param( 'q', $q );
        if ( $types !== '' ) $req->set_param( 'types', $types );

        $res = rest_get_server()->dispatch( $req );
        return (array) $res->get_data();
    }

    public function test_requires_a_logged_in_user(): void {
        wp_set_current_user( 0 );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/search' );
        $res = rest_get_server()->dispatch( $req );

        $this->assertSame( 401, $res->get_status() );
    }

    public function test_returns_reachable_views_before_anything_is_typed(): void {
        wp_set_current_user( $this->admin_id );

        $data = $this->get( '', 'view' );

        $labels = array_column( $data['results'], 'label' );
        $this->assertContains( 'Players', $labels );
        $this->assertContains( 'Measurements', $labels );
    }

    public function test_filters_views_by_the_typed_query(): void {
        wp_set_current_user( $this->admin_id );

        $labels = array_column( $this->get( 'measu', 'view' )['results'], 'label' );

        $this->assertContains( 'Measurements', $labels );
        $this->assertNotContains( 'Players', $labels );
    }

    public function test_view_results_are_capability_filtered_by_the_registry(): void {
        // The subscriber has no tile caps, so the registry resolves an
        // empty set — the endpoint inherits that filtering rather than
        // reimplementing it.
        wp_set_current_user( $this->outsider_id );

        $this->assertSame( [], $this->get( '', 'view' )['results'] );
    }

    public function test_a_one_character_query_does_not_search_records(): void {
        wp_set_current_user( $this->admin_id );

        // Below the minimum length only views are considered, so a stray
        // keystroke never runs three LIKE scans.
        $types = array_column( $this->get( 'a' )['results'], 'type' );

        $this->assertNotContains( 'player', $types );
        $this->assertNotContains( 'team', $types );
        $this->assertNotContains( 'activity', $types );
    }

    public function test_results_are_hard_capped(): void {
        wp_set_current_user( $this->admin_id );

        for ( $i = 0; $i < 20; $i++ ) {
            TileRegistry::register( [
                'slug'      => 'filler-' . $i,
                'view_slug' => 'filler-' . $i,
                'kind'      => 'work',
                'label'     => 'Filler ' . $i,
                'group'     => 'Reference',
                'order'     => 100 + $i,
                'url'       => home_url( '/?tt_view=filler-' . $i ),
            ] );
        }

        $this->assertLessThanOrEqual( 8, count( $this->get( 'filler', 'view' )['results'] ) );
    }

    public function test_every_result_carries_a_usable_url(): void {
        wp_set_current_user( $this->admin_id );

        foreach ( $this->get( '', 'view' )['results'] as $row ) {
            $this->assertArrayHasKey( 'url', $row );
            $this->assertNotSame( '', $row['url'] );
            $this->assertArrayHasKey( 'type', $row );
            $this->assertArrayHasKey( 'label', $row );
        }
    }
}
