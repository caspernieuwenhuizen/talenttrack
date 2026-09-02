<?php
/**
 * Migration: 0254_pdp_files_recycle_bin
 *
 * #3300 — `tt_pdp_files` joins the archive → trash → purge lifecycle.
 *
 * WHY IT WAS OUTSIDE IT
 *
 * PDP is the only entity in the plugin with a hard-delete button on a live,
 * in-progress record. Every other record type archives first, and
 * "Delete permanently" only becomes reachable from the recycle bin.
 *
 * The root cause is structural rather than a UI slip: PDP was never
 * registered with the bin. `ArchiveRepository::TABLE_MAP` listed 21 entities
 * and `tt_pdp_files` was not one of them, even though the table has carried
 * `archived_at` since migration 0148 and `PdpFilesRepository::archiveAllForPlayer()`
 * archives PDP files as a cascade of archiving a player. So a PDP file could
 * be archived and then never appear in the bin, never be restored from it,
 * and never be purged by the retention cron. #1294 grew a bespoke hard-delete
 * path to fill that hole and hung its entry point on the live detail page.
 *
 * WHAT THIS ADDS
 *
 * The three lifecycle columns the bin expects and 0186 gave every other bin
 * table. `archived_at` is already present (0148) and is left alone;
 * `archived_by` was never added alongside it, so it comes in here.
 *
 * Idempotent throughout: `addColumnIfMissing` no-ops on a re-run or a
 * partially-migrated host, and the index add checks first.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0254_pdp_files_recycle_bin';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_pdp_files';

        // A fresh install runs this in the same pass that creates the table;
        // a host mid-upgrade may not have it yet. Either way, nothing to do.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // `archived_at` arrived in 0148 without its companion. The bin reads
        // both — "who archived this" is part of the audit trail the recycle
        // bin shows.
        MigrationHelpers::addColumnIfMissing( $table, 'archived_by', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'archived_at' );
        MigrationHelpers::addColumnIfMissing( $table, 'trashed_at', 'DATETIME NULL DEFAULT NULL', 'archived_by' );
        MigrationHelpers::addColumnIfMissing( $table, 'trashed_by', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'trashed_at' );

        $this->addIndexIfMissing( $table, 'idx_trashed_at', 'trashed_at' );
    }

    /**
     * Same guard 0186 uses: `ADD KEY` is not idempotent, and a duplicate-key
     * error would fail the whole migration at the statement.
     */
    private function addIndexIfMissing( string $table, string $index, string $column ): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index ) );
        if ( empty( $exists ) ) {
            $this->exec( "ALTER TABLE {$table} ADD KEY {$index} ({$column})" );
        }
    }
};
