<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4756 — content on the primary band reads one token.
 *
 * The shell header wrote its text and focus-ring colour as raw `#fff`, and
 * `frontend-alerts.css` read a `--tt-color-on-primary` that nothing defined.
 * A theme with a light band could not recolour either. The token is now
 * defined in `tokens.css` and the band reads it.
 */
final class OnPrimaryTokenTest extends WP_UnitTestCase {

    private function css( string $file ): string {
        return (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/' . $file );
    }

    public function test_tokens_define_the_on_primary_colour(): void {
        $this->assertMatchesRegularExpression( '/--tt-color-on-primary:\s*#ffffff;/', $this->css( 'tokens.css' ) );
    }

    /**
     * The rules drawn on the band: the header, its title and home link, the
     * actions cluster, the help and search controls, the persona chip.
     *
     * White is allowed only as the fallback of the token read. Tokens are
     * scoped to `.tt-root`, which is not on every page that renders the
     * shell, and there a bare `var()` would leave the band's text the
     * theme's colour instead of white.
     */
    public function test_the_band_reads_the_token_with_a_white_fallback_only(): void {
        $css = $this->css( 'frontend-app-chrome.css' );
        $end = strpos( $css, '/* ---- Reusable KPI tile' );

        $this->assertNotFalse( $end, 'the KPI tile section marks where the band rules end' );

        $band = substr( $css, 0, (int) $end );

        $this->assertStringContainsString( 'var(--tt-color-on-primary, #fff)', $band );
        $this->assertStringNotContainsString( 'var(--tt-color-on-primary)', $band, 'a token read without its fallback' );

        $outside_fallback = str_replace( 'var(--tt-color-on-primary, #fff)', '', $band );
        $this->assertDoesNotMatchRegularExpression( '/#fff(fff)?\b/i', $outside_fallback, 'a bare #fff on the band' );
    }
}
