<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ActivityTimeWindow (#3679) — one way to write an activity's clock time.
 *
 * `start_time` and `end_time` are stored as `HH:MM:SS`, and every surface
 * that shows them wants `18:30–20:00`. The activity detail hero built that
 * string inline, so the peek panel would have grown a second copy of the
 * same three-branch rule and the two could drift on the separator or on
 * what an open-ended activity looks like.
 *
 * An activity with no end time is a real case — a trial morning or an
 * away trip whose return is unknown — and reads as the start alone rather
 * than as a dangling dash. An activity with no start time has no window at
 * all and returns an empty string, which the peek envelope then drops.
 */
final class ActivityTimeWindow {

    /** Between start and end: an en dash, not a hyphen (a range, not a minus). */
    private const SEPARATOR = '–';

    /**
     * `18:30–20:00`, or `18:30` with no end, or `''` with no start.
     */
    public static function format( string $start, string $end ): string {
        $from = self::clock( $start );
        if ( $from === '' ) return '';

        $to = self::clock( $end );
        return $to === '' ? $from : $from . self::SEPARATOR . $to;
    }

    /**
     * #4513 — the longest window read as ending the next day. An end before
     * the start is a late activity crossing midnight (a tournament evening
     * from 23:00 to 00:30) only when the span stays this short; 10:00 to
     * 09:00 is a typo, not a 23-hour training.
     */
    public const MAX_NEXT_DAY_MINUTES = 360;

    /**
     * True when `$end` is earlier than `$start` and the window from start to
     * end on the next day is at most `MAX_NEXT_DAY_MINUTES`. "Next day" is
     * derived, never stored: both columns stay `HH:MM:SS`.
     */
    public static function endIsNextDay( string $start, string $end ): bool {
        $s = self::seconds( $start );
        $e = self::seconds( $end );
        if ( $s === null || $e === null || $e >= $s ) return false;
        return ( $e + DAY_IN_SECONDS - $s ) <= self::MAX_NEXT_DAY_MINUTES * MINUTE_IN_SECONDS;
    }

    /**
     * Whole minutes from start to end, counting a next-day end (23:00 to
     * 00:30 is 90). 0 when either time is missing or unparseable, or the
     * end is neither after the start nor a next-day end.
     */
    public static function minutes( string $start, string $end ): int {
        $s = self::seconds( $start );
        $e = self::seconds( $end );
        if ( $s === null || $e === null ) return 0;
        if ( $e > $s ) return intdiv( $e - $s, MINUTE_IN_SECONDS );
        if ( self::endIsNextDay( $start, $end ) ) return intdiv( $e + DAY_IN_SECONDS - $s, MINUTE_IN_SECONDS );
        return 0;
    }

    /** Seconds since midnight for `H:MM`, `HH:MM` or `HH:MM:SS`; null otherwise. */
    private static function seconds( string $time ): ?int {
        if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim( $time ), $m ) ) return null;
        $h = (int) $m[1];
        $i = (int) $m[2];
        $s = isset( $m[3] ) ? (int) $m[3] : 0;
        if ( $h > 23 || $i > 59 || $s > 59 ) return null;
        return $h * 3600 + $i * 60 + $s;
    }

    /**
     * `HH:MM` from a stored `HH:MM:SS`, or `''` when the column is unset.
     * The column is `TIME DEFAULT NULL`, so "no time" arrives as null and
     * casts to an empty string; nothing else is read as absent, because an
     * activity really can start on the hour.
     */
    public static function clock( string $time ): string {
        $raw = trim( $time );
        return $raw === '' ? '' : substr( $raw, 0, 5 );
    }
}
