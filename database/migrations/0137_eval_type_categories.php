<?php
/**
 * Migration 0137 — eval type → category mapping (#819).
 *
 * Join table that maps an `eval_type` lookup row to the list of
 * `tt_eval_categories` rows that should surface when that type is
 * selected. Empty mapping for a type = "all active categories"
 * (back-compat with pre-#819 behaviour, no operator action needed).
 *
 * SaaS-readiness: carries `club_id` even though every install today
 * runs single-tenant; the read/write helpers filter on it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0137_eval_type_categories';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS {$p}tt_eval_type_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            eval_type_id BIGINT UNSIGNED NOT NULL,
            eval_category_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_pair (club_id, eval_type_id, eval_category_id),
            KEY idx_type (eval_type_id),
            KEY idx_category (eval_category_id)
        ) {$charset};";

        dbDelta( $sql );
    }

    public function down(): void {
        // Forward-only. Reverting would lose operator-configured
        // mappings. Drop manually if rolling back to pre-#819.
    }
};
