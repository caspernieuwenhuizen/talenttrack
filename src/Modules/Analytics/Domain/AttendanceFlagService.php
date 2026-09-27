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
 *
 * #4041 — every attendance percentage in the plugin is derived here, over
 * the player's own team's activities (`is_guest = 0`): reports, exports,
 * dashboards, the semantic-layer measure. There is exactly one exception,
 * {@see presentPctForStatus()}, for the player status traffic light, and it
 * is a named method here rather than a second formula somewhere else.
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
        return self::lateThresholdOverride() ?? self::threshold();
    }

    /**
     * The lateness threshold an operator actually set, or `null` when this
     * academy is inheriting the absence threshold.
     *
     * The settings field needs that difference and `lateThreshold()` cannot
     * express it: it resolves the inheritance, so "set to 3" and "following
     * 3" come back identical, and a field pre-filled from it would post the
     * resolved number back on the next save and quietly end the inheritance.
     * A stored value below 1 (blank, or junk from an older hand-edit) reads
     * as unset, which is what the fallback already does with it.
     */
    public static function lateThresholdOverride(): ?int {
        $raw = (int) ( new ConfigService() )->get( self::LATE_CONFIG_KEY, '' );
        return $raw >= 1 ? $raw : null;
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
        return self::inClause( $column, self::MISSED_STATUSES );
    }

    /**
     * #4041 — the SQL predicate for "this attendance row counts as attended"
     * (present or late). Same construction as {@see missedStatusClause()}.
     */
    public static function attendedStatusClause( string $column = 'att.status' ): string {
        return self::inClause( $column, self::ATTENDED_STATUSES );
    }

    /**
     * #4041 — `SUM(...)` of the attended rows, for a query that aggregates
     * in the database: `SELECT {$attended} AS attended, COUNT(*) AS total`.
     */
    public static function attendedSumSql( string $column = 'att.status' ): string {
        return 'SUM( CASE WHEN ' . self::attendedStatusClause( $column ) . ' THEN 1 ELSE 0 END )';
    }

    /**
     * #4041 — one `SUM(...) AS <status>` column per known status, for the
     * queries that report the breakdown (present, late, absent, excused,
     * injured). Callers then read the counts through {@see attended()} and
     * {@see missed()} rather than adding them up themselves.
     */
    public static function statusCountsSql( string $column = 'att.status' ): string {
        $col  = self::column( $column );
        $sums = [];
        foreach ( array_merge( self::ATTENDED_STATUSES, self::MISSED_STATUSES ) as $status ) {
            $sums[] = "SUM( CASE WHEN LOWER({$col}) = '{$status}' THEN 1 ELSE 0 END ) AS {$status}";
        }
        return implode( ",\n                ", $sums );
    }

    /** #4041 — does this one status (any case) count as attended? */
    public static function isAttended( string $status ): bool {
        return in_array( strtolower( trim( $status ) ), self::ATTENDED_STATUSES, true );
    }

    /** #4041 — does this one status (any case) count as missed? */
    public static function isMissed( string $status ): bool {
        return in_array( strtolower( trim( $status ) ), self::MISSED_STATUSES, true );
    }

    /**
     * #4041 — attended over total across several attendance-report rows
     * (arrays or objects carrying `present`, `late` and `total`): the pooled
     * figure a report's "Avg. attendance" tile shows. Null with no rows.
     *
     * @param iterable<array<string,mixed>|object> $rows
     */
    public static function pooledPresentPct( iterable $rows ): ?float {
        $attended = 0;
        $total    = 0;
        foreach ( $rows as $r ) {
            $o         = (object) $r;
            $attended += self::attended( $o );
            $total    += (int) ( $o->total ?? 0 );
        }
        return self::presentPct( $attended, $total );
    }

    /**
     * #4041 — THE ONE EXCEPTION to the rule above, and deliberately named so.
     *
     * The player status traffic light (and the cohort board, which shows the
     * same score) leaves excused and injured activities out of the
     * denominator, so a player is not marked down for being injured or for
     * an absence the club excused. Late still counts as attended. Every other
     * surface uses {@see presentPct()}; nothing else may divide by anything
     * but the full total.
     *
     * Null when nothing is left to count once those are set aside.
     */
    public static function presentPctForStatus( int $attended, int $total, int $excused, int $injured ): ?float {
        return self::presentPct( $attended, max( 0, $total - $excused - $injured ) );
    }

    /** @param list<string> $statuses */
    private static function inClause( string $column, array $statuses ): string {
        $col  = self::column( $column );
        $list = "'" . implode( "', '", $statuses ) . "'";
        return "LOWER({$col}) IN ( {$list} )";
    }

    private static function column( string $column ): string {
        return (string) preg_replace( '/[^A-Za-z0-9_.]/', '', $column );
    }
}
