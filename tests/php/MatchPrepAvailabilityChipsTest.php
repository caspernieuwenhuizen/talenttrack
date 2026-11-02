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

    public function test_every_status_sits_on_one_line_from_480px(): void {
        $block = $this->mediaBlock( 480 );

        $this->assertStringContainsString( 'grid-auto-flow: column;', $block );
        $this->assertStringContainsString( 'grid-auto-columns: minmax(0, 1fr);', $block );
        // One column per status, not a fixed five: the lookup is editable.
        $this->assertStringNotContainsString( 'repeat(5', $block );
    }

    /**
     * #4703 / #4704 — the name stays above the chips at every width. Beside
     * them, inside the wizard frame, each chip got about 67px and the
     * injury badge ran under the first chip.
     */
    public function test_the_name_stays_above_the_chips_at_every_width(): void {
        $this->assertStringNotContainsString( '.tt-avail-row', $this->mediaBlock( 480 ) );
        $this->assertStringNotContainsString( '@media (min-width: 1024px)', $this->css() );

        $this->assertSame(
            0,
            preg_match( '/@media[^{]*\{[^@]*\.tt-avail-row\s*\{/', $this->css() ),
            'no breakpoint lays the row out beside the name'
        );
    }

    /** #4703 — a label breaks only when the word cannot fit at all. */
    public function test_labels_are_not_hyphenated_automatically(): void {
        $rule = $this->baseRule( '.tt-avail-seg label' );

        $this->assertStringContainsString( 'hyphens: manual;', $rule );
        $this->assertStringNotContainsString( 'hyphens: auto;', $rule );
    }

    public function test_the_base_still_wraps_on_a_small_phone(): void {
        $this->assertStringContainsString(
            'grid-template-columns: repeat(auto-fit, minmax(76px, 1fr));',
            $this->baseRule( '.tt-avail-seg' )
        );
    }
}
