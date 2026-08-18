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
        // A declared `cap` is what makes these tiles capability-filtered.
        // `manage_options` is held by the administrator and not by the
        // subscriber, which is exactly the split the privacy test needs.
        TileRegistry::register( [
            'slug'      => 'players',
            'view_slug' => 'players',
            'kind'      => 'work',
            'label'     => 'Players',
            'group'     => 'Performance',
            'order'     => 10,
            'cap'       => 'manage_options',
            'url'       => home_url( '/?tt_view=players' ),
        ] );
        TileRegistry::register( [
            'slug'      => 'measurements',
            'view_slug' => 'measurements',
            'kind'      => 'work',
            'label'     => 'Measurements',
            'group'     => 'Performance',
            'order'     => 20,
            'cap'       => 'manage_options',
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
        // The subscriber fails both tiles' declared cap, so the registry
        // resolves an empty set — the endpoint inherits that filtering
        // rather than reimplementing it, which is the point.
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
                'cap'       => 'manage_options',
                'url'       => home_url( '/?tt_view=filler-' . $i ),
            ] );
        }

        // #2508 raised the cap from 8 to 10 so sections and records can share
        // one list without starving each other.
        $this->assertLessThanOrEqual( 10, count( $this->get( 'filler', 'view' )['results'] ) );
    }

    // --- sections must not crowd out records (#2508) --------------------

    /**
     * Register enough matching sections to fill the response on their own.
     * Before #2508 these were merged first and the combined list truncated,
     * so every record was discarded — typing two letters that matched a lot
     * of sections hid every player.
     */
    private function registerCrowdingViews( string $needle, int $count = 12 ): void {
        for ( $i = 0; $i < $count; $i++ ) {
            TileRegistry::register( [
                'slug'      => 'crowd-' . $i,
                'view_slug' => 'crowd-' . $i,
                'kind'      => 'work',
                'label'     => ucfirst( $needle ) . 'section ' . $i,
                'group'     => 'Reference',
                'order'     => 200 + $i,
                'cap'       => 'manage_options',
                'url'       => home_url( '/?tt_view=crowd-' . $i ),
            ] );
        }
    }

    private function typesIn( array $results ): array {
        return array_count_values( array_map(
            static fn( $r ) => (string) ( $r['type'] ?? '?' ),
            $results
        ) );
    }

    public function test_a_matching_record_survives_a_flood_of_matching_sections(): void {
        wp_set_current_user( $this->admin_id );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'first_name' => 'Zeno',
            'last_name'  => 'Crowdtest',
            'club_id'    => 1,
        ] );

        $this->registerCrowdingViews( 'crowd' );

        $results = $this->get( 'crowd' )['results'];
        $types   = $this->typesIn( $results );

        $this->assertGreaterThan( 0, $types['player'] ?? 0,
            'a matching player must appear even when many sections also match' );
        $this->assertLessThanOrEqual( 3, $types['view'] ?? 0,
            'sections are capped while records are competing for slots' );
    }

    public function test_sections_still_fill_the_list_when_no_record_matches(): void {
        // The quota is a floor for records, not a ceiling on sections: with
        // nothing else competing, sections should use the whole list.
        wp_set_current_user( $this->admin_id );
        $this->registerCrowdingViews( 'crowd' );

        $results = $this->get( 'crowd' )['results'];
        $types   = $this->typesIn( $results );

        $this->assertSame( count( $results ), $types['view'] ?? 0 );
        $this->assertGreaterThan( 3, count( $results ),
            'unused record slots go back to sections' );
    }

    public function test_a_record_only_query_uses_the_whole_list(): void {
        wp_set_current_user( $this->admin_id );

        global $wpdb;
        for ( $i = 0; $i < 6; $i++ ) {
            $wpdb->insert( $wpdb->prefix . 'tt_players', [
                'first_name' => 'Quirinus' . $i,
                'last_name'  => 'Zzzunique',
                'club_id'    => 1,
            ] );
        }

        $results = $this->get( 'Zzzunique' )['results'];
        $types   = $this->typesIn( $results );

        $this->assertSame( 0, $types['view'] ?? 0, 'no section matches this query' );
        $this->assertGreaterThanOrEqual( 6, count( $results ) );
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
