<?php
namespace TT\Modules\Measurements\Units;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DurationFormat (#3273) — mm:ss in and mm:ss out, in seconds.
 *
 * Pure arithmetic on strings and floats: no database, no globals, no unit
 * lookup. That is on purpose — this is the piece that has to be provably right,
 * and it is the piece a test can pin down without a WordPress bootstrap.
 *
 * A duration is always read and written in minutes and seconds regardless of
 * the test's entry unit. "5:30" means five minutes thirty whether the operator
 * chose `min` or `s`, because the colon notation is what makes it unambiguous;
 * the entry unit still governs the plain-number fallback, which is why
 * {@see UnitContext} does the unit arithmetic and this class does not.
 */
final class DurationFormat {

    /**
     * Parse a duration into seconds.
     *
     * Accepts `mm:ss`, `mm:ss.fff`, `h:mm:ss` and `h:mm:ss.fff`. Returns null
     * for anything else — including `5:75`, because a seconds field at or above
     * sixty is a typo, and silently normalising it to 6:15 would be exactly the
     * kind of quiet reinterpretation this issue exists to remove.
     *
     * A bare number is NOT handled here: without a unit it is ambiguous, so the
     * caller decides what it means.
     */
    public static function parse( string $raw ): ?float {
        $raw = trim( $raw );
        if ( $raw === '' || strpos( $raw, ':' ) === false ) return null;

        $negative = false;
        if ( strncmp( $raw, '-', 1 ) === 0 ) {
            $negative = true;
            $raw      = substr( $raw, 1 );
        }

        $parts = explode( ':', $raw );
        if ( count( $parts ) < 2 || count( $parts ) > 3 ) return null;

        $seconds_part = array_pop( $parts );
        if ( ! preg_match( '/^\d{1,2}(\.\d{1,3})?$/', $seconds_part ) ) return null;
        $seconds = (float) $seconds_part;
        if ( $seconds >= 60.0 ) return null;

        $minutes_part = array_pop( $parts );
        if ( $minutes_part === null || ! preg_match( '/^\d{1,3}$/', $minutes_part ) ) return null;
        $minutes = (int) $minutes_part;

        $hours = 0;
        if ( ! empty( $parts ) ) {
            $hours_part = array_pop( $parts );
            if ( ! preg_match( '/^\d{1,2}$/', (string) $hours_part ) ) return null;
            $hours = (int) $hours_part;
            // With an hours field present, minutes are a clock field too.
            if ( $minutes >= 60 ) return null;
        }

        $total = ( $hours * 3600 ) + ( $minutes * 60 ) + $seconds;

        return $negative ? -$total : $total;
    }

    /**
     * Render seconds as `m:ss`, or `h:mm:ss` once there is an hour in it.
     *
     * Fractions are shown only when the value has one — a Cooper-test time of
     * 720 reads "12:00", a hand-timed 90.25 reads "1:30.25" — so the notation
     * never implies a precision the reading did not have.
     */
    public static function format( float $seconds, int $max_decimals = 2 ): string {
        $sign    = $seconds < 0 ? '-' : '';
        $seconds = abs( $seconds );

        $whole    = (int) floor( $seconds );
        $fraction = $seconds - $whole;

        $hours = intdiv( $whole, 3600 );
        $mins  = intdiv( $whole % 3600, 60 );
        $secs  = $whole % 60;

        $tail = '';
        if ( $max_decimals > 0 && $fraction > 0.0000001 ) {
            $tail = substr( rtrim( rtrim( number_format( $fraction, $max_decimals, '.', '' ), '0' ), '.' ), 1 );
        }

        if ( $hours > 0 ) {
            return $sign . $hours . ':' . str_pad( (string) $mins, 2, '0', STR_PAD_LEFT )
                 . ':' . str_pad( (string) $secs, 2, '0', STR_PAD_LEFT ) . $tail;
        }

        return $sign . $mins . ':' . str_pad( (string) $secs, 2, '0', STR_PAD_LEFT ) . $tail;
    }

    /**
     * The pattern an `<input type="text">` validates against client-side, so a
     * mistyped duration is caught before the round-trip. Kept beside the parser
     * it has to agree with.
     */
    public static function inputPattern(): string {
        return '(\d{1,2}:)?\d{1,3}:[0-5]\d(\.\d{1,3})?';
    }
}
