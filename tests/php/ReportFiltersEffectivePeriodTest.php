<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\ReportFilters;

/**
 * #3293 — the period the chrome shows has to be the period the query ran on.
 *
 * Nine surfaces resolve their window as "a manual From/To beats the period
 * pill", then handed the bar the RAW `?period=` anyway. The pill kept reading
 * "This month" while the grid queried the user's own window — the chrome
 * contradicting the data, with nothing to say which was true.
 *
 * Two views carried a private copy of this reconciliation that tested
 * `$period !== ''` first, so it only blanked the pill when no explicit
 * `?period=` was in the URL — the one case where there is nothing to blank,
 * because the pill is only ever active when a period param put it there. The
 * order of the first two checks is the entire fix, which is why it is pinned
 * here rather than left to the call sites.
 */
final class ReportFiltersEffectivePeriodTest extends WP_UnitTestCase {

    /** The reported case: an explicit period AND manual dates. */
    public function test_manual_dates_beat_an_explicit_period(): void {
        $this->assertSame(
            '',
            ReportFilters::effectivePeriod( 'this_month', true, true, '2026-03-01', '2026-03-31' ),
            'the query ran on the manual window, so no pill describes it'
        );
    }

    /** Either end alone is still a manual window. */
    public function test_one_manual_end_is_enough(): void {
        $this->assertSame( '', ReportFilters::effectivePeriod( 'this_month', true, false, '2026-03-01', '2026-06-30' ) );
        $this->assertSame( '', ReportFilters::effectivePeriod( 'this_month', false, true, '2026-01-01', '2026-03-31' ) );
    }

    public function test_manual_dates_alone_are_a_custom_range(): void {
        $this->assertSame( '', ReportFilters::effectivePeriod( '', true, true, '2026-03-01', '2026-03-31' ) );
    }

    public function test_an_explicit_period_alone_is_that_period(): void {
        $this->assertSame( 'this_month', ReportFilters::effectivePeriod( 'this_month', false, false, '2026-03-01', '2026-03-31' ) );
        $this->assertSame( 'last_week', ReportFilters::effectivePeriod( 'last_week', false, false, '', '' ) );
    }

    /**
     * #2136's behaviour, preserved: the seeded season default carries no
     * `?period=` but should read as the season pill, not "Custom range".
     */
    public function test_the_seeded_season_window_reads_as_the_season_pill(): void {
        $season = ReportFilters::periodWindow( 'this_season', gmdate( 'Y-m-d' ) );
        $this->assertNotNull( $season );

        $this->assertSame(
            'this_season',
            ReportFilters::effectivePeriod( '', false, false, $season['from'], $season['to'] )
        );
    }

    /** A window matching nothing, with no params, is custom. */
    public function test_an_unrecognised_window_is_blank(): void {
        $this->assertSame( '', ReportFilters::effectivePeriod( '', false, false, '2019-01-01', '2019-02-01' ) );
    }

    // ---- the summary chip ------------------------------------------------

    /** With a pill active the pill describes the window; a chip would repeat it. */
    public function test_no_range_chip_when_a_pill_is_active(): void {
        $this->assertNull( ReportFilters::customRangeChip( 'this_month', '2026-03-01', '2026-03-31' ) );
    }

    /** The untouched season default is not a filter the reader chose. */
    public function test_no_range_chip_for_the_season_default(): void {
        $defaults = ReportFilters::seasonDefaultWindow();

        $this->assertNull(
            ReportFilters::customRangeChip( '', (string) $defaults['from'], (string) $defaults['to'] )
        );
    }

    /**
     * The gap the seven unreconciled surfaces had: a reader who set only a
     * From/To saw "Filters" with no badge and no chip over a filtered grid.
     */
    public function test_a_custom_window_yields_a_chip(): void {
        $chip = ReportFilters::customRangeChip( '', '2026-03-01', '2026-03-31' );

        $this->assertNotNull( $chip );
        $this->assertStringContainsString( '2026-03-01', (string) $chip );
        $this->assertStringContainsString( '2026-03-31', (string) $chip );
    }

    /** One open end still names the bound that was set. */
    public function test_a_half_open_window_names_the_end_that_is_set(): void {
        $this->assertSame( '2026-03-01', ReportFilters::customRangeChip( '', '2026-03-01', '' ) );
        $this->assertSame( '2026-03-31', ReportFilters::customRangeChip( '', '', '2026-03-31' ) );
    }

    public function test_no_chip_for_an_empty_window(): void {
        $this->assertNull( ReportFilters::customRangeChip( '', '', '' ) );
    }
}
