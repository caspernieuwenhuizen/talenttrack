<?php
/**
 * Migration 0241 — normalise `tt_attendance.status` casing (#2909, part 2
 * of #2863).
 *
 * THE BUG
 *
 * The column had no canonical casing. `AttendanceStatus::PRESENT` was
 * `'present'`; the seeded lookup rows, the attendance wizard and the
 * attendance grid all wrote `'Present'`; and `plannedStatusMap()` wrote
 * `'present'`. So `$row->status === AttendanceStatus::PRESENT` was
 * silently false on most real rows.
 *
 * It survived for years because WordPress creates these tables with a
 * `_ci` collation: MySQL compares `'Present' = 'present'` as true, so
 * every report kept returning sensible numbers while every strict PHP
 * comparison quietly failed. The damage was confined to the PHP layer,
 * which is also why nobody could see it.
 *
 * THE DECISION
 *
 * Title Case wins (decided 2026-08-27). It is what the majority of stored
 * rows and the seeded lookup already carry, so this migration rewrites the
 * minority. `AttendanceStatus` is now the authority for the value;
 * `tt_lookups` supplies only the label, which operators may rename freely.
 *
 * WHY THIS DOES NOT READ THE LOOKUP TABLE
 *
 * The issue asked that the migration normalise against the install's
 * actual lookup rows rather than the seeded names, since operators can
 * rename them. Following that literally would reintroduce the bug it is
 * meant to fix: if the stored value tracks a renameable label, then an
 * academy that renamed `Present` to `Aanwezig` would have this migration
 * write `'Aanwezig'` into the column, and every `=== AttendanceStatus::PRESENT`
 * in the codebase would be false again — for that install only, silently,
 * and forever.
 *
 * So the mapping is by canonical member, case-insensitively. A row reading
 * `'present'`, `'PRESENT'` or `'Present'` all become `'Present'`. A row
 * holding something outside the five is left exactly as it is: a custom
 * vocabulary an academy added through the lookups admin is their data, not
 * ours to rewrite.
 *
 * Idempotent: re-running matches nothing, because every row it would touch
 * already holds the canonical form.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0241_attendance_status_casing';
    }

    public function up(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tt_attendance';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        foreach ( AttendanceStatus::ALL as $canonical ) {
            // BINARY forces a case-SENSITIVE comparison. Without it the `_ci`
            // collation makes `status <> 'Present'` false for `'present'`, the
            // WHERE matches nothing, and the migration silently does nothing —
            // which is exactly the collation behaviour that hid the bug.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table}
                    SET status = %s
                  WHERE status = %s
                    AND BINARY status <> %s",
                $canonical,
                $canonical,
                $canonical
            ) );
        }
    }
};
