<?php
/**
 * Migration 0211 — generalise `tt_saved_filters` beyond reports (#2448).
 *
 * #2385 shipped saved filter presets for five report surfaces, so the column
 * was named `report_key`. Saved views are now part of the shared FilterBar
 * and any surface can opt in, so the column becomes `view_key` and its index
 * follows. Existing rows keep their five report keys, so presets saved before
 * this migration continue to resolve.
 *
 * Also adds `is_default` — the column only. The auto-apply behaviour lands in
 * #2450; carrying the column here avoids a second ALTER on the same table.
 *
 * Forward-only, and written to be re-runnable: each step checks the current
 * shape first, so a partially-applied state (or an install that already has
 * the new column) is a no-op rather than an error.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0211_saved_views_generalise';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_saved_filters';

        // The table only exists once 0208 has run. Nothing to do otherwise —
        // 0208 creates it in its final pre-2448 shape and this runs after.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) return;

        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
        $columns = is_array( $columns ) ? $columns : [];

        // report_key -> view_key. Skipped when the rename already happened.
        if ( in_array( 'report_key', $columns, true ) && ! in_array( 'view_key', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} CHANGE `report_key` `view_key` VARCHAR(64) NOT NULL" );

            // The old index named the old column; rebuild it under the new name.
            $index = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'user_report' ) );
            if ( ! empty( $index ) ) {
                $wpdb->query( "ALTER TABLE {$table} DROP INDEX `user_report`" );
            }
            $new_index = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'user_view' ) );
            if ( empty( $new_index ) ) {
                $wpdb->query( "ALTER TABLE {$table} ADD KEY `user_view` (`club_id`, `user_id`, `view_key`)" );
            }
        }

        // is_default — column only; #2450 adds the behaviour.
        if ( ! in_array( 'is_default', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `is_default` TINYINT(1) NOT NULL DEFAULT 0 AFTER `filters_json`" );
        }
    }

    public function down(): void {
        // Forward-only.
    }
};
