<?php
/**
 * Migration 0021 — Translation layer schema (#0025).
 *
 * Three new tables backing the opt-in auto-translation feature:
 *
 *   - tt_translations_cache       — render-time lookup keyed on
 *                                   (source_hash, source_lang,
 *                                    target_lang, engine)
 *   - tt_translations_usage       — per-month, per-engine character
 *                                   counters for the soft cost cap
 *   - tt_translation_source_meta  — per-source-string detected language
 *                                   so re-saves of unchanged text
 *                                   don't pay for a re-detection
 *
 * The whole feature is default-OFF (gated by `tt_translations_enabled`
 * in `tt_config`). Existing data is unaffected by this migration; if
 * the admin never opts in, these tables stay empty.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0021_translations_layer';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $sql = [];

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_translations_cache (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_hash CHAR(64) NOT NULL,
            source_lang VARCHAR(10) NOT NULL,
            target_lang VARCHAR(10) NOT NULL,
            translated_text LONGTEXT NOT NULL,
            engine VARCHAR(32) NOT NULL,
            char_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_lookup (source_hash, source_lang, target_lang, engine),
            KEY idx_created (created_at),
            KEY idx_target (target_lang)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_translations_usage (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            period_start DATE NOT NULL,
            engine VARCHAR(32) NOT NULL,
            chars_billed BIGINT UNSIGNED NOT NULL DEFAULT 0,
            api_calls INT UNSIGNED NOT NULL DEFAULT 0,
            threshold_hit_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_month_engine (period_start, engine)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_translation_source_meta (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(32) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            field_name VARCHAR(64) NOT NULL,
            source_hash CHAR(64) NOT NULL,
            detected_lang VARCHAR(10) NOT NULL,
            detection_confidence DECIMAL(3,2) NOT NULL DEFAULT 0,
            last_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_field (entity_type, entity_id, field_name),
            KEY idx_hash (source_hash)
        ) {$charset};";

        foreach ( $sql as $statement ) $wpdb->query( $statement );
    }
};
