<?php
/**
 * Migration 0009 — Category weights per age group.
 *
 * Sprint v2.13.0. Adds the tt_category_weights table used to compute a
 * weighted overall rating per evaluation. Weights are percentages
 * (integers 0-100) that sum to 100 per age group when configured;
 * age groups without weights use equal-fallback at compute time.
 *
 * Idempotent. Creates the table only if it doesn't already exist.
 * Seeds nothing — equal-fallback handles the empty-table case.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0009_category_weights';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_category_weights" ) ) === "{$p}tt_category_weights" ) {
            return;
        }

        $charset = $wpdb->get_charset_collate();
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_category_weights (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            age_group_id BIGINT UNSIGNED NOT NULL,
            main_category_id BIGINT UNSIGNED NOT NULL,
            weight TINYINT UNSIGNED NOT NULL DEFAULT 25,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_age_main (age_group_id, main_category_id),
            KEY idx_age_group (age_group_id),
            KEY idx_main_category (main_category_id)
        ) {$charset}" );
    }
};
