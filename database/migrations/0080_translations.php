<?php
/**
 * Migration 0080 — `tt_translations` (#0090 Phase 1 — data-row i18n foundation).
 *
 * Centralized per-(entity_type, entity_id, field, locale, club_id)
 * translation table. Stores translations for data-row strings:
 * lookup labels, eval-category names, role labels, functional-role
 * labels, etc. UI strings (`__('Save')` etc.) continue to flow
 * through `.po` / gettext.
 *
 * `entity_type` is `VARCHAR(32)` rather than ENUM so adding a new
 * translatable entity needs zero schema migration. The
 * `Modules\I18n\TranslatableFieldRegistry` enforces the allowlist
 * in software.
 *
 * SaaS-readiness `club_id` per CLAUDE.md §4. Each tenant has its
 * own translation set. The `.po` backfill writes club_id=1 rows for
 * the existing single tenant; future tenants get a top-up migration
 * at activation per the existing 0063/0064/0067/0069/0074/0077
 * matrix-entity precedent.
 *
 * Idempotent CREATE TABLE IF NOT EXISTS via dbDelta.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0080_translations';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS {$p}tt_translations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,

            entity_type VARCHAR(32) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            field VARCHAR(32) NOT NULL,
            locale VARCHAR(10) NOT NULL,
            value TEXT NOT NULL,

            updated_by BIGINT UNSIGNED DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY uk_lookup (club_id, entity_type, entity_id, field, locale),
            KEY idx_lookup (club_id, entity_type, entity_id),
            KEY idx_locale (locale)
        ) $charset;";
        dbDelta( $sql );
    }
};
