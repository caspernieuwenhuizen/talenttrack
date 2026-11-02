<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\CoreSurfaceRegistration;
use TT\Shared\Frontend\Components\FrontendAppNav;
use TT\Shared\Tiles\NavigationGroups;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4669 — navigation groups are keys from one ordered list.
 *
 * A group used to be its translated label, and any label missing from a
 * hard-coded list of six fell back to the order its module booted in. An
 * unknown `kind` silently became `work`. These tests pin the replacement:
 * `config/navigation_groups.php` names every group, a tile must name one of
 * them, and the list alone decides the order on the dashboard and in the
 * sidebar.
 */
final class NavigationGroupsTest extends WP_UnitTestCase {

    private const DASHBOARD = [ 'me', 'people_teams', 'activities', 'development', 'tactics', 'analytics', 'intake', 'communication', 'product_ideas', 'settings' ];
    private const SETTINGS  = [ 'academy', 'players_teams', 'development_evaluations', 'activities_matches', 'lookups', 'people_access', 'messages_tasks', 'data', 'features_plan', 'system' ];

    /** @var list<array<string, mixed>> */
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

    /**
     * Every tile the plugin registers: the module tiles the kernel already
     * holds plus the core seed, registered again so the test does not
     * depend on what an earlier test left behind.
     *
     * @return list<array<string, mixed>>
     */
    private function everyTile(): array {
        TileRegistry::clear();
        CoreSurfaceRegistration::register();
        $by_slug = [];
        foreach ( array_merge( $this->saved, TileRegistry::allRegistered() ) as $tile ) {
            $by_slug[ (string) ( $tile['slug'] ?? '' ) . '|' . (string) ( $tile['group'] ?? '' ) ] = $tile;
        }
        return array_values( $by_slug );
    }

    /** @param array<string, mixed> $tile */
    private function assertRefused( array $tile, string $why ): void {
        $before = count( TileRegistry::allRegistered() );
        $threw  = false;
        try {
            TileRegistry::register( $tile );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
        }
        $this->assertCount( $before, TileRegistry::allRegistered(), $why . ': the tile must not be stored' );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $this->assertTrue( $threw, $why . ': under WP_DEBUG the registry throws' );
        }
    }

    /** @return array<string, mixed> */
    private function tile( string $slug, string $group, string $icon = '' ): array {
        return [
            'slug'      => $slug,
            'view_slug' => $slug,
            'kind'      => 'work',
            'label'     => ucfirst( $slug ),
            'group'     => $group,
            'icon'      => $icon,
            'cap'       => 'read',
        ];
    }

    // ---- the list ----

    public function test_the_config_lists_every_group_in_the_agreed_order(): void {
        $this->assertSame( array_merge( self::DASHBOARD, self::SETTINGS ), NavigationGroups::keys() );
        $this->assertSame( self::DASHBOARD, NavigationGroups::keysFor( NavigationGroups::SURFACE_DASHBOARD ) );
        $this->assertSame( self::SETTINGS, NavigationGroups::keysFor( NavigationGroups::SURFACE_SETTINGS ) );
        $this->assertSame( TileRegistry::groupOrder(), NavigationGroups::keys() );
    }

    public function test_every_group_has_a_label(): void {
        foreach ( NavigationGroups::all() as $group ) {
            $this->assertNotSame( '', $group['label'], $group['key'] );
            $this->assertNotSame( $group['key'], $group['label'], $group['key'] . ' must carry a real label, not its key' );
        }
    }

    // ---- what a tile may name ----

    public function test_every_registered_tile_names_a_listed_group_and_kind(): void {
        $tiles = $this->everyTile();
        $this->assertNotEmpty( $tiles, 'No tiles registered — the test would prove nothing.' );

        $bad = [];
        foreach ( $tiles as $tile ) {
            $slug = (string) ( $tile['slug'] ?? '?' );
            if ( ! NavigationGroups::exists( (string) ( $tile['group'] ?? '' ) ) ) {
                $bad[] = $slug . ': group "' . (string) ( $tile['group'] ?? '' ) . '"';
            }
            if ( ! in_array( $tile['kind'] ?? '', TileRegistry::KINDS, true ) ) {
                $bad[] = $slug . ': kind "' . (string) ( $tile['kind'] ?? '' ) . '"';
            }
        }
        $this->assertSame( [], $bad );
    }

    public function test_an_unknown_group_key_is_refused(): void {
        TileRegistry::clear();
        $this->assertRefused( $this->tile( 'tt-test-unknown-group', 'Performance' ), 'a translated label is not a key' );
        $this->assertRefused( $this->tile( 'tt-test-no-group', '' ), 'a tile without a group' );
    }

    public function test_an_unknown_kind_is_refused(): void {
        TileRegistry::clear();
        $tile         = $this->tile( 'tt-test-admin-kind', 'system' );
        $tile['kind'] = 'admin';
        $this->assertRefused( $tile, 'the undocumented admin kind' );
    }

    // ---- order ----

    public function test_group_order_ignores_the_order_tiles_were_registered_in(): void {
        TileRegistry::clear();
        // A settings group first, then a module that boots late adding a
        // dashboard group: the old fallback rendered them in this order.
        TileRegistry::register( $this->tile( 'tt-test-system', 'system' ) );
        TileRegistry::register( $this->tile( 'tt-test-analytics', 'analytics' ) );
        TileRegistry::register( $this->tile( 'tt-test-academy', 'academy' ) );
        TileRegistry::register( $this->tile( 'tt-test-me', 'me' ) );

        $user = self::factory()->user->create( [ 'role' => 'editor' ] );

        $grouped = array_column( TileRegistry::tilesForUserGrouped( $user ), 'key' );
        $this->assertSame( [ 'me', 'analytics', 'academy', 'system' ], $grouped );

        $nav = array_column( FrontendAppNav::groups( $user ), 'key' );
        $this->assertSame( $grouped, $nav, 'the sidebar reads the same order as the dashboard' );
    }

    public function test_a_group_carries_its_translated_label(): void {
        TileRegistry::clear();
        TileRegistry::register( $this->tile( 'tt-test-intake', 'intake' ) );
        $user = self::factory()->user->create( [ 'role' => 'editor' ] );

        $groups = TileRegistry::tilesForUserGrouped( $user );
        $this->assertSame( NavigationGroups::label( 'intake' ), $groups[0]['label'] ?? null );
    }

    // ---- icons ----

    public function test_no_two_tiles_in_one_group_share_an_icon(): void {
        $seen  = [];
        $clash = [];
        foreach ( $this->everyTile() as $tile ) {
            $icon = (string) ( $tile['icon'] ?? '' );
            if ( $icon === '' ) continue;
            $group = (string) ( $tile['group'] ?? '' );
            $slug  = (string) ( $tile['slug'] ?? '' );
            if ( isset( $seen[ $group ][ $icon ] ) && $seen[ $group ][ $icon ] !== $slug ) {
                $clash[] = sprintf( '%s: %s and %s both use "%s"', $group, $seen[ $group ][ $icon ], $slug, $icon );
                continue;
            }
            $seen[ $group ][ $icon ] = $slug;
        }
        $this->assertSame( [], $clash );
    }

    public function test_the_gear_belongs_to_the_configuration_tile_alone(): void {
        $holders = [];
        foreach ( $this->everyTile() as $tile ) {
            if ( in_array( (string) ( $tile['icon'] ?? '' ), [ 'settings', 'gear' ], true ) ) {
                $holders[] = (string) ( $tile['slug'] ?? '' );
            }
        }
        $this->assertSame( [ 'configuration' ], array_values( array_unique( $holders ) ) );
    }

    public function test_a_second_tile_with_the_same_icon_in_a_group_is_refused_under_wp_debug(): void {
        if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            $this->markTestSkipped( 'On a live install a clash is logged, not thrown.' );
        }
        TileRegistry::clear();
        TileRegistry::register( $this->tile( 'tt-test-first', 'analytics', 'reports' ) );
        // The same slug again is a re-registration, not a clash.
        TileRegistry::register( $this->tile( 'tt-test-first', 'analytics', 'reports' ) );

        $this->expectException( \InvalidArgumentException::class );
        TileRegistry::register( $this->tile( 'tt-test-second', 'analytics', 'reports' ) );
    }
}
