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
