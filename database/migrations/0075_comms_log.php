<?php
/**
 * Migration 0075 (#0066) — `tt_comms_log` audit table for the
 * Communication module.
 *
 * One row per Comms send attempt. Captures who sent what to whom, on
 * which channel, with what status, and the resolved recipient + payload
 * hash. Used by the operator-facing "did the parents actually get the
 * cancellation message?" query path and by the GDPR-retention cron
 * (default 18 months per spec Q6 lean — configurable per club).
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS guards the call.
 *
 * Columns:
 *   - `id` PK auto-increment
 *   - `club_id` SaaS-readiness tenancy column (#0052)
 *   - `uuid` per-send identifier
 *   - `created_at` send timestamp
 *   - `template_key` which template the send used
 *   - `message_type` short discriminator for opt-out + retention scoping
 *   - `channel` resolved channel ('push' / 'email' / 'sms' /
 *     'whatsapp_link' / 'inapp')
 *   - `sender_user_id` who initiated (or 0 for system sends)
 *   - `recipient_user_id` resolved recipient (after #0042 youth-contact
 *     rules apply)
 *   - `recipient_player_id` original player context (for "about which
 *     player")
 *   - `recipient_kind` 'self' / 'parent' / 'coach' / 'system'
 *   - `address_blob` channel-specific address (email / phone /
 *     device-token); kept short, post-GDPR-erasure becomes empty
 *   - `subject` short subject line (email subject; null for push / sms)
 *   - `payload_hash` SHA-256 of message body (audit-without-PII)
 *   - `status` 'queued' / 'sent' / 'delivered' / 'bounced' / 'failed'
 *     / 'opted_out' / 'quiet_hours' / 'rate_limited'
 *   - `error_code` short failure key for failures
 *   - `attempt` int — 1-based attempt counter for retried sends
 *   - `attached_export_id` optional FK to a future tt_exports row
 *     (#0063 hand-off; nullable)
 *   - `subject_erased_at` GDPR right-to-erasure tombstone
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0075_comms_log';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset_collate = $wpdb->get_charset_collate();

        $table = "{$p}tt_comms_log";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            return;
        }

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            template_key VARCHAR(64) NOT NULL,
            message_type VARCHAR(64) NOT NULL,
            channel VARCHAR(32) NOT NULL,
            sender_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            recipient_user_id BIGINT UNSIGNED DEFAULT NULL,
            recipient_player_id BIGINT UNSIGNED DEFAULT NULL,
            recipient_kind VARCHAR(16) NOT NULL DEFAULT 'self',
            address_blob VARCHAR(255) NOT NULL DEFAULT '',
            subject VARCHAR(255) DEFAULT NULL,
            payload_hash CHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'queued',
            error_code VARCHAR(64) DEFAULT NULL,
            attempt SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            attached_export_id BIGINT UNSIGNED DEFAULT NULL,
            subject_erased_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club_created (club_id, created_at),
            KEY idx_recipient_user (recipient_user_id),
            KEY idx_recipient_player (recipient_player_id),
            KEY idx_template_status (template_key, status),
            KEY idx_message_type (message_type),
            KEY idx_status_created (status, created_at)
        ) {$charset_collate};" );
    }

};
