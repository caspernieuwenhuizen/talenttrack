<?php
/**
 * Migration 0156 — `player_feedback` column on `tt_evaluations` (#1386).
 *
 * Optional, coach-authored feedback written explicitly FOR the player to
 * read, distinct from the internal `notes` field (which stays staff-only
 * and is never surfaced to player/parent personas). Nullable TEXT, sits
 * next to `notes`. Idempotent ALTER (guarded by SHOW COLUMNS) per the
 * #1357 standard — dbDelta is reserved for genuinely new tables.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0156_evaluation_player_feedback';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_evaluations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return; // table not provisioned yet
        }

        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s", 'player_feedback'
        ) );
        if ( $col !== 'player_feedback' ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN player_feedback TEXT NULL DEFAULT NULL AFTER notes" );
        }
    }

    public function down(): void {
        // Forward-only. Drop manually if rolling back to pre-#1386.
    }
};
