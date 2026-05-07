<?php
/**
 * Migration 0075 — `tt_scheduled_reports` (#0083 Child 6).
 *
 * Stores recurring report definitions: pick a KPI (or a saved
 * explorer view), pick a frequency, pick recipients, pick a format.
 * The daily `tt_scheduled_reports_cron` checks active schedules,
 * renders the report, attaches the file, and sends via `wp_mail()`.
 *
 *   - `kpi_key` and `explorer_state_json` are exclusive: a schedule
 *     either targets a specific KPI directly, or carries a saved
 *     explorer state (filter + group-by + extra-dim selection) for
 *     a KPI. v1 ships only the `kpi_key` path; the JSON column is
 *     reserved for the explorer-state save flow shipping after.
 *   - `frequency` is one of the documented strings: 'weekly_monday',
 *     'monthly_first', 'season_end'. Strings (rather than integers)
 *     so a future per-club calendar can extend without a migration.
 *   - `recipients` is JSON: a list of email addresses or role keys.
 *     Role keys (e.g. `tt_head_dev`) expand to every user with that
 *     role at send time.
 *   - `format` is `csv` (v1) — `xlsx` and `pdf` defer.
 *   - `next_run_at` is set on create + after each successful run; the
 *     cron picks rows with `next_run_at <= NOW()` and `status = 'active'`.
 *
 * SaaS-readiness `club_id` + `uuid` per CLAUDE.md §4. Idempotent CREATE
 * TABLE IF NOT EXISTS via dbDelta.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0075_scheduled_reports';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS {$p}tt_scheduled_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) DEFAULT NULL,

            name VARCHAR(255) NOT NULL,
            kpi_key VARCHAR(64) DEFAULT NULL,
            explorer_state_json LONGTEXT DEFAULT NULL,
            frequency VARCHAR(20) NOT NULL,
            recipients TEXT NOT NULL,
            format VARCHAR(10) NOT NULL DEFAULT 'csv',

            last_run_at DATETIME DEFAULT NULL,
            next_run_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',

            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,

            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club_status (club_id, status),
            KEY idx_next_run (next_run_at, status)
        ) $charset;";
        dbDelta( $sql );
    }
};
