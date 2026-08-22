<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SeriesPalette (#2670) — which colour a series gets on a multi-line chart,
 * and the matching swatch for the table that lists the same series.
 *
 * One place decides, so the chart and its table can never disagree about
 * whose line is whose. The caller passes a position; it gets back CSS
 * classes. No colour value ever reaches PHP — the hues live in
 * `assets/css/frontend-trend-chart.css` as `--tt-series-*`, the same
 * arrangement MeasurementLevelPalette uses, so a future SaaS front end
 * re-themes by swapping a stylesheet (CLAUDE.md §2).
 *
 * Ten hues is the ceiling on what a reader can actually tell apart on a
 * thin line, so past ten the palette repeats the hue and changes the dash
 * pattern instead: player 11 shares a colour with player 1 but is dashed,
 * player 21 is dotted. That keeps a full squad identifiable, and it keeps
 * the chart readable in greyscale print and for a colour-blind reader —
 * the same reason the Change column carries a glyph rather than colour
 * alone (#2628).
 */
final class SeriesPalette {

    /** Distinct hues before the palette starts repeating. */
    public const HUES = 10;

    /** Solid, dashed, dotted. Beyond three cycles the pattern sticks. */
    private const DASHES = 3;

    /**
     * The classes that paint one series: its hue and its dash pattern.
     * Both the SVG line and the table swatch take the same pair.
     */
    public static function classFor( int $index ): string {
        $i    = max( 0, $index );
        $hue  = ( $i % self::HUES ) + 1;
        $dash = min( self::DASHES - 1, intdiv( $i, self::HUES ) );
        return 'tt-series-' . $hue . ' is-dash-' . $dash;
    }

    /**
     * The cue that ties a table row to its line: a short segment in the
     * series' own colour and pattern.
     *
     * Hidden from assistive tech on purpose — the row already names the
     * player, and "colour 3, dashed" tells a screen-reader user nothing
     * about a chart they are not reading.
     */
    public static function swatch( int $index ): string {
        return '<span class="tt-series-swatch ' . esc_attr( self::classFor( $index ) ) . '" aria-hidden="true"></span>';
    }
}
