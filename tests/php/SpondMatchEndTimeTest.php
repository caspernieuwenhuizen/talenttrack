<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Spond\SpondSync;

/**
 * #2389 — Spond match events frequently omit an end time, which left the
 * synced `end_time` blank for matches (trainings, which carry ends, looked
 * right — hence "only wrong for matches"). The kick-off + 105 min default
 * (#1863) was wired into the create wizard only, never the sync. These
 * tests pin the sync-layer mapping: a match with no Spond end defaults to
 * kick-off + 105 min, a real Spond end always wins, and non-match types
 * never get a defaulted end.
 */
final class SpondMatchEndTimeTest extends WP_UnitTestCase {

    /** @return array<string,string|null> */
    private function timeColumns( string $type, string $start, string $end, string $meet ): array {
        $m = new \ReflectionMethod( SpondSync::class, 'timeColumns' );
        $m->setAccessible( true );
        return $m->invoke( null, $type, $start, $end, $meet );
    }

    public function test_match_without_spond_end_defaults_to_kickoff_plus_105(): void {
        $cols = $this->timeColumns( 'game', '14:00:00', '', '13:00:00' );
        $this->assertSame( '15:45:00', $cols['end_time'], 'match end defaults to kick-off + 105 min' );
        $this->assertSame( '14:00:00', $cols['kickoff_time'] );
    }

    public function test_real_spond_end_always_wins(): void {
        $cols = $this->timeColumns( 'game', '14:00:00', '16:30:00', '13:00:00' );
        $this->assertSame( '16:30:00', $cols['end_time'], 'a real Spond end is never overwritten by the default' );
    }

    public function test_non_match_type_never_gets_a_defaulted_end(): void {
        $cols = $this->timeColumns( 'training', '18:00:00', '', '' );
        $this->assertNull( $cols['end_time'], 'trainings keep a blank end blank' );
        $this->assertArrayNotHasKey( 'kickoff_time', $cols, 'trainings carry no kickoff column' );
    }

    public function test_late_kickoff_end_wraps_past_midnight(): void {
        // #4529 — 22:30 + 105 min wraps to 00:15 the next day, no longer
        // clamped to 23:59, and reads as the full 105 minutes.
        $cols = $this->timeColumns( 'game', '22:30:00', '', '' );
        $this->assertSame( '00:15:00', $cols['end_time'] );
        $this->assertSame( 105, \TT\Modules\Activities\Services\ActivityTimeWindow::minutes( '22:30:00', (string) $cols['end_time'] ) );

        $cols = $this->timeColumns( 'game', '18:00:00', '', '' );
        $this->assertSame( '19:45:00', $cols['end_time'], 'a same-day default is unchanged' );
    }

    public function test_match_with_no_start_gets_no_defaulted_end(): void {
        $cols = $this->timeColumns( 'game', '', '', '' );
        $this->assertNull( $cols['end_time'], 'no start means no basis for a default end' );
    }
}
