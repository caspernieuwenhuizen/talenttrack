<?php
namespace TT\Tests\Php;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\CommsService;
use TT\Modules\Comms\Dispatch\CommsDispatcher;

/**
 * Puts a Comms test at a fixed instant inside quiet hours.
 *
 * No quiet-hours window covers the whole day. `00:00-23:59` is open during
 * the minute 23:59, and a test that expects a hold fails when it runs then.
 * So a test about quiet hours sets the default window and freezes the clock
 * inside it, instead of trying to make the window cover the time it runs.
 *
 * `freezeCommsClockAt()` sets the window to 21:00-07:00 and freezes the
 * clock the dispatcher sends through (`CommsDispatcher::useClock()`) at the
 * given local time today. `commsService()` is the same service, for a test
 * that sends through `CommsService` directly. The clock is released after
 * every test.
 *
 * A test that only needs quiet hours out of the way uses `QuietHoursClosed`.
 */
trait FrozenCommsClock {

    /**
     * @param string $time Local wall-clock time, `H:i:s`; 21:00:00-06:59:59 is inside the window.
     */
    private function freezeCommsClockAt( string $time ): void {
        QueryHelpers::set_config( 'comms_quiet_hours_start', '21:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '07:00' );

        $day = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
        $at  = ( new \DateTimeImmutable( $day . ' ' . $time, wp_timezone() ) )->getTimestamp();

        CommsDispatcher::useClock( static fn (): int => $at );
    }

    private function commsService(): CommsService {
        return CommsDispatcher::service();
    }

    /** @after */
    public function releaseCommsClock(): void {
        CommsDispatcher::useClock( null );
    }
}
