<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4805 — the shared button reads the body font.
 *
 * The canonical `.tt-btn` block in frontend-admin.css set no font-family, so
 * the legacy `.tt-btn` rule in public.css ("Helvetica Neue", Arial) decided
 * it. Under Leon Hutten the text around a button was Open Sans and the
 * button was not; a Branding body font never reached a button either.
 *
 * The other shared controls already follow the body font: `.tt-input` and
 * the `.tt-field` controls declare `font-family: inherit`, the segmented
 * option `font: inherit`, and chips are list items and spans.
 */
final class ButtonBodyFontTest extends WP_UnitTestCase {

    public function test_the_canonical_button_block_reads_the_body_font(): void {
        $css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/frontend-admin.css' );
        $at  = strpos( $css, "\n.tt-dashboard .tt-btn,\n.tt-dashboard .tt-btn-primary," );

        $this->assertNotFalse( $at, 'the canonical button block moved' );

        $open  = strpos( $css, '{', (int) $at );
        $close = strpos( $css, '}', (int) $open );
        $rule  = substr( $css, (int) $open + 1, (int) $close - (int) $open - 1 );

        $this->assertStringContainsString( 'font-family: var(--tt-font-body', $rule );
    }
}
