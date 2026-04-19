<?php
/**
 * Migration: 0003_create_custom_fields
 *
 * Creates two polymorphic tables:
 *   - tt_custom_fields   — field definitions per entity_type
 *   - tt_custom_values   — stored values per (entity_type, entity_id, field)
 *
 * Polymorphic design: a single schema supports custom fields for players today,
 * and teams / sessions / goals / etc. in the future without new migrations.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0003_create_custom_fields';
    }

    public function up(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $fields_sql = "CREATE TABLE IF NOT EXISTS {$p}tt_custom_fields (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(64) NOT NULL DEFAULT 'player',
            field_key VARCHAR(100) NOT NULL,
            label VARCHAR(255) NOT NULL,
            field_type VARCHAR(32) NOT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            options LONGTEXT,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_entity_key (entity_type, field_key),
            KEY idx_entity_active (entity_type, is_active, sort_order)
        ) $c;";

        $values_sql = "CREATE TABLE IF NOT EXISTS {$p}tt_custom_values (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(64) NOT NULL DEFAULT 'player',
            entity_id BIGINT UNSIGNED NOT NULL,
            field_id BIGINT UNSIGNED NOT NULL,
            value LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_entity_field (entity_type, entity_id, field_id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_field (field_id)
        ) $c;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $fields_sql );
        dbDelta( $values_sql );
    }
};
