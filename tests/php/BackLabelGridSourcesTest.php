<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\BackLabelResolver;

/**
 * #4388 — a destination opened from the minutes grid or the attendance
 * grid names the grid in its back pill, with the grid's own breadcrumb
 * title, instead of the bare "Back".
 *
 * The grids carry a `team_id`, not an `id`, so the label is list-level:
 * no team name is resolved for them.
 */
final class BackLabelGridSourcesTest extends WP_UnitTestCase {

    public function test_minutes_grid_names_itself(): void {
        $label = BackLabelResolver::labelFor( 'https://example.org/dashboard/?tt_view=minutes-grid&team_id=3&date_from=2026-09-01' );
        $this->assertSame( 'Back to Minutes + statistics', $label );
    }

    public function test_attendance_grid_names_itself(): void {
        $label = BackLabelResolver::labelFor( 'https://example.org/dashboard/?tt_view=attendance-grid&team_id=3' );
        $this->assertSame( 'Back to Attendance grid', $label );
    }

    public function test_an_unknown_view_still_falls_back_to_back(): void {
        $label = BackLabelResolver::labelFor( 'https://example.org/dashboard/?tt_view=no-such-view' );
        $this->assertSame( 'Back', $label );
    }
}
