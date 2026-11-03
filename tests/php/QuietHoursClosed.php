<?php
namespace TT\Tests\Php;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * Takes quiet hours out of a test that is about something else.
 *
 * A message that does not bypass quiet hours is held while the academy's
 * local time is inside the window (21:00-07:00 by default). A test that
 * expects a delivery and leaves the window alone passes by day and fails
 * at night; CI runs at whatever hour it runs.
 *
 * `closeQuietHours()` sets the start and the end to the same minute. That is
 * an empty window: `QuietHoursPolicy::shouldDefer()` holds nothing at any
 * minute of the day, so the wall clock cannot decide the test. A narrow
 * window such as 03:00-03:01 is not the same thing. It holds every message
 * sent during that minute, and the nightly run starts at 03:00 UTC.
 *
 * `QuietHoursPinGuardTest` keeps one-minute windows out of the suite and
 * sends a hand-rolled empty window here.
 *
 * A test about quiet hours themselves sets its own window and freezes the
 * clock through `QuietHoursPolicy`'s constructor instead.
 */
trait QuietHoursClosed {

    private function closeQuietHours(): void {
        QueryHelpers::set_config( 'comms_quiet_hours_start', '00:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '00:00' );
    }
}
