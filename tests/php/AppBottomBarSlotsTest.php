<?php
/**
 * Bottom-bar slot derivation (#2459).
 *
 * The slot list is what lets this ship before the product question
 * ("which four per persona?") is answered, so the behaviour worth pinning
 * is the degradation: a stale or partial config must never produce an
 * empty or broken bar, and setup tiles must never reach the thumb zone.
 */

use TT\Shared\Frontend\Components\FrontendAppBottomBar;
use TT\Shared\Tiles\TileRegistry;

class AppBottomBarSlotsTest extends WP_UnitTestCase {

    private int $user_id = 0;

    public function set_up(): void {
        parent::set_up();
        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user_id );

        TileRegistry::clear();
        ( new \TT\Infrastructure\Config\ConfigService() )->set( FrontendAppBottomBar::CONFIG_KEY, '' );

        // Six work tiles across two groups, plus one setup tile.
        $this->registerTile( 'players',     'Players',     'work', 'Performance', 10 );
        $this->registerTile( 'evaluations', 'Evaluations', 'work', 'Performance', 20 );
        $this->registerTile( 'teams',       'Teams',       'work', 'People',      10 );
        $this->registerTile( 'staff',       'Staff',       'work', 'People',      20 );
        $this->registerTile( 'activities',  'Activities',  'work', 'People',      30 );
        $this->registerTile( 'reports',     'Reports',     'work', 'People',      40 );
        $this->registerTile( 'configuration', 'Configuration', 'setup', 'Reference', 10 );
    }

    public function tear_down(): void {
        TileRegistry::clear();
        parent::tear_down();
    }

    private function registerTile( string $slug, string $label, string $kind, string $group, int $order ): void {
        TileRegistry::register( [
            'slug'      => $slug,
            'view_slug' => $slug,
            'kind'      => $kind,
            'label'     => $label,
            'group'     => $group,
            'order'     => $order,
            'url'       => home_url( '/?tt_view=' . $slug ),
        ] );
    }

    /** @return list<string> */
    private function slugs(): array {
        return array_map(
            static fn( $t ) => (string) ( $t['view_slug'] ?? $t['slug'] ),
            FrontendAppBottomBar::slots( $this->user_id )
        );
    }

    public function test_derives_four_work_tiles_when_nothing_is_configured(): void {
        $this->assertSame( [ 'players', 'evaluations', 'teams', 'staff' ], $this->slugs() );
    }

    public function test_never_places_a_setup_tile_in_the_thumb_zone(): void {
        $this->assertNotContains( 'configuration', $this->slugs() );
    }

    public function test_configured_slugs_win_and_keep_their_order(): void {
        $this->configure( [ '*' => [ 'reports', 'activities', 'teams', 'players' ] ] );
        $this->assertSame( [ 'reports', 'activities', 'teams', 'players' ], $this->slugs() );
    }

    public function test_a_partial_config_is_backfilled_from_the_derived_default(): void {
        $this->configure( [ '*' => [ 'reports' ] ] );

        $slugs = $this->slugs();
        $this->assertCount( 4, $slugs );
        $this->assertSame( 'reports', $slugs[0] );
        // Backfill picks up the derived order, skipping the already-placed tile.
        $this->assertSame( [ 'reports', 'players', 'evaluations', 'teams' ], $slugs );
    }

    public function test_a_stale_slug_is_skipped_rather_than_rendered_dead(): void {
        $this->configure( [ '*' => [ 'a-tile-that-was-removed', 'teams' ] ] );

        $slugs = $this->slugs();
        $this->assertNotContains( 'a-tile-that-was-removed', $slugs );
        $this->assertContains( 'teams', $slugs );
        $this->assertCount( 4, $slugs, 'the derived default must backfill the skipped slot' );
    }

    public function test_a_setup_slug_in_config_is_ignored(): void {
        $this->configure( [ '*' => [ 'configuration', 'teams' ] ] );
        $this->assertNotContains( 'configuration', $this->slugs() );
    }

    public function test_duplicate_slugs_in_config_do_not_shrink_the_bar(): void {
        $this->configure( [ '*' => [ 'teams', 'teams', 'teams', 'teams' ] ] );

        $slugs = $this->slugs();
        $this->assertCount( 4, $slugs );
        $this->assertSame( 1, count( array_keys( $slugs, 'teams', true ) ) );
    }

    public function test_malformed_config_falls_back_to_the_derived_default(): void {
        ( new \TT\Infrastructure\Config\ConfigService() )->set( FrontendAppBottomBar::CONFIG_KEY, 'not json' );
        $this->assertSame( [ 'players', 'evaluations', 'teams', 'staff' ], $this->slugs() );
    }

    public function test_fewer_visible_tiles_than_slots_yields_a_shorter_bar_not_an_error(): void {
        TileRegistry::clear();
        $this->registerTile( 'players', 'Players', 'work', 'Performance', 10 );

        $this->assertSame( [ 'players' ], $this->slugs() );
    }

    public function test_no_visible_tiles_yields_no_bar(): void {
        TileRegistry::clear();
        $this->assertSame( [], FrontendAppBottomBar::slots( $this->user_id ) );
    }

    private function configure( array $map ): void {
        ( new \TT\Infrastructure\Config\ConfigService() )->set(
            FrontendAppBottomBar::CONFIG_KEY,
            (string) wp_json_encode( $map )
        );
    }
}
