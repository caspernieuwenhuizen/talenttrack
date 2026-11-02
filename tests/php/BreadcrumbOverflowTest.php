<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4655 — the breadcrumb chain never widens the page on a phone.
 *
 * A one-word crumb ("Aanwezigheidsraster") was wider than the current crumb's
 * 6em floor. As unbreakable text it overflowed that box, and the page scrolled
 * sideways by about 15px between 390 and 413px. Every crumb may now shrink
 * below its text, and the text is cut with an ellipsis instead.
 *
 * A stylesheet assertion guards this, as in `TapTargetFloorTest`: the defect
 * is a missing declaration, and the browser-level proof is the Playwright
 * mobile gate, which only runs where wp-env does.
 */
final class BreadcrumbOverflowTest extends WP_UnitTestCase {

    /** The declarations of the first rule whose selector list is exactly `$selector`. */
    private function rule( string $selector ): string {
        $css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/public.css' );
        $css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

        $pattern = '/(?:^|})\s*' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/';
        $this->assertSame( 1, preg_match( $pattern, $css, $m ), "public.css has no `{$selector}` rule" );

        return (string) preg_replace( '/\s+/', ' ', $m[1] );
    }

    public function test_every_crumb_may_shrink_below_its_text(): void {
        $this->assertStringContainsString( 'min-width: 0;', $this->rule( '.tt-breadcrumbs li' ) );
    }

    /** @return array<string, array{0: string}> */
    public static function labels(): array {
        return [
            'a crumb link'      => [ '.tt-breadcrumbs a' ],
            'the current crumb' => [ '.tt-breadcrumbs li[aria-current="page"]' ],
        ];
    }

    /**
     * @dataProvider labels
     */
    public function test_a_label_that_does_not_fit_is_cut_rather_than_overflowing( string $selector ): void {
        $rule = $this->rule( $selector );

        $this->assertStringContainsString( 'display: block;', $rule, 'text-overflow needs a block box, not a flex container' );
        $this->assertStringContainsString( 'overflow: hidden;', $rule );
        $this->assertStringContainsString( 'text-overflow: ellipsis;', $rule );
        $this->assertStringContainsString( 'white-space: nowrap;', $rule );
    }

    /**
     * @dataProvider labels
     */
    public function test_the_crumb_keeps_its_48px_tap_band( string $selector ): void {
        $rule = $this->rule( $selector );

        $this->assertStringContainsString( 'min-height: 48px;', $rule );
        $this->assertStringContainsString( 'line-height: 48px;', $rule );
    }
}
