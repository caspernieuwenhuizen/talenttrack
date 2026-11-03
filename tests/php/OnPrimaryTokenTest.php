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

    /**
     * #4768 — the two other rules that draw text on a primary fill: the
     * active child-switcher pill and the active segmented option.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function primaryFills(): array {
        return [
            'active child-switcher pill' => [ 'frontend-app-chrome.css', '.tt-child-switcher-pill.is-active' ],
            'active segmented option'    => [ 'frontend-app-chrome.css', '.tt-segmented__opt.is-on' ],
            'active methodology tab'     => [ 'frontend-methodology-manage.css', '.tt-dashboard .tt-mmg-tab.is-active' ],
        ];
    }

    /**
     * @dataProvider primaryFills
     */
    public function test_text_on_a_primary_fill_reads_the_token( string $file, string $selector ): void {
        $css = $this->css( $file );
        $at  = strpos( $css, "\n" . $selector . ' {' );

        $this->assertNotFalse( $at, 'No `' . $selector . '` rule in ' . $file );

        $open  = strpos( $css, '{', (int) $at );
        $close = strpos( $css, '}', (int) $open );
        $rule  = substr( $css, (int) $open + 1, (int) $close - (int) $open - 1 );

        $this->assertStringContainsString( 'color: var(--tt-color-on-primary, #fff);', $rule );
    }

    /**
     * #4769 — `--tt-on-primary` was read and never defined, so the rule
     * always took its fallback and could not follow a theme.
     */
    public function test_no_sheet_reads_the_undefined_on_primary_name(): void {
        $dir   = dirname( __DIR__, 2 ) . '/assets/css';
        $paths = array_merge( glob( $dir . '/*.css' ) ?: [], glob( $dir . '/*/*.css' ) ?: [] );

        $this->assertNotEmpty( $paths, 'no stylesheets found under assets/css' );

        foreach ( $paths as $path ) {
            $this->assertStringNotContainsString( '--tt-on-primary', (string) file_get_contents( $path ), basename( $path ) );
        }
    }
}
