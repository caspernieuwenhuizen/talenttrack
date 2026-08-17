<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Usage\UsageTracker;

/**
 * #2444 — usage-stats windows were built in UTC and compared against a
 * site-local column.
 *
 * `tt_usage_events.created_at` is written with `current_time( 'mysql' )`, i.e.
 * site-local, but every boundary was built with `gmdate()`, which WordPress
 * pins to UTC. On a CEST install the window started two hours late: events in
 * the 00:00–02:00 band of the oldest day fell outside it, and the same band on
 * the day before fell inside. The daily-active-users chart and the day
 * drill-down disagreed at those edges for the same reason.
 *
 * A non-UTC site timezone is the only setting where the bug is observable, so
 * these tests pin one — same approach as TTDateTimezoneTest (#2437).
 */
final class UsageStatsTimezoneTest extends WP_UnitTestCase {

    private string $prev_tz = '';

    public function set_up(): void {
        parent::set_up();
        $this->prev_tz = (string) get_option( 'timezone_string' );
        update_option( 'timezone_string', 'Europe/Amsterdam' );
    }

    public function tear_down(): void {
        update_option( 'timezone_string', $this->prev_tz );
        parent::tear_down();
    }

    /**
     * The regression: the cutoff must be the same wall clock the events are
     * stamped in, not the UTC instant.
     */
    public function test_cutoff_is_site_local_not_utc(): void {
        $seconds = 7 * DAY_IN_SECONDS;
        $this->assertSame(
            wp_date( 'Y-m-d H:i:s', time() - $seconds ),
            UsageTracker::cutoff( $seconds )
        );
    }

    public function test_cutoff_differs_from_the_utc_boundary_on_an_offset_install(): void {
        // If these ever match on Europe/Amsterdam, the helper has silently
        // reverted to UTC and the window is shifted again.
        $seconds = 7 * DAY_IN_SECONDS;
        $this->assertNotSame(
            gmdate( 'Y-m-d H:i:s', time() - $seconds ),
            UsageTracker::cutoff( $seconds )
        );
    }

    public function test_cutoff_matches_the_convention_created_at_is_written_in(): void {
        // `record()` stamps rows with current_time( 'mysql' ). A zero-second
        // cutoff must land on that same clock. Compared with a tolerance
        // rather than as strings so the assertion cannot straddle a tick.
        $written = (int) strtotime( (string) current_time( 'mysql' ) );
        $bound   = (int) strtotime( UsageTracker::cutoff( 0 ) );

        $this->assertLessThanOrEqual(
            2,
            abs( $written - $bound ),
            'cutoff(0) drifted from the clock created_at is written in — the window is offset again.'
        );
    }

    public function test_day_start_is_local_midnight(): void {
        $this->assertSame( wp_date( 'Y-m-d 00:00:00' ), UsageTracker::dayStart( 0 ) );
        $this->assertStringEndsWith( ' 00:00:00', UsageTracker::dayStart( 6 ) );
    }

    public function test_day_start_six_days_back_is_the_local_calendar_day(): void {
        $this->assertSame(
            wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ) . ' 00:00:00',
            UsageTracker::dayStart( 6 )
        );
    }

    public function test_day_returns_the_local_calendar_day(): void {
        $this->assertSame( wp_date( 'Y-m-d' ), UsageTracker::day() );
        $this->assertSame( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ), UsageTracker::day( -1 ) );
        $this->assertSame( wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ), UsageTracker::day( 1 ) );
    }

    /**
     * The chart's zero-fill keys must line up with the site-local
     * `DATE(created_at)` buckets the query groups by, or the edge days of the
     * series silently drop their rows.
     */
    public function test_daily_series_keys_span_the_local_window_and_end_today(): void {
        $days   = 7;
        $series = UsageTracker::dailyActiveUsers( $days );
        $keys   = array_keys( $series );

        $this->assertCount( $days, $series );
        $this->assertSame( wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ), $keys[0] );
        $this->assertSame( wp_date( 'Y-m-d' ), $keys[ $days - 1 ] );
    }

    public function test_daily_series_first_key_matches_its_own_cutoff_day(): void {
        // The fill loop and the SQL cutoff are derived separately; if they
        // ever drift apart the first bucket stops matching the query window.
        $days   = 30;
        $series = UsageTracker::dailyActiveUsers( $days );
        $first  = (string) array_key_first( $series );

        $this->assertSame( substr( UsageTracker::dayStart( $days - 1 ), 0, 10 ), $first );
    }
}
