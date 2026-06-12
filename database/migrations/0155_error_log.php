<?php
/**
 * Migration 0155 — `tt_error_log` table (#1360).
 *
 * Persistent store for Logger error/warning entries so an operator can
 * diagnose "saving fails" reports from wp-admin without hosting-panel
 * or SSH access to the PHP error log. Capped at
 * ErrorLogRepository::MAX_ROWS rows — the repository prunes on insert,
 * so the table never grows unbounded.
 *
 * SaaS-readiness: carries `club_id` even though every install today
 * runs single-tenant; the read helpers filter on it.
 *
 * dbDelta is acceptable here per the #1357 standard — this is a
 * genuinely new table, not an ALTER on an existing one.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0155_error_log';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS {$p}tt_error_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            level VARCHAR(16) NOT NULL DEFAULT 'error',
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_level (level),
            KEY idx_created (created_at)
        ) {$charset};";

        dbDelta( $sql );
    }

    public function down(): void {
        // Forward-only. The table is a bounded diagnostic buffer;
        // drop manually if rolling back to pre-#1360.
    }
};
