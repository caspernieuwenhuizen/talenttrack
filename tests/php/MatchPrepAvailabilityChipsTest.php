<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4664 — the match prep availability chips on a tablet.
 *
 * From 480px the name moved beside the chips and left them two thirds of the
 * row, too narrow for five: they wrapped four and one, every row grew to
 * about 150px, and "Verontschuldigd" ran over the edges of its chip. The name
 * now stays above the chips at every width (#4703), the chips sit on one
 * line from 480px, and a long label breaks inside its chip.
 *
 * A stylesheet assertion guards this, as in `TapTargetFloorTest`: the defect
 * is where a declaration sits, and the browser-level proof is the Playwright
 * mobile gate.
 */
final class MatchPrepAvailabilityChipsTest extends WP_UnitTestCase {

    private function css(): string {
        $css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/frontend-match-prep-availability.css' );
        return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
    }

    /** The body of the first `@media (min-width: N)` block, up to its closing brace. */
    private function mediaBlock( int $min_width ): string {
        $css = $this->css();
        $at  = strpos( $css, '@media (min-width: ' . $min_width . 'px)' );
        $this->assertNotFalse( $at, "no {$min_width}px block" );

        $open  = (int) strpos( $css, '{', (int) $at );
        $depth = 0;
        for ( $i = $open, $n = strlen( $css ); $i < $n; $i++ ) {
            if ( $css[ $i ] === '{' ) $depth++;
            if ( $css[ $i ] === '}' && --$depth === 0 ) {
                return (string) preg_replace( '/\s+/', ' ', substr( $css, $open + 1, $i - $open - 1 ) );
            }
        }
        $this->fail( "the {$min_width}px block never closes" );
    }

    /** Declarations of the top-level rule whose selector is exactly `$selector`. */
    private function baseRule( string $selector ): string {
        $pattern = '/(?:^|})\s*' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/';
        $this->assertSame( 1, preg_match( $pattern, $this->css(), $m ), "no `{$selector}` rule" );
        return (string) preg_replace( '/\s+/', ' ', $m[1] );
    }

    public function test_a_long_label_breaks_inside_its_chip(): void {
        $rule = $this->baseRule( '.tt-avail-seg label' );

        $this->assertStringContainsString( 'min-width: 0;', $rule );
        $this->assertStringContainsString( 'overflow-wrap: anywhere;', $rule );
        $this->assertStringContainsString( 'min-height: 48px;', $rule, 'the chip keeps its tap height' );
    }

    /** #4703 — one row from 768px, one equal column per status. */
    public function test_every_status_sits_on_one_line_from_768px(): void {
        $block = $this->mediaBlock( 768 );

        $this->assertStringContainsString( 'grid-auto-flow: column;', $block );
        $this->assertStringContainsString( 'grid-auto-columns: minmax(0, 1fr);', $block );
        // One column per status, not a fixed five: the lookup is editable.
        $this->assertStringNotContainsString( 'repeat(5', $block );
        $this->assertStringNotContainsString( '@media (min-width: 480px)', $this->css(), 'five across at 480 broke short words' );
    }

    /**
     * #4703 / #4704 — the name stays above the chips at every width. Beside
     * them, inside the wizard frame, each chip got about 67px and the
     * injury badge ran under the first chip.
     */
    public function test_the_name_stays_above_the_chips_at_every_width(): void {
        $this->assertStringNotContainsString( '.tt-avail-row', $this->mediaBlock( 768 ) );
        $this->assertStringNotContainsString( '@media (min-width: 1024px)', $this->css() );

        $this->assertSame(
            0,
            preg_match( '/@media[^{]*\{[^@]*\.tt-avail-row\s*\{/', $this->css() ),
            'no breakpoint lays the row out beside the name'
        );
    }

    /** #4703 — a longer word breaks at a syllable; anywhere is the last resort. */
    public function test_a_long_word_is_hyphenated_at_a_syllable(): void {
        $rule = $this->baseRule( '.tt-avail-seg label' );

        $this->assertStringContainsString( 'hyphens: auto;', $rule );
        $this->assertStringContainsString( 'overflow-wrap: anywhere;', $rule );
    }

    /** #4703 — three columns below 768px: at most two rows for five statuses. */
    public function test_a_phone_gets_three_columns(): void {
        $this->assertStringContainsString(
            'grid-template-columns: repeat(3, minmax(0, 1fr));',
            $this->baseRule( '.tt-avail-seg' )
        );
    }

    /**
     * #4703 — the selected button carries a real 2px border (forced-colours
     * mode keeps a border and drops a shadow), and gives the extra pixel
     * back from its padding so its size and text room stay the same.
     */
    public function test_the_selected_button_has_a_2px_border_and_keeps_its_size(): void {
        $checked = $this->baseRule( '.tt-avail-radio:checked + label' );
        $this->assertStringContainsString( 'border-width: 2px;', $checked );
        $this->assertStringContainsString( 'padding: 0.3125rem 0.1875rem;', $checked, '1px less than the 0.375rem 0.25rem base' );
        $this->assertStringNotContainsString( 'box-shadow', $checked );

        $this->assertStringContainsString( 'padding: 0.3125rem 0.4375rem;', $this->mediaBlock( 768 ), '1px less than the 0.375rem 0.5rem desktop padding' );
    }

    /**
     * #4703 — below 768px a third of a 360px row must hold "Geblesseerd":
     * narrow side padding, a 0.75rem label, and no bold on the selected one.
     * The desktop size and the bold return from 768px.
     */
    public function test_the_phone_label_is_compact_and_desktop_keeps_its_size(): void {
        $base = $this->baseRule( '.tt-avail-seg label' );
        $this->assertStringContainsString( 'padding: 0.375rem 0.25rem;', $base );
        $this->assertStringContainsString( 'font-size: 0.75rem;', $base );
        $this->assertStringNotContainsString( 'font-weight', $this->baseRule( '.tt-avail-radio:checked + label' ) );

        $block = $this->mediaBlock( 768 );
        $this->assertStringContainsString( 'font-size: 0.8125rem;', $block );
        $this->assertStringContainsString( 'font-weight: 600;', $block );
    }
}
