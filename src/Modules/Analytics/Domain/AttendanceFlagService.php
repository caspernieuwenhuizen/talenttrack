<?php
namespace TT\Modules\Analytics\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * AttendanceFlagService (#1488) — the single source of truth for the
 * consecutive/repeated-absence flag threshold and the non-present count.
 *
 * Both the attendance report (inline "at risk" badge + panel) and the
 * Comms cron (`detectAttendanceFlags`) read the threshold here so they
 * can never drift apart. The threshold is operator-configurable in
 * `tt_config` (key `attendance_flag_threshold`); default 3.
 *
 * "Missed" follows the cron's existing definition: a non-present status
 * (absent / excused / injured) on a completed, actual, non-guest
 * attendance row. A player is flagged when their missed count in the
 * window reaches the threshold.
 */
final class AttendanceFlagService {

    public const CONFIG_KEY         = 'attendance_flag_threshold';
    public const DEFAULT_THRESHOLD  = 3;

    /** Operator-configured threshold, clamped to a sane floor of 1. */
    public static function threshold(): int {
        $raw = (int) ( new ConfigService() )->get( self::CONFIG_KEY, (string) self::DEFAULT_THRESHOLD );
        return $raw >= 1 ? $raw : self::DEFAULT_THRESHOLD;
    }

    /**
     * Non-present count from an attendance-report row object that carries
     * `absent` / `excused` / `injured` sums.
     */
    public static function missed( object $row ): int {
        return (int) ( $row->absent ?? 0 )
            + (int) ( $row->excused ?? 0 )
            + (int) ( $row->injured ?? 0 );
    }
}
