<?php
/**
 * Migration 0012 — Demo tags table (v3.2.0).
 *
 * Adds tt_demo_tags, the lookup that maps every demo-generated entity
 * back to its batch. Enables the site-level demo-mode scope filter
 * (QueryHelpers::apply_demo_scope — added in Checkpoint 2) and
 * dependency-ordered deletion via DemoDataCleaner.
 *
 * Per the spec (#0020), existing tables are untouched — all provenance
 * lives here. Cost is a JOIN/IN at read time, negligible at demo scale.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0012_demo_tags';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_demo_tags" ) ) === "{$p}tt_demo_tags" ) {
            return;
        }

        $charset = $wpdb->get_charset_collate();
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_demo_tags (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id VARCHAR(64) NOT NULL,
            entity_type VARCHAR(32) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            extra_json TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_batch (batch_id),
            KEY idx_lookup (entity_type, entity_id)
        ) {$charset}" );
    }
};
