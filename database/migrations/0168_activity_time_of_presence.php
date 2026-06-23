<?php
/**
 * Migration 0168 — `tt_activities.time_of_presence` column (#1729).
 *
 * Adds the optional arrival/"be present by" time for match-type
 * activities so the weekly planner PDF can tell families when to show
 * up. Stored as a nullable TIME, mirroring `start_time` / `kickoff_time`.
 * The frontend form only captures it for match types, but the column is
 * generic.
 *
 * Idempotent — SHOW COLUMNS guard. Forward-only — operators wanting a
 * rollback restore from a pre-#1729 backup.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0168_activity_time_of_presence';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_activities";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s", 'time_of_presence'
        ) );
        if ( $col !== 'time_of_presence' ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN time_of_presence TIME DEFAULT NULL AFTER end_time" );
        }
    }

    public function down(): void {
        // Forward-only.
    }
};
