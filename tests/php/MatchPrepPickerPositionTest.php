<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4263 — the match prep player picker is `position: fixed`, so it is
 * placed in viewport coordinates. Adding the scroll offset pushed it below
 * the fold on a scrolled page, behind a backdrop that blocked the rest.
 * The browser walk is `tests/e2e/match-prep-picker.spec.js`; this pins the
 * two halves of the contract without a browser.
 */
final class MatchPrepPickerPositionTest extends WP_UnitTestCase {

    public function test_open_picker_positions_in_viewport_space(): void {
        $js    = (string) file_get_contents( TT_PLUGIN_DIR . 'assets/js/frontend-match-prep.js' );
        $start = (int) strpos( $js, 'function openPicker(' );
        $this->assertGreaterThan( 0, $start );
        $body = substr( $js, $start, (int) strpos( $js, 'function renderPickerList(' ) - $start );

        $this->assertStringNotContainsString( 'window.scrollY', $body );
        $this->assertStringNotContainsString( 'window.scrollX', $body );
        $this->assertStringContainsString( 'window.innerHeight - pr.height - 8', $body );
        $this->assertStringContainsString( 'window.innerWidth - 8', $body );
    }

    public function test_the_picker_is_fixed_and_never_wider_than_the_viewport(): void {
        $css   = (string) file_get_contents( TT_PLUGIN_DIR . 'assets/css/frontend-match-prep.css' );
        $start = (int) strpos( $css, '.tt-mp-picker {' );
        $this->assertGreaterThan( 0, $start );
        $rule = substr( $css, $start, (int) strpos( $css, '}', $start ) - $start );

        $this->assertStringContainsString( 'position: fixed;', $rule );
        $this->assertStringContainsString( 'max-width: min(22rem, calc(100vw - 1rem));', $rule );
    }
}
