<?php
/**
 * Migration 0172 — archive-column completion (#1784).
 *
 * Gives every archivable entity the uniform `archived_at` + `archived_by`
 * pair the referential-integrity delete framework (#1783) and the shared
 * Restore / Delete-permanently row actions expect.
 *
 * Six tables already carried `archived_at` (from their own migrations) but
 * were missing the `archived_by` companion: trial tracks, test trainings,
 * holidays, player injuries, custom widgets, VCT exercises.
 *
 * Scheduled reports tracked their lifecycle with a `status` enum
 * ('active' | 'paused' | 'archived') and had neither column. This adds the
 * standard pair and backfills `archived_at` for rows already marked
 * `status='archived'`, so they join the framework while `status` keeps
 * driving active / paused.
 *
 * Idempotent — skips any column that already exists; re-runnable.
 * Forward-only (additive columns).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0172_archive_by_completion';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Already have archived_at — add the missing archived_by companion.
        $need_by = [
            'tt_trial_tracks',
            'tt_test_trainings',
            'tt_holidays',
            'tt_player_injuries',
            'tt_custom_widgets',
            'tt_vct_exercises',
        ];
        foreach ( $need_by as $table ) {
            $full = $p . $table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) continue;
            if ( ! $this->columnExists( $full, 'archived_by' ) ) {
                $wpdb->query( "ALTER TABLE {$full} ADD COLUMN archived_by BIGINT UNSIGNED NULL DEFAULT NULL" );
            }
        }

        // Scheduled reports — add the full pair + backfill from status.
        $sr = $p . 'tt_scheduled_reports';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sr ) ) === $sr ) {
            if ( ! $this->columnExists( $sr, 'archived_at' ) ) {
                $wpdb->query( "ALTER TABLE {$sr} ADD COLUMN archived_at DATETIME NULL DEFAULT NULL" );
                $wpdb->query( "ALTER TABLE {$sr} ADD INDEX idx_archived_at (archived_at)" );
            }
            if ( ! $this->columnExists( $sr, 'archived_by' ) ) {
                $wpdb->query( "ALTER TABLE {$sr} ADD COLUMN archived_by BIGINT UNSIGNED NULL DEFAULT NULL" );
            }
            // Rows already retired via the status enum get a soft-delete
            // timestamp so the framework + UI see them as archived.
            $wpdb->query( "UPDATE {$sr} SET archived_at = NOW() WHERE status = 'archived' AND archived_at IS NULL" );
        }
    }

    private function columnExists( string $table, string $column ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME, $table, $column
        ) ) > 0;
    }
};
