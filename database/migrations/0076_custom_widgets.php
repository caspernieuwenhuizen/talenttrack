<?php
/**
 * Migration 0076 — `tt_custom_widgets` (#0078 Phase 2).
 *
 * Stores admin-authored persona-dashboard widget definitions backed
 * by registered `CustomDataSource` classes (Phase 1 shipped the data
 * layer; this migration + REST CRUD are Phase 2).
 *
 *   - `data_source_id` is the registry key (e.g. `players_active`);
 *     not a foreign key — sources are PHP classes, not DB rows.
 *   - `chart_type` ∈ { `table`, `kpi`, `bar`, `line` } per spec
 *     decision 2; pie / donut / radar deferred (existing widgets
 *     cover them).
 *   - `definition` carries the operator's choices: `columns` (array
 *     of column ids), `filters` (per-filter values), `aggregation`
 *     (for KPI / bar / line), `format` (per-column format hints),
 *     `cache_ttl_minutes` (Phase 5 reads it).
 *   - `archived_at` is a soft-delete tombstone; queries filter on
 *     IS NULL by default. Hard-delete is operator-initiated cleanup.
 *
 * SaaS-readiness `club_id` + `uuid` per CLAUDE.md §4. The `uuid`
 * doubles as the slot-config foreign key once Phase 4 wires the
 * persona-dashboard editor palette: a slot pointing at a custom
 * widget stores `data_source: <uuid>` so renames don't break
 * placements.
 *
 * Idempotent CREATE TABLE IF NOT EXISTS via dbDelta.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0076_custom_widgets';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS {$p}tt_custom_widgets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) NOT NULL,

            name VARCHAR(120) NOT NULL,
            data_source_id VARCHAR(80) NOT NULL,
            chart_type VARCHAR(16) NOT NULL,
            definition LONGTEXT NOT NULL,

            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            archived_at DATETIME DEFAULT NULL,

            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club (club_id),
            KEY idx_source (data_source_id),
            KEY idx_archived (archived_at)
        ) $charset;";
        dbDelta( $sql );
    }
};
