<?php
/**
 * Migration 0152 — `tt_activities.tournament_id` column (#1324).
 *
 * Adds the optional FK that links a tournament-typed activity to the
 * tt_tournament row backing it. The reverse direction
 * (`tt_tournament_matches.activity_id`) already exists for individual
 * matches inside a tournament; this column captures the parent
 * tournament for the activity-of-type-tournament wrapper.
 *
 * Idempotent — SHOW COLUMNS guard. Forward-only — operators wanting a
 * rollback restore from a pre-#1324 backup.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0152_activities_tournament_id';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_activities";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s", 'tournament_id'
        ) );
        if ( $col !== 'tournament_id' ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN tournament_id BIGINT UNSIGNED DEFAULT NULL AFTER team_id" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_tournament (tournament_id)" );
        }
    }

    public function down(): void {
        // Forward-only.
    }
};
