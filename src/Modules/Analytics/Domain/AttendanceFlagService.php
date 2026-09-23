<?php
namespace TT\Modules\Analytics\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * AttendanceFlagService (#1488) — the one place that decides what counts as
 * attended, what counts as missed, and when a player is flagged.
 *
 * Both the attendance report (inline "at risk" badge + panel) and the
 * Comms cron (`detectAttendanceFlags`) read the thresholds and the status
 * sets here so they can never drift apart. The absence threshold is
 * operator-configurable in `tt_config` (key `attendance_flag_threshold`);
 * default 3.
 *
 * #4013 — two definitions live here, and they are not each other's negation:
 *
 *   - **Attended** = present **or late**. A player who turned up late was
 *     at the session; the academy counts them as there. So the present
 *     percentage every surface reports is `(present + late) / total`.
 *   - **Missed** = absent / excused / injured on a completed, actual,
 *     non-guest attendance row. A player is flagged when their missed count
 *     in the window reaches the absence threshold.
 *
 * Late used to fall between the two: it was neither in the numerator nor in
 * `missed`, so it silently dragged a player's percentage down without ever
 * flagging. That made the worst-looking attender on a team a player who had
 * been at every session, and it put the report's colour band and its flag on
 * different numerators.
 *
 * Counting late as attended would on its own lose chronic lateness, so
 * lateness flags on its own threshold (`attendance_late_flag_threshold`,
 * falling back to the absence threshold when unset). A flagged player
 * therefore carries one or both **reasons** — absence, lateness — so the
 * at-risk list can say which it is instead of implying absences.
 */
final class AttendanceFlagService {

    public const CONFIG_KEY              = 'attendance_flag_threshold';
    public const DEFAULT_THRESHOLD       = 3;

    /**
     * Lateness has its own threshold so an academy can act on it separately.
     * Unset (the default) means "same bar as absences" — one number to tune
     * for operators who do not want two.
     */
    public const LATE_CONFIG_KEY         = 'attendance_late_flag_threshold';

    /** Statuses that mean the player was at the session. */
    public const ATTENDED_STATUSES = [ 'present', 'late' ];

    /** Statuses that mean the player was not. */
    public const MISSED_STATUSES   = [ 'absent', 'excused', 'injured' ];

    /** Flag reasons, in the order a surface should read them out. */
    public const REASON_ABSENCE  = 'absence';
    public const REASON_LATENESS = 'lateness';

    /** Operator-configured absence threshold, clamped to a sane floor of 1. */
    public static function threshold(): int {
        $raw = (int) ( new ConfigService() )->get( self::CONFIG_KEY, (string) self::DEFAULT_THRESHOLD );
        return $raw >= 1 ? $raw : self::DEFAULT_THRESHOLD;
    }

    /**
     * Lateness threshold. Falls back to the absence threshold, so an academy
     * that raised the absence bar raises both unless it says otherwise.
     */
    public static function lateThreshold(): int {
        $raw = (int) ( new ConfigService() )->get( self::LATE_CONFIG_KEY, '' );
        return $raw >= 1 ? $raw : self::threshold();
    }

    /**
     * Attended count from an attendance-report row object that carries
     * `present` / `late` sums. Late counts as attended (#4013).
     */
    public static function attended( object $row ): int {
        return (int) ( $row->present ?? 0 )
            + (int) ( $row->late ?? 0 );
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

    /**
     * The present percentage every surface reports, to one decimal.
     * Null when the player has no rows in the window — "no data" is not 0%.
     */
    public static function presentPct( int $attended, int $total ): ?float {
        return $total > 0 ? round( ( $attended / $total ) * 100, 1 ) : null;
    }

    /** Is this player flagged for absences? */
    public static function isAbsenceFlagged( int $missed ): bool {
        return $missed >= self::threshold();
    }

    /** Is this player flagged for lateness? */
    public static function isLatenessFlagged( int $late ): bool {
        return $late >= self::lateThreshold();
    }

    /**
     * Why this player is flagged, or an empty list when they are not. A
     * player can be both, and the at-risk list says so rather than implying
     * absences for a player who has simply never been on time.
     *
     * @return list<string>
     */
    public static function flagReasons( int $missed, int $late ): array {
        $reasons = [];
        if ( self::isAbsenceFlagged( $missed ) )  $reasons[] = self::REASON_ABSENCE;
        if ( self::isLatenessFlagged( $late ) )   $reasons[] = self::REASON_LATENESS;
        return $reasons;
    }

    /**
     * Literal SQL predicate for "this attendance row is a miss", for the
     * queries that aggregate in the database rather than in PHP. No user
     * input reaches it, so it needs no preparation — the statuses are this
     * class's own constants.
     */
    public static function missedStatusClause( string $column = 'att.status' ): string {
        $col  = preg_replace( '/[^A-Za-z0-9_.]/', '', $column );
        $list = "'" . implode( "', '", self::MISSED_STATUSES ) . "'";
        return "LOWER({$col}) IN ( {$list} )";
    }
}
