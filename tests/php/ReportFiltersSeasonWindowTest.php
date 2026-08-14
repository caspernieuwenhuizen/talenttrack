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

    public function test_this_season_end_holds_even_when_today_is_before_it(): void {
        // Season end is in the future relative to "today"; the pill must
        // still return the season's own end, not clamp back to today.
        $this->seedCurrentSeason( '2026-08-01', '2027-06-30' );

        $window = ReportFilters::periodWindow( 'this_season', '2026-09-01' );

        $this->assertNotNull( $window );
        $this->assertSame( '2027-06-30', $window['to'] );
    }
}
