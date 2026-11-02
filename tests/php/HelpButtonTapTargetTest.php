<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4710 — the shared help link (`.tt-help-button`, `HelpDrawer::button()`).
 *
 * It measured 36px high on a phone, the one control under 48px on the report
 * wizard, and its `:focus` rule removed the outline, leaving a border colour
 * as the only keyboard cue. A stylesheet assertion guards it, as in
 * `TapTargetFloorTest`; the Playwright spec measures the rendered link.
 */
final class HelpButtonTapTargetTest extends WP_UnitTestCase {

    private function css(): string {
        $css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/public.css' );
        return (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '#/\*.*?\*/#s', '', $css ) );
    }

    /** @return list<string> Every rule body whose selector list names `$selector` exactly. */
    private function rulesFor( string $selector, string $css ): array {
        preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER );
        $out = [];
        foreach ( $m as $rule ) {
            $selectors = array_map( 'trim', explode( ',', $rule[1] ) );
            if ( in_array( $selector, $selectors, true ) ) $out[] = $rule[2];
        }
        return $out;
    }

    /** The bodies of every `@media (pointer: coarse)` block. */
    private function coarseBlocks(): string {
        $css = $this->css();
        $out = '';
        $at  = 0;
        while ( ( $at = strpos( $css, '@media (pointer: coarse)', $at ) ) !== false ) {
            $open  = (int) strpos( $css, '{', $at );
            $depth = 0;
            for ( $i = $open, $n = strlen( $css ); $i < $n; $i++ ) {
                if ( $css[ $i ] === '{' ) $depth++;
                if ( $css[ $i ] === '}' && --$depth === 0 ) break;
            }
            $out .= substr( $css, $open + 1, $i - $open - 1 ) . ' ';
            $at = $i;
        }
        return $out;
    }

    public function test_the_help_link_reads_the_tap_floor_under_a_finger(): void {
        $bodies = implode( ' ', $this->rulesFor( '.tt-help-button', $this->coarseBlocks() ) );
        $this->assertStringContainsString( 'min-height: var(--tt-tap-min', $bodies );
    }

    public function test_a_mouse_keeps_the_desktop_height(): void {
        $base = $this->rulesFor( '.tt-help-button', $this->css() );
        $this->assertNotEmpty( $base );
        $this->assertStringContainsString( 'min-height: 36px;', $base[0], 'the first (base) rule keeps 36px' );
    }

    public function test_keyboard_focus_keeps_a_visible_ring(): void {
        $css = $this->css();

        $this->assertSame( [], array_filter(
            array_merge( $this->rulesFor( '.tt-help-button:focus', $css ), $this->rulesFor( '.tt-help-button:focus-visible', $css ) ),
            static fn( string $body ): bool => strpos( $body, 'outline: none' ) !== false
        ), 'no focus rule removes the outline' );

        $ring = implode( ' ', $this->rulesFor( '.tt-help-button:focus-visible', $css ) );
        $this->assertMatchesRegularExpression( '/outline: 2px solid var\(--tt-primary/', $ring );
    }
}
