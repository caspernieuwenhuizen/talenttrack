<?php
/**
 * Migration 0148 — `tt_pdp_files.archived_at` column (#1274 PR1).
 *
 * Adds the parent column for the PDP soft-archive epic. Children
 * (`tt_pdp_conversations`, `tt_pdp_verdicts`, `tt_pdp_calendar_links`,
 * `tt_pdp_blocks`) become invisible because every read path JOINs
 * through `tt_pdp_files.id` — no need to thread the column through
 * five tables.
 *
 * Idempotent — SHOW COLUMNS guard. Forward-only; no down() because
 * archived PDPs would otherwise be silently restored.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0148_pdp_files_archived_at';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_pdp_files";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s", 'archived_at'
        ) );
        if ( $col !== 'archived_at' ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER updated_at" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_archived (archived_at)" );
        }
    }

    public function down(): void {
        // Forward-only — dropping the column would silently restore
        // every archived PDP. Operators who need a rollback restore
        // from a pre-#1274 backup.
    }
};
