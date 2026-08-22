<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TrendChart (#2536) — a dated numeric series drawn as inline SVG.
 *
 * Server-rendered, script-free and dependency-free: the markup that leaves
 * this class is the finished chart, so it renders in the PDF/print path and
 * costs nothing against the front-end JS budget (CLAUDE.md §2). Presentation
 * rides SVG attributes and CSS classes — never an inline `style` attribute
 * (#1389).
 *
 * The component knows nothing about measurements. It takes points, an
 * optional shaded band and some labels, and returns a chart; the caller
 * decides whether a chart is the right shape for its data at all. That is
 * deliberate — a status or pass/fail measurement has no numeric axis and
 * must NOT be routed here (see FrontendMeasurementsView), and the report in
 * #2537 reuses the same component for a multi-series chart.
 *
 * Returns '' for fewer than two numeric points. A single point is not a
 * trend, and an axis frame drawn around one dot reads as missing data
 * rather than as a starting position — the caller renders a sentence
 * instead.
 */
final class TrendChart {

    /** Geometry of the drawing surface, in viewBox units. */
    private const VB_W    = 640;
    private const VB_H    = 210;
    private const PLOT_L  = 60;
    private const PLOT_R  = 600;
    private const PLOT_T  = 30;
    private const PLOT_B  = 170;

    /**
     * Render one series.
     *
     * @param array{
     *   series: array<int, array{date?: string, value?: float|int|string|null}>,
     *   unit?: string,
     *   direction?: string,
     *   band?: array{min?: float|null, max?: float|null, label?: string}|null,
     *   title?: string
     * } $args
     */
    public static function render( array $args ): string {
        $points = self::numericPoints( $args['series'] ?? [] );
        if ( count( $points ) < 2 ) return '';

        $unit  = (string) ( $args['unit'] ?? '' );
        $title = (string) ( $args['title'] ?? '' );
        $band  = is_array( $args['band'] ?? null ) ? $args['band'] : null;

        $values = array_column( $points, 'value' );
        $scale  = self::scale( $values, $band );

        $out  = '<div class="tt-trend">';
        $out .= '<svg class="tt-trend__svg" viewBox="0 0 ' . self::VB_W . ' ' . self::VB_H . '"'
            . ' preserveAspectRatio="xMidYMid meet" role="img"'
            . ' aria-label="' . esc_attr( self::describe( $points, $unit, $title ) ) . '">';

        $out .= self::band( $band, $scale );
        $out .= self::axes( $scale, $unit );
        $out .= self::plot( $points, $scale );

        $out .= '</svg>';
        $out .= '</div>';

        return $out;
    }

    /**
     * Render several series on one pair of axes — the shape #2537 needs:
     * a line per player plus the squad average over a shared date axis.
     *
     * Every series is plotted against the SAME x positions, taken from the
     * shared date list, so a player who missed a round leaves a gap in their
     * own line rather than sliding their remaining points leftwards to meet
     * the others. Sliding them would silently compare different dates.
     *
     * A series may name its own `class` — the caller's way of colouring one
     * line apart from the rest (SeriesPalette does this for #2670). The
     * component neither knows nor cares what the class means; without one,
     * every line keeps the shared `is-player` treatment.
     *
     * @param array{
     *   dates: array<int, string>,
     *   series: array<int, array{label?: string, values?: array<string, float|int|string|null>, variant?: string, class?: string}>,
     *   unit?: string,
     *   band?: array{min?: float|null, max?: float|null, label?: string}|null,
     *   title?: string
     * } $args
     */
    public static function renderMulti( array $args ): string {
        $dates = array_values( array_filter( array_map( 'strval', $args['dates'] ?? [] ) ) );
        $sets  = [];
        $all   = [];

        foreach ( (array) ( $args['series'] ?? [] ) as $set ) {
            if ( ! is_array( $set ) ) continue;
            $values = [];
            foreach ( $dates as $d ) {
                $v = $set['values'][ $d ] ?? null;
                if ( $v === null || $v === '' || ! is_numeric( $v ) ) continue;
                $values[ $d ] = (float) $v;
                $all[] = (float) $v;
            }
            if ( count( $values ) < 1 ) continue;
            $sets[] = [
                'label'   => (string) ( $set['label'] ?? '' ),
                'values'  => $values,
                'variant' => (string) ( $set['variant'] ?? 'player' ),
                'class'   => trim( (string) ( $set['class'] ?? '' ) ),
            ];
        }

        if ( count( $dates ) < 2 || $all === [] ) return '';

        $band  = is_array( $args['band'] ?? null ) ? $args['band'] : null;
        $scale = self::scale( $all, $band );
        $n     = count( $dates );

        $out  = '<div class="tt-trend">';
        $out .= '<svg class="tt-trend__svg" viewBox="0 0 ' . self::VB_W . ' ' . self::VB_H . '"'
            . ' preserveAspectRatio="xMidYMid meet" role="img"'
            . ' aria-label="' . esc_attr( self::describeMulti( $sets, $dates, (string) ( $args['title'] ?? '' ) ) ) . '">';

        $out .= self::band( $band, $scale );
        $out .= self::axes( $scale, (string) ( $args['unit'] ?? '' ) );

        foreach ( $sets as $set ) {
            $own    = $set['class'] !== '' ? ' ' . $set['class'] : '';
            $coords = [];
            foreach ( $dates as $i => $d ) {
                if ( ! isset( $set['values'][ $d ] ) ) continue;
                $coords[] = round( self::x( $i, $n ), 1 ) . ',' . round( self::y( $set['values'][ $d ], $scale ), 1 );
            }
            if ( count( $coords ) < 2 ) {
                // A single reading cannot be a line. Draw the point so the
                // player is not invisible, but never a one-point "trend".
                if ( count( $coords ) === 1 ) {
                    [ $x, $y ] = explode( ',', $coords[0] );
                    $out .= '<circle class="' . esc_attr( 'tt-trend__dot' . $own ) . '" cx="' . $x
                        . '" cy="' . $y . '" r="3"></circle>';
                }
                continue;
            }
            $cls = $set['variant'] === 'average' ? 'tt-trend__line is-average' : 'tt-trend__line is-player' . $own;
            $out .= '<polyline class="' . esc_attr( $cls ) . '" points="' . esc_attr( implode( ' ', $coords ) ) . '"></polyline>';
        }

        // Date axis, thinned exactly as the single-series chart does.
        $every = (int) max( 1, ceil( $n / 6 ) );
        foreach ( $dates as $i => $d ) {
            if ( $i !== 0 && $i !== $n - 1 && $i % $every !== 0 ) continue;
            $out .= '<text class="tt-trend__date" x="' . round( self::x( $i, $n ), 1 ) . '" y="' . ( self::PLOT_B + 18 )
                . '" text-anchor="middle">' . esc_html( self::shortDate( $d ) ) . '</text>';
        }

        $out .= '</svg></div>';
        return $out;
    }

    /**
     * Keep only points that carry a real number, preserving order. A gap in
     * the series (a missed test round) is skipped rather than plotted as
     * zero — a zero would draw a cliff that never happened.
     *
     * @param array<int, array<string, mixed>> $series
     * @return array<int, array{date: string, value: float}>
     */
    private static function numericPoints( array $series ): array {
        $out = [];
        foreach ( $series as $row ) {
            if ( ! is_array( $row ) ) continue;
            $v = $row['value'] ?? null;
            if ( $v === null || $v === '' || ! is_numeric( $v ) ) continue;
            $out[] = [
                'date'  => (string) ( $row['date'] ?? '' ),
                'value' => (float) $v,
            ];
        }
        return $out;
    }

    /**
     * Value range for the y-axis, widened to include the whole target band
     * and given headroom so no point or band edge is ever drawn outside the
     * plot area. A line that leaves the axis reads as a rendering fault.
     *
     * @param array<int, float> $values
     * @param array<string, mixed>|null $band
     * @return array{min: float, max: float}
     */
    private static function scale( array $values, ?array $band ): array {
        $min = min( $values );
        $max = max( $values );

        foreach ( [ 'min', 'max' ] as $edge ) {
            $b = $band[ $edge ] ?? null;
            if ( $b !== null && is_numeric( $b ) ) {
                $min = min( $min, (float) $b );
                $max = max( $max, (float) $b );
            }
        }

        $span = $max - $min;
        if ( $span <= 0 ) {
            // A flat series still deserves a readable axis: give it a band
            // around the value rather than dividing by zero later.
            $pad = abs( $max ) > 0 ? abs( $max ) * 0.1 : 1.0;
            return [ 'min' => $min - $pad, 'max' => $max + $pad ];
        }

        $pad = $span * 0.12;
        return [ 'min' => $min - $pad, 'max' => $max + $pad ];
    }

    /** Map a value to its y coordinate inside the plot area. */
    private static function y( float $value, array $scale ): float {
        $span = $scale['max'] - $scale['min'];
        if ( $span <= 0 ) return ( self::PLOT_T + self::PLOT_B ) / 2;
        $ratio = ( $value - $scale['min'] ) / $span;
        return self::PLOT_B - $ratio * ( self::PLOT_B - self::PLOT_T );
    }

    /** Map a point index to its x coordinate. */
    private static function x( int $i, int $n ): float {
        if ( $n < 2 ) return ( self::PLOT_L + self::PLOT_R ) / 2;
        $step = ( self::PLOT_R - self::PLOT_L - 40 ) / ( $n - 1 );
        return self::PLOT_L + 20 + $i * $step;
    }

    /**
     * The shaded "on target" band. Open-ended targets (only a minimum, or
     * only a maximum) shade to the edge of the plot, which is what an
     * open-ended target means.
     *
     * @param array<string, mixed>|null $band
     * @param array{min: float, max: float} $scale
     */
    private static function band( ?array $band, array $scale ): string {
        if ( $band === null ) return '';
        $has_min = isset( $band['min'] ) && is_numeric( $band['min'] );
        $has_max = isset( $band['max'] ) && is_numeric( $band['max'] );
        if ( ! $has_min && ! $has_max ) return '';

        $top    = $has_max ? self::y( (float) $band['max'], $scale ) : self::PLOT_T;
        $bottom = $has_min ? self::y( (float) $band['min'], $scale ) : self::PLOT_B;
        $height = max( 0.0, $bottom - $top );
        if ( $height <= 0 ) return '';

        $out = '<rect class="tt-trend__band" x="' . self::PLOT_L . '" y="' . round( $top, 1 )
            . '" width="' . ( self::PLOT_R - self::PLOT_L ) . '" height="' . round( $height, 1 ) . '"></rect>';

        $label = (string) ( $band['label'] ?? '' );
        if ( $label !== '' ) {
            // Sit the caption inside the band when it fits, just above it
            // otherwise, so it never collides with the plotted line.
            $ly = $height >= 22 ? $top + 14 : max( self::PLOT_T + 10, $top - 4 );
            $out .= '<text class="tt-trend__band-label" x="' . ( self::PLOT_L + 6 ) . '" y="' . round( $ly, 1 ) . '">'
                . esc_html( $label ) . '</text>';
        }
        return $out;
    }

    /**
     * Axis lines plus three value ticks (low / mid / high). Three is enough
     * to read the scale and few enough to stay legible at 360px.
     *
     * @param array{min: float, max: float} $scale
     */
    private static function axes( array $scale, string $unit ): string {
        $out = '<line class="tt-trend__axis" x1="' . self::PLOT_L . '" y1="' . self::PLOT_T
            . '" x2="' . self::PLOT_L . '" y2="' . self::PLOT_B . '"></line>';
        $out .= '<line class="tt-trend__axis" x1="' . self::PLOT_L . '" y1="' . self::PLOT_B
            . '" x2="' . self::PLOT_R . '" y2="' . self::PLOT_B . '"></line>';

        // Axis labels get ONE precision for the whole chart, derived from the
        // span. Formatting each tick independently let the padded bounds show
        // through as "168,96" — which reads as a measurement rather than as a
        // scale marker.
        $span     = $scale['max'] - $scale['min'];
        $decimals = $span >= 10 ? 0 : ( $span >= 1 ? 1 : 2 );

        $mid = ( $scale['min'] + $scale['max'] ) / 2;
        foreach ( [ $scale['max'], $mid, $scale['min'] ] as $v ) {
            $y = self::y( (float) $v, $scale );
            if ( $v !== $scale['min'] ) {
                $out .= '<line class="tt-trend__grid" x1="' . self::PLOT_L . '" y1="' . round( $y, 1 )
                    . '" x2="' . self::PLOT_R . '" y2="' . round( $y, 1 ) . '"></line>';
            }
            $out .= '<text class="tt-trend__tick" x="' . ( self::PLOT_L - 6 ) . '" y="' . round( $y + 4, 1 )
                . '" text-anchor="end">' . esc_html( number_format_i18n( round( (float) $v, $decimals ), $decimals ) ) . '</text>';
        }

        if ( $unit !== '' ) {
            $out .= '<text class="tt-trend__unit" x="' . self::PLOT_L . '" y="' . ( self::PLOT_T - 12 ) . '">'
                . esc_html( $unit ) . '</text>';
        }
        return $out;
    }

    /**
     * The line, its points, each point's value, and the date axis. Date
     * labels thin out beyond six points so they never overlap; the first
     * and last are always kept because they bound the window the reader is
     * being asked to judge.
     *
     * @param array<int, array{date: string, value: float}> $points
     * @param array{min: float, max: float} $scale
     */
    private static function plot( array $points, array $scale ): string {
        $n = count( $points );
        $every = (int) max( 1, ceil( $n / 6 ) );

        $coords = [];
        foreach ( $points as $i => $p ) {
            $coords[] = round( self::x( $i, $n ), 1 ) . ',' . round( self::y( $p['value'], $scale ), 1 );
        }

        $out = '<polyline class="tt-trend__line" points="' . esc_attr( implode( ' ', $coords ) ) . '"></polyline>';

        foreach ( $points as $i => $p ) {
            $x = round( self::x( $i, $n ), 1 );
            $y = round( self::y( $p['value'], $scale ), 1 );
            $is_last = $i === $n - 1;

            $out .= '<circle class="tt-trend__dot' . ( $is_last ? ' is-latest' : '' ) . '" cx="' . $x
                . '" cy="' . $y . '" r="' . ( $is_last ? '5' : '4' ) . '"></circle>';

            if ( $is_last || $i === 0 || $n <= 6 ) {
                $out .= '<text class="tt-trend__value" x="' . $x . '" y="' . round( $y - 10, 1 )
                    . '" text-anchor="middle">' . esc_html( self::num( $p['value'] ) ) . '</text>';
            }
            if ( $is_last || $i === 0 || $i % $every === 0 ) {
                $out .= '<text class="tt-trend__date" x="' . $x . '" y="' . ( self::PLOT_B + 18 )
                    . '" text-anchor="middle">' . esc_html( self::shortDate( $p['date'] ) ) . '</text>';
            }
        }
        return $out;
    }

    /**
     * Locale-aware number with at most two decimals and no trailing zeroes,
     * so 1.94 stays "1,94" in Dutch while 168.0 reads "168" rather than
     * "168,00".
     */
    private static function num( float $v ): string {
        $rounded = round( $v, 2 );
        $decimals = ( floor( $rounded ) === $rounded ) ? 0 : ( round( $rounded, 1 ) === $rounded ? 1 : 2 );
        return number_format_i18n( $rounded, $decimals );
    }

    /**
     * Text alternative for a multi-series chart: how many series over which
     * window, and the average's movement when one is present. Naming twenty
     * players would be unusable read aloud — the table below the chart is
     * the accessible detail, and it is real markup.
     *
     * @param array<int, array{label: string, values: array<string, float>, variant: string}> $sets
     * @param array<int, string> $dates
     */
    private static function describeMulti( array $sets, array $dates, string $title ): string {
        $players = 0;
        foreach ( $sets as $s ) {
            if ( $s['variant'] !== 'average' ) $players++;
        }
        return trim( sprintf(
            /* translators: 1: test name, 2: number of players plotted, 3: first date, 4: last date */
            __( '%1$s: %2$d players plotted from %3$s to %4$s. The table below lists every value.', 'talenttrack' ),
            $title,
            $players,
            self::shortDate( $dates[0] ),
            self::shortDate( $dates[ count( $dates ) - 1 ] )
        ) );
    }

    private static function shortDate( string $date ): string {
        $ts = $date !== '' ? strtotime( $date ) : false;
        return $ts ? date_i18n( 'j M', $ts ) : '';
    }

    /**
     * Text alternative for the chart. A screen reader gets the first and
     * last value with their dates and the number of points — the same
     * summary a sighted reader takes from the shape.
     *
     * @param array<int, array{date: string, value: float}> $points
     */
    private static function describe( array $points, string $unit, string $title ): string {
        $first = $points[0];
        $last  = $points[ count( $points ) - 1 ];
        $unit  = $unit !== '' ? ' ' . $unit : '';

        return trim( sprintf(
            /* translators: 1: test name, 2: number of results, 3: first value, 4: first date, 5: latest value, 6: latest date */
            __( '%1$s: %2$d results, from %3$s on %4$s to %5$s on %6$s.', 'talenttrack' ),
            $title,
            count( $points ),
            self::num( $first['value'] ) . $unit,
            self::shortDate( $first['date'] ),
            self::num( $last['value'] ) . $unit,
            self::shortDate( $last['date'] )
        ) );
    }
}
