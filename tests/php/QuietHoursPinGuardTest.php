<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4846: no test pins quiet hours to a window that the wall clock can open.
 *
 * Ten test files pinned the window "shut" at 03:00-03:01. That is a real
 * one-minute window, and the nightly CI run starts at 03:00 UTC. A message
 * sent in that minute is held, nothing reaches the channel, and the tests
 * fail for no real reason.
 *
 * Two rules, over every literal window a test sets:
 *
 * - No one-minute window. It holds every send during that minute.
 * - An empty window (start equals end) comes from `QuietHoursClosed`, not
 *   a hand-rolled pair, so there is one fixture to read and to trust.
 *
 * A test about quiet hours themselves sets a wider window and freezes the
 * clock through `QuietHoursPolicy`'s constructor. Neither rule touches that.
 */
final class QuietHoursPinGuardTest extends WP_UnitTestCase {

    use QuietHoursClosed;

    private const FIXTURE = 'QuietHoursClosed.php';

    /**
     * The fixture on a frozen clock: nothing is held at any second checked,
     * the 03:00 run start and both sides of midnight included, nor at any
     * minute of the day.
     */
    public function test_closed_quiet_hours_hold_nothing_at_any_time(): void {
        $this->closeQuietHours();
        $request = new \TT\Modules\Comms\Domain\CommsRequest( 'notification', \TT\Modules\Comms\Domain\MessageType::NOTIFICATION, 1, 0, [] );
        $this->assertFalse( \TT\Modules\Comms\Domain\MessageType::bypassesQuietHours( \TT\Modules\Comms\Domain\MessageType::NOTIFICATION ), 'Precondition: notifications are subject to quiet hours.' );

        $day = ( new \DateTimeImmutable( '2026-09-14 00:00:00', wp_timezone() ) )->getTimestamp();
        $instants = [ '03:00:04' => $day + 3 * 3600 + 4, '23:59:59' => $day + 86399, '00:00:01' => $day + 86401 ];
        for ( $minute = 0; $minute < 1440; $minute++ ) $instants[ sprintf( '%02d:%02d', intdiv( $minute, 60 ), $minute % 60 ) ] = $day + $minute * 60;

        foreach ( $instants as $label => $at ) {
            $policy = new \TT\Modules\Comms\QuietHours\QuietHoursPolicy( static fn (): int => $at );
            $this->assertFalse( $policy->shouldDefer( $request ), "held at {$label}" );
        }
    }

    public function test_no_test_pins_a_window_the_clock_can_open(): void {
        $found = [];
        foreach ( glob( __DIR__ . '/*.php' ) ?: [] as $path ) {
            $file = basename( $path );
            if ( $file === self::FIXTURE || $file === basename( __FILE__ ) ) continue;
            foreach ( self::violations( (string) file_get_contents( $path ) ) as $why ) {
                $found[] = "{$file}: {$why}";
            }
        }

        $this->assertSame( [], $found, 'Close quiet hours with QuietHoursClosed::closeQuietHours().' );
    }

    public function test_the_guard_catches_the_old_pin_and_a_hand_rolled_empty_window(): void {
        $old = "QueryHelpers::set_config( 'comms_quiet_hours_start', '03:00' );\n"
             . "QueryHelpers::set_config( 'comms_quiet_hours_end', '03:01' );";
        $this->assertSame( [ 'one-minute window 03:00-03:01' ], self::violations( $old ) );

        $midnight = "set_config( 'comms_quiet_hours_start', '23:59' ); set_config( 'comms_quiet_hours_end', '00:00' );";
        $this->assertSame( [ 'one-minute window 23:59-00:00' ], self::violations( $midnight ) );

        $empty = "\\TT\\Infrastructure\\Query\\QueryHelpers::set_config( 'comms_quiet_hours_start', '00:00' );\n"
               . "\\TT\\Infrastructure\\Query\\QueryHelpers::set_config( 'comms_quiet_hours_end', '00:00' );";
        $this->assertSame( [ 'hand-rolled empty window 00:00-00:00' ], self::violations( $empty ) );

        $deliberate = "set_config( 'comms_quiet_hours_start', '21:00' ); set_config( 'comms_quiet_hours_end', '07:00' );";
        $this->assertSame( [], self::violations( $deliberate ) );
    }

    /**
     * Each start is paired with the next end in the same source.
     *
     * @return list<string>
     */
    private static function violations( string $src ): array {
        preg_match_all(
            "/set_config\\(\\s*'comms_quiet_hours_(start|end)'\\s*,\\s*'(\\d{1,2}:\\d{2})'/",
            $src,
            $m,
            PREG_SET_ORDER
        );

        $out   = [];
        $start = null;
        foreach ( $m as $call ) {
            if ( $call[1] === 'start' ) {
                $start = $call[2];
                continue;
            }
            if ( $start === null ) continue;
            $end   = $call[2];
            $width = ( self::minutes( $end ) - self::minutes( $start ) + 1440 ) % 1440;
            if ( $width === 1 ) $out[] = "one-minute window {$start}-{$end}";
            if ( $width === 0 ) $out[] = "hand-rolled empty window {$start}-{$end}";
            $start = null;
        }
        return $out;
    }

    private static function minutes( string $hhmm ): int {
        [ $h, $m ] = array_map( 'intval', explode( ':', $hhmm ) );
        return $h * 60 + $m;
    }
}
