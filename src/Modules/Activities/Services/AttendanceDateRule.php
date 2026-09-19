<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\AttendanceStatus;

/**
 * AttendanceDateRule (#3586) — a player cannot have been present at an
 * activity that has not happened yet.
 *
 * `present` and `late` say the player turned up, so they are refused on an
 * activity dated after today. `absent`, `excused` and `injured` stay allowed:
 * a coach can legitimately pre-record a known absence for next week (the
 * intent `ActivitiesRepository::completeIfNotTerminal()` already states), and
 * clearing a cell is always allowed.
 *
 * "Today" is site time, the same clock `completeIfNotTerminal()` uses, so an
 * activity can be recorded on the evening it was played wherever the server
 * sits.
 *
 * One rule for every write path that records a register: the attendance grid
 * (`POST attendance/bulk`), the activity's attendance matrix (POST/PUT
 * `activities`) and `PATCH attendance/{id}`. It only concerns recorded
 * (`record_type = 'actual'`) rows; the planned squad is a forecast and is
 * dated in the future by definition.
 */
final class AttendanceDateRule {

    public const ERROR_CODE = 'future_attendance';

    /** The statuses that assert the player was there. */
    private const ARRIVED = [ AttendanceStatus::PRESENT, AttendanceStatus::LATE ];

    public static function today(): string {
        return current_time( 'Y-m-d' );
    }

    public static function isUpcoming( string $session_date, ?string $today = null ): bool {
        $date = substr( trim( $session_date ), 0, 10 );
        return $date !== '' && $date > ( $today ?? self::today() );
    }

    /**
     * True when recording `$status` on an activity dated `$session_date`
     * would claim a player was at something that has not happened.
     */
    public static function refuses( string $status, string $session_date, ?string $today = null ): bool {
        $raw = trim( $status );
        if ( $raw === '' ) return false;
        $canonical = AttendanceStatus::normalise( $raw ) ?? $raw;
        if ( ! in_array( $canonical, self::ARRIVED, true ) ) return false;
        return self::isUpcoming( $session_date, $today );
    }

    public static function message(): string {
        return __( 'Present and late can only be recorded on or after the day of the activity. An absence can be recorded in advance.', 'talenttrack' );
    }
}
