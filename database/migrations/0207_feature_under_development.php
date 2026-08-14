<?php
/**
 * Migration 0207 — `tt_feature_state.under_development` (#2387).
 *
 * A cosmetic per-feature flag, independent of `enabled`: a feature can be
 * fully live yet marked "under development" so its views show an
 * informational pill. Defaults to 0 (not under development); forward-only.
 * Idempotent — skips the ADD when the column already exists.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0207_feature_under_development';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_feature_state";

        $exists = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = %s',
            $table,
            'under_development'
        ) );
        if ( (int) $exists > 0 ) return;

        $wpdb->query(
            "ALTER TABLE {$table}
                ADD COLUMN under_development TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled"
        );
    }

    public function down(): void {
        // Forward-only.
    }
};
