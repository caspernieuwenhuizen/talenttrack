<?php
/**
 * Migration 0159 — `tt_holidays` table (#1480).
 *
 * Academy-wide holiday periods surfaced on every team planner. A
 * dedicated table (not `tt_activities`) — holidays are global, dateless
 * of any team, and one-off (no recurrence engine in v1).
 *
 * Carries the SaaS tenancy scaffold (`club_id` default 1) + a `uuid`
 * for portable identity + standard audit + soft-archive columns, per
 * the repo's SaaS-ready-by-construction rule.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0159_holidays_schema';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_holidays (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            name VARCHAR(255) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            note TEXT NULL,
            color VARCHAR(16) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            archived_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_uuid (uuid),
            KEY idx_club (club_id),
            KEY idx_range (start_date, end_date),
            KEY idx_archived (archived_at)
        ) {$charset}" );
    }

    public function down(): void {
        // Forward-only.
    }
};
