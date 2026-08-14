<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * #2384 — the `this_season` period pill must span the whole configured
 * season (start through the season's own end date), not clamp the upper
 * bound to today. The silent default window (seasonDefaultWindow) stays
 * today-bounded and is covered separately.
 */
final class ReportFiltersSeasonWindowTest extends WP_UnitTestCase {

    private function seedCurrentSeason( string $start, string $end ): void {
        $repo = new SeasonsRepository();
        $id   = $repo->create( [ 'name' => 'Test Season', 'start_date' => $start, 'end_date' => $end ] );
        $this->assertGreaterThan( 0, $id, 'season row created' );
        $this->assertTrue( $repo->setCurrent( $id ), 'season marked current' );
    }

    public function test_this_season_spans_full_season_even_when_end_is_in_the_future(): void {
        // Season runs a year around "today"; its end is well in the future.
        $this->seedCurrentSeason( '2026-08-01', '2027-06-30' );

        $window = ReportFilters::periodWindow( 'this_season', '2026-08-14' );

        $this->assertNotNull( $window );
        $this->assertSame( '2026-08-01', $window['from'], 'from is the season start' );
        $this->assertSame(
            '2027-06-30',
            $window['to'],
            'to is the season END, not clamped to today'
        );
    }

    public function test_returns_null_when_no_current_season_configured(): void {
        // No season seeded → caller keeps its manual From/To range.
        $this->assertNull( ReportFilters::periodWindow( 'this_season', '2026-08-14' ) );
    }
}
