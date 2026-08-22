<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\SeriesPalette;

/**
 * #2670 — the series palette. What matters is that the first ten positions
 * are ten different hues, that position eleven repeats a hue but changes the
 * pattern (so it is still a distinct line), and that the chart and the table
 * are handed the identical classes for the same position.
 */
final class SeriesPaletteTest extends WP_UnitTestCase {

    public function test_first_ten_positions_are_ten_distinct_hues(): void {
        $hues = [];
        for ( $i = 0; $i < SeriesPalette::HUES; $i++ ) {
            $hues[] = SeriesPalette::classFor( $i );
        }
        $this->assertCount( SeriesPalette::HUES, array_unique( $hues ) );
        $this->assertSame( 'tt-series-1 is-dash-0', $hues[0] );
        $this->assertSame( 'tt-series-10 is-dash-0', $hues[9] );
    }

    /**
     * Past ten the hue comes round again — that is the point of the dash
     * pattern. Player 11 must not be indistinguishable from player 1.
     */
    public function test_second_pass_reuses_the_hue_with_a_new_pattern(): void {
        $this->assertSame( 'tt-series-1 is-dash-1', SeriesPalette::classFor( 10 ) );
        $this->assertSame( 'tt-series-1 is-dash-2', SeriesPalette::classFor( 20 ) );
        $this->assertSame( 'tt-series-5 is-dash-1', SeriesPalette::classFor( 14 ) );
        $this->assertNotSame( SeriesPalette::classFor( 0 ), SeriesPalette::classFor( 10 ) );
    }

    /**
     * A squad past thirty runs out of patterns; the classes repeat rather
     * than pointing at a `is-dash-3` rule that does not exist.
     */
    public function test_pattern_sticks_at_the_last_one(): void {
        $this->assertSame( 'tt-series-1 is-dash-2', SeriesPalette::classFor( 30 ) );
        $this->assertSame( 'tt-series-1 is-dash-2', SeriesPalette::classFor( 40 ) );
    }

    public function test_negative_position_falls_back_to_the_first_series(): void {
        $this->assertSame( 'tt-series-1 is-dash-0', SeriesPalette::classFor( -3 ) );
    }

    /** The table's cue must be the same colour and pattern as the line. */
    public function test_swatch_carries_the_same_classes_and_is_hidden_from_screen_readers(): void {
        $swatch = SeriesPalette::swatch( 12 );
        $this->assertStringContainsString( SeriesPalette::classFor( 12 ), $swatch );
        $this->assertStringContainsString( 'tt-series-swatch', $swatch );
        $this->assertStringContainsString( 'aria-hidden="true"', $swatch );
        $this->assertStringNotContainsString( 'style=', $swatch, 'inline styles are barred by the #1389 gate' );
    }
}
