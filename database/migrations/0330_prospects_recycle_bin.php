<?php
/**
 * Migration: 0330_prospects_recycle_bin
 *
 * #4840 — `tt_prospects` joins the archive → trash → purge lifecycle, so a
 * prospect can be deleted: moved to the recycle bin, restored from it, and
 * purged by hand or after the bin's retention period.
 *
 * `archived_at` and `archived_by` have been on the table since 0066. The bin
 * also reads `trashed_at` and `trashed_by`, the two columns 0186 and 0254
 * gave the other bin tables. Idempotent: `addColumnIfMissing` no-ops on a
 * re-run, and the index add checks first.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0330_prospects_recycle_bin';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_prospects';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing( $table, 'trashed_at', 'DATETIME NULL DEFAULT NULL', 'archive_reason' );
        MigrationHelpers::addColumnIfMissing( $table, 'trashed_by', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'trashed_at' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'idx_trashed_at' ) );
        if ( empty( $exists ) ) {
            $this->exec( "ALTER TABLE {$table} ADD KEY idx_trashed_at (trashed_at)" );
        }
    }
};
