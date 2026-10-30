<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Activities\Services\AttendanceDateRule;

/**
 * InjuryStatus — the one place that answers what an injury record means.
 *
 * Two questions, asked by different surfaces, used to be answered in four
 * places with three rules. They live here now, side by side, so they can be
 * read against each other:
 *
 * 1. **What is the state of this record today?** `status()`:
 *    - `closed`     — an actual return is recorded;
 *    - `overdue`    — no actual return, and the expected return has passed;
 *    - `incomplete` — no actual return, no expected return, and the injury
 *                     started at least `incompleteAfterDays()` days ago;
 *    - `open`       — anything else.
 *    The player file and the squad overview show this.
 *
 * 2. **Does the record keep the player out on day D?** `coversDay()` and
 *    its SQL twin `coversDaySql()`, which a roster query uses so it stays
 *    one query. An injury covers D when it is not archived or trashed,
 *    started on or before D, and on D the player was not yet back: with an
 *    actual return, D is before it (the return day is fit); without one,
 *    the expected return is unknown or D or later. Planning asks this.
 *
 * Derived on read, never stored: a stored status goes stale the day after
 * it is written, which is exactly what this derivation avoids.
 *
 * The two answers are not the same question and do not always agree on
 * purpose: an overdue record is open on the player file but no longer
 * covers today in planning, and an incomplete one still does. What
 * planning should do with overdue and incomplete records is a policy of
 * its own; until it changes, `coversDay()` is the rule planning has always
 * used. `InjuryStatusTest` holds the PHP and SQL forms to the same answer.
 *
 * One clock: `AttendanceDateRule::today()`, the day the attendance and
 * planning rules already read.
 */
final class InjuryStatus {

    public const OPEN       = 'open';
    public const OVERDUE    = 'overdue';
    public const INCOMPLETE = 'incomplete';
    public const CLOSED     = 'closed';

    /** `tt_config` key: days without an expected return before a record is incomplete. */
    public const INCOMPLETE_AFTER_KEY = 'injury_incomplete_after_days';

    /** Fallback when the club has not set its own number. Not seeded. */
    public const INCOMPLETE_AFTER_DEFAULT = 14;

    /**
     * The record's state as of `$today` (today when null).
     *
     * @param object|array<string, mixed> $row An injury row.
     */
    public static function status( $row, ?string $today = null ): string {
        $r        = (array) $row;
        $today    = self::date( $today ?? AttendanceDateRule::today() );
        $actual   = self::date( (string) ( $r['actual_return'] ?? '' ) );
        $expected = self::date( (string) ( $r['expected_return'] ?? '' ) );
        $started  = self::date( (string) ( $r['started_on'] ?? '' ) );

        if ( $actual !== '' ) return self::CLOSED;
        if ( $expected !== '' ) {
            return $expected < $today ? self::OVERDUE : self::OPEN;
        }
        if ( $started !== '' && self::daysBetween( $started, $today ) >= self::incompleteAfterDays() ) {
            return self::INCOMPLETE;
        }
        return self::OPEN;
    }

    /** Not yet closed: the set the overview lists as "currently out". */
    public static function isOpen( string $status ): bool {
        return $status !== self::CLOSED;
    }

    /**
     * Whether the record keeps the player out on `$day` (Y-m-d).
     *
     * @param object|array<string, mixed> $row An injury row.
     */
    public static function coversDay( $row, string $day ): bool {
        $r = (array) $row;
        if ( ! empty( $r['archived_at'] ) || ! empty( $r['trashed_at'] ) ) return false;

        $day      = self::date( $day );
        $started  = self::date( (string) ( $r['started_on'] ?? '' ) );
        $actual   = self::date( (string) ( $r['actual_return'] ?? '' ) );
        $expected = self::date( (string) ( $r['expected_return'] ?? '' ) );

        if ( $day === '' || $started === '' || $started > $day ) return false;
        if ( $actual !== '' ) return $actual > $day;
        return $expected === '' || $expected >= $day;
    }

    /**
     * The SQL form of `coversDay()`, for a `WHERE` over `tt_player_injuries`.
     * It carries three `%s` placeholders, each bound to the day.
     *
     * @param string $alias Table alias, e.g. `i`; empty for the bare table.
     */
    public static function coversDaySql( string $alias = '' ): string {
        $a = $alias === '' ? '' : (string) preg_replace( '/[^a-z0-9_]/i', '', $alias ) . '.';
        return "{$a}archived_at IS NULL
                AND {$a}trashed_at IS NULL
                AND {$a}started_on <= %s
                AND (
                      ( {$a}actual_return IS NOT NULL AND {$a}actual_return > %s )
                   OR ( {$a}actual_return IS NULL
                        AND ( {$a}expected_return IS NULL OR {$a}expected_return >= %s ) )
                )";
    }

    /** The SQL form of "not closed", for the overview's "currently out" filter. */
    public static function openSql( string $alias = '' ): string {
        $a = $alias === '' ? '' : (string) preg_replace( '/[^a-z0-9_]/i', '', $alias ) . '.';
        return "{$a}actual_return IS NULL";
    }

    /**
     * Whole days the player has been out: to the actual return when there
     * is one, otherwise to `$today`.
     *
     * @param object|array<string, mixed> $row An injury row.
     */
    public static function daysOut( $row, ?string $today = null ): int {
        $r       = (array) $row;
        $started = self::date( (string) ( $r['started_on'] ?? '' ) );
        if ( $started === '' ) return 0;
        $actual = self::date( (string) ( $r['actual_return'] ?? '' ) );
        $end    = $actual !== '' ? $actual : self::date( $today ?? AttendanceDateRule::today() );
        return $end === '' ? 0 : max( 0, self::daysBetween( $started, $end ) );
    }

    /** The club's number of days, `tt_config`, falling back to 14. */
    public static function incompleteAfterDays(): int {
        $n = (int) QueryHelpers::get_config( self::INCOMPLETE_AFTER_KEY, (string) self::INCOMPLETE_AFTER_DEFAULT );
        return $n > 0 ? $n : self::INCOMPLETE_AFTER_DEFAULT;
    }

    private static function daysBetween( string $from, string $to ): int {
        $a = strtotime( $from . ' 00:00:00 UTC' );
        $b = strtotime( $to . ' 00:00:00 UTC' );
        if ( $a === false || $b === false ) return 0;
        return (int) floor( ( $b - $a ) / DAY_IN_SECONDS );
    }

    /** The Y-m-d part of a date or datetime, or '' when it is not one. */
    private static function date( string $value ): string {
        $d = substr( trim( $value ), 0, 10 );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) === 1 ? $d : '';
    }
}
