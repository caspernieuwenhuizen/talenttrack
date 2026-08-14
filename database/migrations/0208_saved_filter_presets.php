<?php
/**
 * Migration 0208 — `tt_saved_filters` (#2385).
 *
 * Personal, named filter presets for the standard reports' shared filter
 * bar. Each row is one user's saved combination for one report
 * (`report_key`), storing the filter params as JSON — the period *key* when
 * a preset pill is chosen (so "This season" stays relative), resolved
 * from/to dates for a manual range. Carries the SaaS tenancy scaffold
 * (`club_id`) and a `uuid` per CLAUDE.md §4. Forward-only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0208_saved_filter_presets';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_saved_filters (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            report_key VARCHAR(64) NOT NULL,
            name VARCHAR(191) NOT NULL,
            filters_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uuid (uuid),
            KEY user_report (club_id, user_id, report_key)
        ) {$charset}" );
    }

    public function down(): void {
        // Forward-only.
    }
};
