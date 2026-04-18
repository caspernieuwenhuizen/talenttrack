<?php
/**
 * Migration: 0002_create_audit_log
 *
 * Creates the tt_audit_log table for tracking user actions and
 * system events. Idempotent (CREATE TABLE IF NOT EXISTS).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0002_create_audit_log';
    }

    public function up(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $sql = "CREATE TABLE IF NOT EXISTS {$p}tt_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED DEFAULT 0,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(64) DEFAULT '',
            entity_id BIGINT UNSIGNED DEFAULT 0,
            payload LONGTEXT,
            ip_address VARCHAR(45) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_action (action),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_created (created_at)
        ) $c;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
};
