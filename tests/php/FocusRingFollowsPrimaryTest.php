<?php
namespace TT\Tests\Php;

use TT\Core\Kernel;
use TT\Shared\Frontend\BrandStyles;
use WP_UnitTestCase;

/**
 * #4804 — the focus ring takes the primary's hue and stays visible.
 *
 * `--tt-focus` was a fixed green at 35% opacity under every design, and at
 * that opacity it reached nowhere near 3:1 against the page. It is now a
 * solid ring in the primary colour, declared where a theme's primary is
 * visible, and a Branding primary too light to reach 3:1 gets a darker shade
 * of the same hue.
 */
final class FocusRingFollowsPrimaryTest extends WP_UnitTestCase {

    /** The canvas page background, the darkest light background a ring sits on. */
    private const BG = [ 0xf4, 0xf5, 0xf7 ];

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function rgb( string $hex ): array {
        return [ (int) hexdec( substr( $hex, 1, 2 ) ), (int) hexdec( substr( $hex, 3, 2 ) ), (int) hexdec( substr( $hex, 5, 2 ) ) ];
    }

    public function test_tokens_derive_the_ring_from_the_primary_where_a_theme_can_set_it(): void {
        $css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/tokens.css' );

        $this->assertMatchesRegularExpression(
            '/\.tt-root,\s*\.tt-dashboard\s*\{\s*--tt-focus:\s*0 0 0 2px var\(--tt-focus-color, var\(--tt-primary\)\);/',
            $css
        );
        $this->assertStringNotContainsString( 'rgba(11, 61, 46, 0.35)', $css, 'the fixed green is gone' );
    }

    /**
     * The shipped primaries already reach 3:1, so they need no shade.
     *
     * @return array<string, array{0: string}>
     */
    public static function darkPrimaries(): array {
        return [
            'default'     => [ '#0b3d2e' ],
            'leon hutten' => [ '#0262a1' ],
            'federation'  => [ '#1b3660' ],
        ];
    }

    /**
     * @dataProvider darkPrimaries
     */
    public function test_a_primary_that_reaches_three_to_one_is_used_as_is( string $hex ): void {
        $this->assertSame( '', BrandStyles::focusColor( $hex ) );
        $this->assertGreaterThanOrEqual( 3.0, BrandStyles::contrast( $this->rgb( $hex ), self::BG ) );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function lightPrimaries(): array {
        return [
            'amber'     => [ '#ffd54f' ],
            'sky'       => [ '#4fc3f7' ],
            'club gold' => [ '#e8b624' ],
        ];
    }

    /**
     * @dataProvider lightPrimaries
     */
    public function test_a_light_primary_gets_a_darker_shade_of_its_own_hue( string $hex ): void {
        $focus = BrandStyles::focusColor( $hex );

        $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $focus );
        $this->assertGreaterThanOrEqual( 3.0, BrandStyles::contrast( $this->rgb( $focus ), self::BG ) );

        // Same hue: the strongest channel stays the strongest.
        $in  = $this->rgb( $hex );
        $out = $this->rgb( $focus );
        $this->assertSame( array_search( max( $in ), $in, true ), array_search( max( $out ), $out, true ) );
    }

    /**
     * `--tt-focus` is a box-shadow value. Read as a colour (an outline or an
     * SVG stroke) it is invalid wherever it is defined, and the property
     * falls back to its initial value: no ring at all. A colour reads
     * `--tt-focus-color` falling back to `--tt-primary` instead.
     */
    public function test_the_focus_token_is_only_ever_a_box_shadow(): void {
        $dir   = dirname( __DIR__, 2 ) . '/assets/css';
        $paths = array_merge( glob( $dir . '/*.css' ) ?: [], glob( $dir . '/*/*.css' ) ?: [] );
        $wrong = [];

        foreach ( $paths as $path ) {
            $css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $path ) );
            preg_match_all( '/([a-z-]+)\s*:[^;{}]*var\(\s*--tt-focus\s*[,)]/', $css, $m );
            foreach ( $m[1] as $property ) {
                if ( $property !== 'box-shadow' ) {
                    $wrong[] = basename( $path ) . ': ' . $property;
                }
            }
        }

        $this->assertSame( [], $wrong );
    }

    public function test_branding_emits_the_shade_only_when_it_is_needed(): void {
        $cfg   = Kernel::instance()->container()->get( 'config' );
        $saved = $cfg->get( 'primary_color', '' );

        $cfg->set( 'primary_color', '#ffd54f' );
        $this->assertStringContainsString( '--tt-focus-color:#', BrandStyles::css() );

        $cfg->set( 'primary_color', '#7a1fa2' );
        $this->assertStringNotContainsString( '--tt-focus-color', BrandStyles::css() );

        $cfg->set( 'primary_color', $saved );
    }
}
