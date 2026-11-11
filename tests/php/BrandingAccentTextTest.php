<?php
namespace TT\Tests\Php;

use TT\Core\Kernel;
use TT\Shared\Frontend\BrandStyles;
use WP_UnitTestCase;

/**
 * #5105 — the secondary printed as text reads at 4.5:1.
 *
 * The gold is a fill colour; as text on white it measured 1.9:1. Four
 * surfaces print it as text and read `--tt-secondary-text`, which
 * tokens.css sets for the default design and Branding feeds, through
 * `--tt-secondary-text-color`, from a chosen secondary however light.
 */
final class BrandingAccentTextTest extends WP_UnitTestCase {

    /** White, the default soft background, and the canvas page background. */
    private const BACKGROUNDS = [ '#ffffff', '#faf8f3', '#f4f5f7' ];

    /** @var string */
    private $saved = '';

    private function cfg(): \TT\Infrastructure\Config\ConfigService {
        return Kernel::instance()->container()->get( 'config' );
    }

    public function setUp(): void {
        parent::setUp();
        $this->saved = (string) $this->cfg()->get( 'secondary_color', '' );
    }

    public function tearDown(): void {
        $this->cfg()->set( 'secondary_color', $this->saved );
        parent::tearDown();
    }

    private function root(): string {
        return dirname( __DIR__, 2 );
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgb( string $hex ): array {
        return [ (int) hexdec( substr( $hex, 1, 2 ) ), (int) hexdec( substr( $hex, 3, 2 ) ), (int) hexdec( substr( $hex, 5, 2 ) ) ];
    }

    private function assertReads( string $hex, string $what ): void {
        foreach ( self::BACKGROUNDS as $bg ) {
            $ratio = BrandStyles::contrast( $this->rgb( $hex ), $this->rgb( $bg ) );
            $this->assertGreaterThanOrEqual( 4.5, $ratio, sprintf( '%s: %s on %s is %.2f:1', $what, $hex, $bg, $ratio ) );
        }
    }

    public function test_the_default_token_is_the_derived_shade_of_the_default_gold(): void {
        $css = (string) file_get_contents( $this->root() . '/assets/css/tokens.css' );
        $this->assertSame( 1, preg_match( '/--tt-secondary-text:\s*var\(--tt-secondary-text-color,\s*(#[0-9a-f]{6})\)/', $css, $m ) );
        $this->assertSame( BrandStyles::accentTextColor( '#e8b624' ), $m[1] );
        $this->assertReads( $m[1], 'default accent text' );
    }

    public function test_a_light_secondary_gets_a_shade_that_reads(): void {
        foreach ( [ '#e8b624', '#ffe566', '#ffd400', '#ffffff', '#00ff00', '#c6f432' ] as $secondary ) {
            $this->assertReads( BrandStyles::accentTextColor( $secondary ), 'secondary ' . $secondary );
        }
    }

    public function test_a_dark_secondary_is_used_as_it_is(): void {
        $this->assertSame( '#0b3d2e', BrandStyles::accentTextColor( '#0b3d2e' ) );
        $this->assertSame( '', BrandStyles::accentTextColor( 'not-a-colour' ) );
    }

    public function test_branding_emits_the_shade_with_the_secondary(): void {
        $this->cfg()->set( 'secondary_color', '#ffe566' );
        $css = BrandStyles::css();

        $this->assertStringContainsString( '--tt-secondary:#ffe566', $css );
        $this->assertStringContainsString( '--tt-secondary-text-color:' . BrandStyles::accentTextColor( '#ffe566' ), $css );
    }

    public function test_the_four_text_surfaces_read_the_token(): void {
        $surfaces = [
            'frontend-404.css'        => '.tt-404-emblem',
            'frontend-filter-bar.css' => '.tt-dashboard .tt-viewchip__star',
            'frontend-podium.css'     => '.tt-dashboard .tt-podium-team-header h2 a:focus-visible',
            'frontend-pop.css'        => '.tt-dashboard .tt-pop-pct',
        ];
        foreach ( $surfaces as $file => $selector ) {
            $css = (string) file_get_contents( $this->root() . '/assets/css/' . $file );
            $pos = strpos( $css, $selector . ' {' );
            $this->assertNotFalse( $pos, $file . ' ' . $selector );
            $rule = substr( $css, (int) $pos, (int) strpos( $css, '}', (int) $pos ) - (int) $pos );
            $this->assertMatchesRegularExpression( '/(^|\s)color:\s*var\(--tt-secondary-text\b/m', $rule, $file . ' ' . $selector );
        }
    }
}
