<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\CoreSurfaceRegistration;
use TT\Shared\Icons\IconRenderer;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4168 — every registered tile icon exists as a line icon AND a duotone icon.
 *
 * The shell nav (sidebar, rail, bottom bar) draws a tile's icon through
 * IconRenderer::render(), which reads the line set and returns an empty
 * string when the file is missing. Injuries, Team development and three
 * other surfaces had a duotone file only, so they sat in the nav with no
 * icon. tools/check-tile-icons.php catches this on the source; this test
 * catches it on what the registry actually holds, including tiles whose
 * registration a source scan would not read.
 */
final class TileIconVariantsTest extends WP_UnitTestCase {

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

    public function test_every_registered_tile_icon_exists_in_both_sets(): void {
        // Module tiles are already registered by the kernel; the core seed
        // is registered again here so the test does not depend on what an
        // earlier test left in the registry.
        TileRegistry::clear();
        CoreSurfaceRegistration::register();
        $tiles = array_merge( $this->saved, TileRegistry::allRegistered() );

        $this->assertNotEmpty( $tiles, 'No tiles registered — the test would prove nothing.' );

        $missing = [];
        foreach ( $tiles as $tile ) {
            $icon = (string) ( $tile['icon'] ?? '' );
            if ( $icon === '' ) continue;
            $slug = (string) ( $tile['slug'] ?? $tile['view_slug'] ?? '?' );
            if ( ! IconRenderer::exists( $icon ) ) {
                $missing[] = sprintf( '%s: line icon "%s"', $slug, $icon );
            }
            if ( ! IconRenderer::duotoneExists( $icon ) ) {
                $missing[] = sprintf( '%s: duotone icon "%s"', $slug, $icon );
            }
        }

        $this->assertSame( [], array_values( array_unique( $missing ) ) );
    }

    public function test_the_icons_that_were_duotone_only_now_render_in_the_nav(): void {
        foreach ( [ 'alert', 'note', 'pdp', 'sliders', 'trials' ] as $icon ) {
            $this->assertStringContainsString( '<svg', IconRenderer::render( $icon ), $icon );
        }
    }
}
