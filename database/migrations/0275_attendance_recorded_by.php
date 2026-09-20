<?php
/**
 * Migration 0275 — `tt_attendance.recorded_by` / `recorded_at` (#3655).
 *
 * A coach opened a completed trial training and found one attendance mark
 * neither they nor their assistant had entered. Nothing on the page or in
 * the API could say who wrote it or when: the table carried a status and a
 * note and no author at all.
 *
 * These two columns stamp the **last save of the register**, not the author
 * of each mark. Saving a register deletes and re-inserts the recorded rows
 * (`ActivitiesRepository::saveAttendance()`), so every row of one save
 * carries the same stamp, and the copy above it has to say so.
 *
 * Nullable with no backfill: rows written before this migration have no
 * recorded author and stay blank rather than gaining a fabricated one.
 * Planned rows are never stamped — a squad somebody selected is not a
 * register somebody took.
 *
 * Idempotent — SHOW COLUMNS guard per column.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0275_attendance_recorded_by';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_attendance";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $by = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'recorded_by' ) );
        if ( $by !== 'recorded_by' ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN recorded_by BIGINT UNSIGNED DEFAULT NULL" );
        }

        $at = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'recorded_at' ) );
        if ( $at !== 'recorded_at' ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN recorded_at DATETIME DEFAULT NULL" );
        }
    }

    public function down(): void {
        // Forward-only.
    }
};
