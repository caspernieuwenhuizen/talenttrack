<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Icons\IconRenderer;

/**
 * #2993 — the activity type icons come from the icon set, not from emoji.
 *
 * The test that earns its place is the one asserting every type resolves
 * to a glyph that actually exists on disk. `IconRenderer::render()` on a
 * missing name returns nothing, so a typo in the resolver would render an
 * empty circle in the activity hero and nothing would fail — the icon
 * would simply be gone.
 */
final class ActivityTypeIconsTest extends WP_UnitTestCase {

    /** Every glyph the activity-type resolver can return. */
    private const TYPE_ICONS = [ 'football', 'trophy', 'clipboard', 'pin', 'training-cone' ];

    public function test_every_activity_type_icon_exists_on_disk(): void {
        foreach ( self::TYPE_ICONS as $name ) {
            $this->assertTrue(
                IconRenderer::exists( $name ),
                "assets/icons/$name.svg is missing; the activity type using it would render nothing"
            );
        }
    }

    public function test_every_activity_type_icon_renders_markup(): void {
        foreach ( self::TYPE_ICONS as $name ) {
            $svg = IconRenderer::render( $name, [ 'width' => 16, 'height' => 16 ] );
            $this->assertStringContainsString( '<svg', $svg, "$name did not render" );
        }
    }

    public function test_the_icons_inherit_currentcolor(): void {
        // They sit on the activity type's tinted ground and go into print.
        // A hard-coded stroke would be invisible on one and wrong on the
        // other — which is half of what was wrong with the emoji.
        foreach ( self::TYPE_ICONS as $name ) {
            $raw = (string) file_get_contents( IconRenderer::dir() . $name . '.svg' );
            $this->assertStringContainsString( 'currentColor', $raw, "$name does not inherit currentColor" );
            $this->assertStringNotContainsString( '#', $raw, "$name carries a hard-coded colour" );
        }
    }

    public function test_the_icons_match_the_sets_geometry(): void {
        // A glyph on a different viewBox or stroke width reads as a
        // different weight beside its neighbours, which is exactly the
        // inconsistency this issue was about.
        foreach ( self::TYPE_ICONS as $name ) {
            $raw = (string) file_get_contents( IconRenderer::dir() . $name . '.svg' );
            $this->assertStringContainsString( 'viewBox="0 0 24 24"', $raw, "$name is not on the 24x24 grid" );
            $this->assertStringContainsString( 'stroke-width="2"', $raw, "$name is not stroke-width 2" );
            $this->assertStringContainsString( 'fill="none"', $raw, "$name is not an outline glyph" );
        }
    }

    public function test_match_and_tournament_stay_distinguishable(): void {
        // A tournament is a day, a match is a fixture; the activity list
        // relies on telling them apart at a glance.
        $this->assertNotSame(
            file_get_contents( IconRenderer::dir() . 'football.svg' ),
            file_get_contents( IconRenderer::dir() . 'trophy.svg' )
        );
    }
}
