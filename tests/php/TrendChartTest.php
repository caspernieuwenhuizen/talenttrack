<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\TrendChart;

/**
 * #2536 — the shared trend chart. The contract that matters is that nothing
 * is ever drawn outside the plot area (a line leaving the axis reads as a
 * rendering fault, not as data), that a single point is refused, and that
 * the markup carries no inline `style` (#1389).
 */
final class TrendChartTest extends WP_UnitTestCase {

    /** @return array<int, array{date: string, value: float|null}> */
    private function series( array $values ): array {
        $out = [];
        $day = 1;
        foreach ( $values as $v ) {
            $out[] = [ 'date' => sprintf( '2026-01-%02d', $day ), 'value' => $v ];
            $day += 7;
        }
        return $out;
    }

    public function test_fewer_than_two_points_renders_nothing(): void {
        $this->assertSame( '', TrendChart::render( [ 'series' => [] ] ) );
        $this->assertSame( '', TrendChart::render( [ 'series' => $this->series( [ 1.94 ] ) ] ) );
        // A lone numeric point among nulls is still a lone point.
        $this->assertSame( '', TrendChart::render( [
            'series' => [
                [ 'date' => '2026-01-01', 'value' => null ],
                [ 'date' => '2026-01-08', 'value' => 1.94 ],
            ],
        ] ) );
    }

    public function test_renders_svg_without_inline_style(): void {
        $svg = TrendChart::render( [
            'series' => $this->series( [ 2.05, 2.01, 1.97, 1.94 ] ),
            'unit'   => 's',
            'title'  => 'Sprint 10 m',
        ] );
        $this->assertStringContainsString( '<svg', $svg );
        $this->assertStringContainsString( 'tt-trend__line', $svg );
        $this->assertStringNotContainsString( 'style=', $svg, 'inline styles are barred by the #1389 gate' );
        $this->assertStringContainsString( 'role="img"', $svg );
        $this->assertStringContainsString( 'aria-label="', $svg, 'the chart needs a text alternative' );
    }

    /**
     * The bug this guards against was live in the mockup: a series whose
     * extreme sat above the axis maximum drew a line out of the frame.
     */
    public function test_every_point_stays_inside_the_plot_area(): void {
        $svg = TrendChart::render( [
            'series' => $this->series( [ 2.08, 2.09, 2.10, 2.11 ] ),
            'band'   => [ 'min' => null, 'max' => 1.95 ],
        ] );
        $this->assertNotSame( '', $svg );

        preg_match( '/class="tt-trend__line" points="([^"]+)"/', $svg, $m );
        $this->assertNotEmpty( $m, 'the polyline must be present' );

        foreach ( explode( ' ', html_entity_decode( $m[1] ) ) as $pair ) {
            [ $x, $y ] = array_map( 'floatval', explode( ',', $pair ) );
            $this->assertGreaterThanOrEqual( 60.0, $x, 'x inside the left axis' );
            $this->assertLessThanOrEqual( 600.0, $x, 'x inside the right edge' );
            $this->assertGreaterThanOrEqual( 30.0, $y, 'y below the plot top' );
            $this->assertLessThanOrEqual( 170.0, $y, 'y above the plot bottom' );
        }
    }

    /**
     * The band widens the axis instead of being clipped by it — otherwise a
     * target far outside the player's range silently disappears.
     */
    public function test_band_outside_the_value_range_is_still_drawn(): void {
        $svg = TrendChart::render( [
            'series' => $this->series( [ 30.0, 31.0, 32.0 ] ),
            'band'   => [ 'min' => 40.0, 'max' => null ],
            'title'  => 'Jump',
        ] );
        $this->assertStringContainsString( 'tt-trend__band', $svg );

        preg_match( '/class="tt-trend__band" x="\d+" y="([\d.]+)" width="\d+" height="([\d.]+)"/', $svg, $m );
        $this->assertNotEmpty( $m, 'the band rect must be present' );
        $this->assertGreaterThanOrEqual( 30.0, (float) $m[1] );
        $this->assertLessThanOrEqual( 170.0, (float) $m[1] + (float) $m[2], 'the band stays inside the plot' );
    }

    public function test_flat_series_still_renders(): void {
        $svg = TrendChart::render( [ 'series' => $this->series( [ 50.0, 50.0, 50.0 ] ) ] );
        $this->assertNotSame( '', $svg, 'an unchanged series is a legitimate trend' );
        $this->assertStringContainsString( 'tt-trend__line', $svg );
    }

    /** A gap must be skipped, never plotted as zero. */
    public function test_null_values_are_skipped_not_zeroed(): void {
        $svg = TrendChart::render( [
            'series' => [
                [ 'date' => '2026-01-01', 'value' => 160.0 ],
                [ 'date' => '2026-02-01', 'value' => null ],
                [ 'date' => '2026-03-01', 'value' => 166.0 ],
            ],
        ] );
        preg_match( '/class="tt-trend__line" points="([^"]+)"/', $svg, $m );
        $this->assertCount( 2, explode( ' ', html_entity_decode( $m[1] ) ), 'the gap contributes no point' );
    }
}
