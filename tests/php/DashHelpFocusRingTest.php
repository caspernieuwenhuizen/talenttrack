<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * The dashboard header's help link (`.tt-dash-help`) had a `:focus` rule
 * that removed the outline, so a keyboard user got only a border colour
 * change. Same guard as `HelpButtonTapTargetTest` for `.tt-help-button`.
 */
final class DashHelpFocusRingTest extends WP_UnitTestCase {

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

    public function test_keyboard_focus_keeps_a_visible_ring(): void {
        $css = $this->css();

        $this->assertSame( [], array_filter(
            array_merge( $this->rulesFor( '.tt-dash-help:focus', $css ), $this->rulesFor( '.tt-dash-help:focus-visible', $css ) ),
            static fn( string $body ): bool => strpos( $body, 'outline: none' ) !== false
        ), 'no focus rule removes the outline' );

        $ring = implode( ' ', $this->rulesFor( '.tt-dash-help:focus-visible', $css ) );
        $this->assertMatchesRegularExpression( '/outline: 2px solid var\(--tt-primary/', $ring );
    }

    public function test_hover_keeps_the_colour_change(): void {
        $hover = implode( ' ', $this->rulesFor( '.tt-dash-help:hover', $this->css() ) );
        $this->assertStringContainsString( 'color: var(--tt-primary', $hover );
        $this->assertStringContainsString( 'border-color: var(--tt-primary', $hover );
        $this->assertStringNotContainsString( 'outline: none', $hover );
    }
}
