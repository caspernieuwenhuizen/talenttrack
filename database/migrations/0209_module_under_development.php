<?php
/**
 * Migration 0209 — `tt_module_state.under_development` (#2409).
 *
 * The module-level twin of migration 0207's per-feature flag. Cosmetic and
 * independent of `enabled`: a module can be fully live yet marked "under
 * development" so every view it owns shows an informational pill and every
 * dashboard tile it owns shows a badge. Defaults to 0; forward-only.
 * Idempotent — skips the ADD when the column already exists.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0209_module_under_development';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_module_state";

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
