<?php
/**
 * AttendanceStatus — typed constants for the five `tt_attendance.status`
 * values stored on the row that records a player's presence at an activity.
 *
 * Operator-editable in the sense that the column is VARCHAR + admins can
 * extend the vocabulary via the lookups admin, but the five values below
 * are the seeded canonical set used by every report, KPI, and SQL CASE
 * expression in the codebase.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $row->status === AttendanceStatus::PRESENT ) { ... }
 *     in_array( $row->status, [ AttendanceStatus::PRESENT, AttendanceStatus::LATE ], true );
 *
 * SQL string literals (CASE WHEN status='present' …) stay as literals —
 * those are the canonical stored values and the DB layer is the source of
 * truth, not the PHP layer.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class AttendanceStatus {

    public const PRESENT = 'present';
    public const ABSENT  = 'absent';
    public const LATE    = 'late';
    public const EXCUSED = 'excused';
    public const INJURED = 'injured';

    /** @var list<string> */
    public const ALL = [
        self::PRESENT,
        self::ABSENT,
        self::LATE,
        self::EXCUSED,
        self::INJURED,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
