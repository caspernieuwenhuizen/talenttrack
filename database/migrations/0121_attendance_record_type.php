<?php
/**
 * Migration 0121 — Expected vs actual attendance distinction (#788 ship 1).
 *
 * Adds `record_type ENUM('expected', 'actual') NOT NULL DEFAULT 'actual'`
 * to `tt_attendance` plus a covering index on `(activity_id, record_type)`.
 *
 * Defaulting to `actual` keeps every existing query semantically correct
 * without behaviour change — pre-migration rows describe what actually
 * happened, so they are `actual`. Ship 2 will introduce the wizard mode
 * that writes `expected` rows when a coach plans attendance ahead of a
 * scheduled activity.
 *
 * The reporting sweep that lands alongside this migration adds
 * `AND record_type = 'actual'` to every read surface that should count
 * actuals only — the moment `expected` rows start being written,
 * everything that hasn't been audited would silently conflate the two
 * categories. The defensive sweep happens in ship 1 so it's in place
 * before ship 2 turns on the new write path.
 *
 * Idempotent — uses the same `SHOW COLUMNS` guard pattern as 0120.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0121_attendance_record_type';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = $p . 'tt_attendance';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $existing = $wpdb->get_results( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            'record_type'
        ) );
        if ( empty( $existing ) ) {
            $wpdb->query(
                "ALTER TABLE {$table} ADD COLUMN record_type ENUM('expected','actual') NOT NULL DEFAULT 'actual'"
            );
        }

        $idx = $wpdb->get_results(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_activity_record_type'"
        );
        if ( empty( $idx ) ) {
            $wpdb->query(
                "ALTER TABLE {$table} ADD INDEX idx_activity_record_type (activity_id, record_type)"
            );
        }
    }
};
