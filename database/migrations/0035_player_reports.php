<?php
/**
 * Migration 0035 — Scout report persistence (#0014 Sprint 5).
 *
 * Stores generated scout reports for the two access paths:
 *
 *   - Emailed one-time link: a 64-char token grants direct access
 *     until expiry or revocation. Photos are base64-inlined in the
 *     stored HTML so the link page has no upload-dir dependency.
 *   - Internal scout account: each scout-side view writes a row with
 *     scout_user_id set, expires_at NULL (revoked when the assignment
 *     is removed).
 *
 * Idempotent. Adds the table only if it doesn't exist.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0035_player_reports';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$p}tt_player_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id BIGINT UNSIGNED NOT NULL,
            generated_by BIGINT UNSIGNED NOT NULL,
            audience VARCHAR(48) NOT NULL,
            config_json LONGTEXT NOT NULL,
            rendered_html LONGTEXT NOT NULL,
            access_token VARCHAR(64) DEFAULT NULL,
            scout_user_id BIGINT UNSIGNED DEFAULT NULL,
            recipient_email VARCHAR(255) DEFAULT NULL,
            cover_message TEXT DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            first_accessed_at DATETIME DEFAULT NULL,
            access_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_player (player_id),
            KEY idx_token (access_token),
            KEY idx_scout (scout_user_id),
            KEY idx_expiry (expires_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
};
