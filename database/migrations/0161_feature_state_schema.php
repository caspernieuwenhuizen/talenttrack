<?php
/**
 * Migration 0161 — `tt_feature_state` table (#1485).
 *
 * Sub-feature flags within a module. Mirrors `tt_module_state` one
 * level finer: a row toggles a single surface (e.g. Cohort transitions)
 * without disabling its parent module. Carries the SaaS tenancy scaffold
 * (`club_id` default 1) so a future multi-tenant install can hold
 * per-club feature state. Forward-only; a feature with no row falls back
 * to its catalogued default in FeatureRegistry.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0161_feature_state_schema';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_feature_state (
            feature_key VARCHAR(64) NOT NULL,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (feature_key, club_id)
        ) {$charset}" );
    }

    public function down(): void {
        // Forward-only.
    }
};
