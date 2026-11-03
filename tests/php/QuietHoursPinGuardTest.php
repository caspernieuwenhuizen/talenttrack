<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4846: no test pins quiet hours to a window that the wall clock can open.
 *
 * Ten test files pinned the window "shut" at 03:00-03:01. That is a real
 * one-minute window. A CI run that reaches those tests during that minute
 * holds the message, nothing reaches the channel, and the tests fail for no
 * real reason.
 *
 * The rules, over every quiet-hours value a test sets:
 *
 * - No one-minute window. It holds every send during that minute.
 * - No window open for one minute either, such as 00:00-23:59. No window
 *   covers the whole day, so a test that expects a hold freezes the clock
 *   with `FrozenCommsClock` instead (#4861).
 * - An empty window (start equals end) comes from `QuietHoursClosed`, not
 *   a hand-rolled pair, so there is one fixture to read and to trust.
 * - Every value is a literal, so the rules above can read it.
 *
 * A test about quiet hours themselves sets a wider window and freezes the
 * clock, through `FrozenCommsClock` or `QuietHoursPolicy`'s constructor.
 */
final class QuietHoursPinGuardTest extends WP_UnitTestCase {

    use QuietHoursClosed;

    private const FIXTURE = 'QuietHoursClosed.php';

    /**
     * The fixture on a frozen clock: nothing is held at any second checked,
     * 03:00:04 and both sides of midnight included, nor at any minute of the
     * day.
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

        $this->assertSame( [], $found, 'Close quiet hours with QuietHoursClosed, or freeze the clock with FrozenCommsClock.' );
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

        $all_day = "set_config( 'comms_quiet_hours_start', '00:00' ); set_config( 'comms_quiet_hours_end', '23:59' );";
        $this->assertSame( [ 'one-minute gap 00:00-23:59' ], self::violations( $all_day ) );

        $variable = "QueryHelpers::set_config( 'comms_quiet_hours_start', \$start );\n"
                  . "QueryHelpers::set_config( 'comms_quiet_hours_end', self::SHUT );";
        $this->assertSame( [ 'non-literal start value $start', 'non-literal end value self::SHUT' ], self::violations( $variable ) );

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
            "/set_config\\(\\s*'comms_quiet_hours_(start|end)'\\s*,\\s*([^,)]+?)\\s*\\)/",
            $src,
            $m,
            PREG_SET_ORDER
        );

        $out   = [];
        $start = null;
        foreach ( $m as $call ) {
            if ( ! preg_match( "/^'(\\d{1,2}:\\d{2})'$/", $call[2], $literal ) ) {
                $out[] = "non-literal {$call[1]} value {$call[2]}";
                if ( $call[1] === 'start' ) $start = null;
                continue;
            }
            if ( $call[1] === 'start' ) {
                $start = $literal[1];
                continue;
            }
            if ( $start === null ) continue;
            $end   = $literal[1];
            $width = ( self::minutes( $end ) - self::minutes( $start ) + 1440 ) % 1440;
            if ( $width === 1 ) $out[] = "one-minute window {$start}-{$end}";
            if ( $width === 1439 ) $out[] = "one-minute gap {$start}-{$end}";
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
