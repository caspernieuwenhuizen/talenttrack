<?php
/**
 * Migration 0011 — Usage events (v2.18.0).
 *
 * Adds the tt_usage_events table. Captures login + page-view events
 * for app-usage analytics. 90-day retention enforced by a daily
 * WP-Cron prune job (see UsageTracker::pruneOldEvents).
 *
 * Intentionally lightweight schema: user id + event type + optional
 * target string + timestamp. No IP addresses, no user agents, no
 * fingerprinting. Admin-only visibility of the aggregated metrics.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0011_usage_events';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_usage_events" ) ) === "{$p}tt_usage_events" ) {
            return;
        }

        $charset = $wpdb->get_charset_collate();
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_usage_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            event_target VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_time (user_id, created_at),
            KEY idx_type_time (event_type, created_at),
            KEY idx_created_at (created_at)
        ) {$charset}" );
    }
};
