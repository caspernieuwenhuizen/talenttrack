<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Tiles\TileRegistry;

/**
 * #2570 — the dispatcher enforces the capability the tile already declares.
 *
 * `TileRegistry` has always known which capability a view requires; that
 * declaration governed whether the tile appeared in the nav and nothing more.
 * Dispatch consulted a different rung — the matrix entity, and only when the
 * matrix was active — so a surface hidden from the nav stayed reachable by
 * typing its URL. #2569 fixed seven such views one at a time; this is the
 * structural reason they could drift.
 *
 * The fail-open decision for unregistered slugs is deliberate and is the thing
 * most likely to be "tidied" later: component sub-views, wizard steps and
 * record detail pages route without tiles of their own, and failing closed
 * would deny every one of them.
 */
final class DispatcherTileCapTest extends WP_UnitTestCase {

    private array $saved = [];

    public function set_up(): void {
        parent::set_up();
        $this->saved = TileRegistry::allRegistered();
    }

    public function tear_down(): void {
        TileRegistry::clear();
        foreach ( $this->saved as $tile ) {
            TileRegistry::register( $tile );
        }
        parent::tear_down();
    }

    private function subscriber(): int {
        return self::factory()->user->create( [ 'role' => 'subscriber' ] );
    }

    public function test_an_unregistered_slug_fails_open(): void {
        TileRegistry::clear();

        $this->assertTrue(
            TileRegistry::canAccessViewSlug( 'some-wizard-step', $this->subscriber() ),
            'sub-views, wizard steps and detail pages route without a tile - failing closed would deny them all'
        );
    }

    public function test_an_empty_slug_fails_open(): void {
        $this->assertTrue( TileRegistry::canAccessViewSlug( '', $this->subscriber() ) );
    }

    public function test_a_tile_whose_cap_the_user_lacks_denies_the_slug(): void {
        TileRegistry::clear();
        TileRegistry::register( [
            'module_class' => null,
            'view_slug'    => 'tt-test-gated',
            'group'        => 'Test',
            'kind'         => 'work',
            'order'        => 1,
            'label'        => 'Gated',
            'cap'          => 'tt_manage_nothing_at_all',
        ] );

        $this->assertFalse(
            TileRegistry::canAccessViewSlug( 'tt-test-gated', $this->subscriber() ),
            'the nav hides this tile on its cap; the URL must not still reach it'
        );
    }

    public function test_a_tile_whose_cap_the_user_holds_allows_the_slug(): void {
        TileRegistry::clear();
        TileRegistry::register( [
            'module_class' => null,
            'view_slug'    => 'tt-test-open',
            'group'        => 'Test',
            'kind'         => 'work',
            'order'        => 1,
            'label'        => 'Open',
            'cap'          => 'read',
        ] );

        $this->assertTrue(
            TileRegistry::canAccessViewSlug( 'tt-test-open', $this->subscriber() ),
            'subscribers hold `read`'
        );
    }

    /**
     * Several registrations may share a slug. The nav shows the tile if any
     * of them is visible, so dispatch has to answer the same way or the two
     * disagree again — which is the whole point of this change.
     */
    public function test_any_visible_registration_grants_the_slug(): void {
        TileRegistry::clear();
        TileRegistry::register( [
            'module_class' => null,
            'view_slug'    => 'tt-test-shared',
            'group'        => 'Test',
            'kind'         => 'work',
            'order'        => 1,
            'label'        => 'Denied variant',
            'cap'          => 'tt_manage_nothing_at_all',
        ] );
        TileRegistry::register( [
            'module_class' => null,
            'view_slug'    => 'tt-test-shared',
            'group'        => 'Test',
            'kind'         => 'work',
            'order'        => 2,
            'label'        => 'Allowed variant',
            'cap'          => 'read',
        ] );

        $this->assertTrue(
            TileRegistry::canAccessViewSlug( 'tt-test-shared', $this->subscriber() )
        );
    }

    public function test_cap_callback_is_honoured(): void {
        TileRegistry::clear();
        TileRegistry::register( [
            'module_class' => null,
            'view_slug'    => 'tt-test-callback',
            'group'        => 'Test',
            'kind'         => 'work',
            'order'        => 1,
            'label'        => 'Callback gated',
            'cap_callback' => static function ( $uid ) { return false; },
        ] );

        $this->assertFalse(
            TileRegistry::canAccessViewSlug( 'tt-test-callback', $this->subscriber() )
        );
    }
}
