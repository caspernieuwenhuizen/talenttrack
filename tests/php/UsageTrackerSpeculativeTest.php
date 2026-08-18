<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Usage\UsageTracker;

/**
 * #2517 — a speculatively fetched page is not a visit.
 *
 * Hover prefetch renders the page server-side for real, so without this
 * guard every hovered link would write a `frontend_view` row: active-user
 * counts, the daily-active chart and the per-view tallies would all inflate
 * with pages nobody opened. That is a worse defect than the page-load flash
 * prefetching was added to hide, so it is pinned here.
 *
 * The asymmetry matters and is tested in both directions: a false positive
 * loses one usage row, a false negative silently corrupts every usage figure.
 */
final class UsageTrackerSpeculativeTest extends WP_UnitTestCase {

    private const PROBE = 'speculative_probe';

    private int $uid = 0;

    public function set_up(): void {
        parent::set_up();
        $this->uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->clearHeaders();
    }

    public function tear_down(): void {
        $this->clearHeaders();
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_usage_events', [ 'event_type' => self::PROBE ] );
        parent::tear_down();
    }

    private function clearHeaders(): void {
        unset( $_SERVER['HTTP_SEC_PURPOSE'], $_SERVER['HTTP_PURPOSE'], $_SERVER['HTTP_X_MOZ'] );
    }

    private function rows(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_usage_events WHERE user_id = %d AND event_type = %s",
            $this->uid,
            self::PROBE
        ) );
    }

    /** @dataProvider speculativeHeaders */
    public function test_speculative_requests_are_not_recorded( string $header, string $value ): void {
        $_SERVER[ $header ] = $value;

        $before = $this->rows();
        UsageTracker::record( $this->uid, self::PROBE, 'x' );

        $this->assertTrue( UsageTracker::isSpeculativeRequest(), "{$header}: {$value} should read as speculative" );
        $this->assertSame( $before, $this->rows(), 'a speculative render must not be recorded as a visit' );
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function speculativeHeaders(): array {
        return [
            'Sec-Purpose prefetch'   => [ 'HTTP_SEC_PURPOSE', 'prefetch' ],
            // The form Chrome actually sends for cross-site prefetch.
            'Sec-Purpose with attrs' => [ 'HTTP_SEC_PURPOSE', 'prefetch;anonymous-client-ip' ],
            'Sec-Purpose prerender'  => [ 'HTTP_SEC_PURPOSE', 'prerender' ],
            'legacy Purpose'         => [ 'HTTP_PURPOSE', 'prefetch' ],
            'legacy Firefox X-Moz'   => [ 'HTTP_X_MOZ', 'prefetch' ],
        ];
    }

    public function test_a_real_navigation_is_still_recorded(): void {
        // The direction that matters most: over-eager detection would
        // silently stop counting genuine visits.
        $before = $this->rows();
        UsageTracker::record( $this->uid, self::PROBE, 'x' );

        $this->assertFalse( UsageTracker::isSpeculativeRequest() );
        $this->assertSame( $before + 1, $this->rows() );
    }

    public function test_a_non_prefetch_sec_purpose_does_not_suppress(): void {
        $_SERVER['HTTP_SEC_PURPOSE'] = 'navigate';

        $before = $this->rows();
        UsageTracker::record( $this->uid, self::PROBE, 'x' );

        $this->assertFalse( UsageTracker::isSpeculativeRequest() );
        $this->assertSame( $before + 1, $this->rows() );
    }
}
