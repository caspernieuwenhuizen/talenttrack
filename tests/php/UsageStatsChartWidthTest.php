<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4766 — the usage-stats chart never widens the page.
 *
 * Chart.js writes the canvas width in px when it draws and only corrects it
 * when its resize observer fires. A chart drawn while the page was briefly
 * wider (WordPress's admin bar declares min-width: 600px) kept that width,
 * and the mobile gate measured the page 10px past a 320px phone. The canvas
 * is capped at its container.
 *
 * A stylesheet assertion guards this, as in `TapTargetFloorTest`: the defect
 * is a missing declaration, and the timing that exposes it is not something
 * a test can schedule reliably.
 */
final class UsageStatsChartWidthTest extends WP_UnitTestCase {

    private function rule( string $selector ): string {
        $css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/frontend-usage-stats.css' );
        $at  = strpos( $css, "\n" . $selector . ' {' );

        $this->assertNotFalse( $at, 'No `' . $selector . '` rule in frontend-usage-stats.css' );

        $open  = strpos( $css, '{', (int) $at );
        $close = strpos( $css, '}', (int) $open );

        return substr( $css, (int) $open + 1, (int) $close - (int) $open - 1 );
    }

    public function test_the_canvas_is_capped_at_its_container(): void {
        $this->assertStringContainsString( 'max-width: 100%', $this->rule( '.tt-dashboard .tt-usage-chart canvas' ) );
    }

    public function test_the_container_can_shrink(): void {
        $this->assertStringContainsString( 'min-width: 0', $this->rule( '.tt-dashboard .tt-usage-chart' ) );
    }
}
