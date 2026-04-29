<?php
/**
 * Migration 0046 — Push subscriptions table (#0042 Sprint 3).
 *
 * One row per (user, device) Web Push subscription. The browser
 * generates a unique `endpoint` URL on `pushManager.subscribe()`;
 * rotation = a fresh row, the old endpoint times out via the
 * 90-day inactivity prune.
 *
 * Carries `club_id` for the SaaS-readiness scaffold (#0052 PR-A)
 * and a `uuid` per CLAUDE.md § 4 root-entity rule. Idempotent.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0046_push_subscriptions';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$p}tt_push_subscriptions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) DEFAULT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            endpoint VARCHAR(500) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth_secret VARCHAR(255) NOT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_endpoint (endpoint(191)),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_user (user_id, last_seen_at),
            KEY idx_club (club_id)
        ) $c;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function down(): void {
        // No-op. Schema migrations are forward-only in this project.
    }
};
