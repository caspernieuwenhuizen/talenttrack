<?php
/**
 * AttendanceStatus — the canonical `tt_attendance.status` vocabulary.
 *
 * THIS CLASS IS THE AUTHORITY (#2909). The five constants below are the only
 * values that may be written to the column. The `attendance_status` lookup
 * table supplies the *label* an operator sees and may rename freely; renaming
 * a lookup row changes the display and nothing else.
 *
 * That separation is the whole point of this file. Before #2909 the column had
 * no canonical casing at all: the constants said `'present'`, the seed and both
 * writers said `'Present'`, and `$row->status === AttendanceStatus::PRESENT`
 * was therefore silently false on most real rows. Title Case won because it
 * matches what the majority of stored rows and the seeded lookup already carry
 * — but the value now lives here, in code, not in a table operators can edit.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $row->status === AttendanceStatus::PRESENT ) { ... }
 *     in_array( $row->status, [ AttendanceStatus::PRESENT, AttendanceStatus::LATE ], true );
 *
 * SQL literals (`WHERE status = 'Present'`) are safe either way: WordPress
 * creates these tables with a `_ci` collation, so MySQL compares them
 * case-insensitively. That is why the mismatch survived so long — every report
 * kept working while the PHP layer quietly failed every strict comparison.
 * Write new SQL in Title Case anyway, so the code reads as one vocabulary.
 *
 * REST endpoints accept any casing on input and normalise through
 * {@see normalise()}; a client that has been sending `'present'` for years
 * keeps working.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class AttendanceStatus {

    public const PRESENT = 'Present';
    public const ABSENT  = 'Absent';
    public const LATE    = 'Late';
    public const EXCUSED = 'Excused';
    public const INJURED = 'Injured';

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

    /**
     * Fold any casing to the canonical member, or null when the value is not
     * one of the five.
     *
     * This is what makes the vocabulary safe to tighten: input arriving from a
     * REST client, an import, or a pre-#2909 row is matched case-insensitively
     * and returned in canonical form, so callers can then use `===` honestly.
     */
    public static function normalise( string $value ): ?string {
        $needle = strtolower( trim( $value ) );
        foreach ( self::ALL as $member ) {
            if ( strtolower( $member ) === $needle ) {
                return $member;
            }
        }
        return null;
    }
}
