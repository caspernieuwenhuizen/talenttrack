<?php
/**
 * Migration 0076 (#0066) — `tt_comms_inbox` table backing the
 * InappChannelAdapter.
 *
 * One row per recipient × message. Surfaced by the persona-dashboard
 * inbox UI (lands separately) and by a future REST endpoint
 * `GET /comms/inbox` that the front-end polls. Mark-as-read flips
 * `read_at`; a daily prune (lands with the inbox UI) deletes rows
 * older than 90 days that have been read.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS guards the call.
 *
 * Columns:
 *   - `id` PK auto-increment
 *   - `club_id` SaaS-readiness tenancy column (#0052)
 *   - `uuid` mirrors `tt_comms_log.uuid` so inbox + audit trail
 *     cross-reference 1:1
 *   - `created_at` send timestamp
 *   - `recipient_user_id` resolved recipient (after #0042 rules)
 *   - `recipient_player_id` original player context (about-which-player)
 *   - `template_key` which template the send used
 *   - `message_type` discriminator for filtering / opt-out
 *   - `subject` short subject line
 *   - `body` rendered body (HTML or plaintext per template)
 *   - `payload_json` optional structured payload (deep-link target,
 *     etc.) for the front-end to render a richer card
 *   - `read_at` first time the recipient marked read (NULL = unread)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0076_comms_inbox';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset_collate = $wpdb->get_charset_collate();

        $table = "{$p}tt_comms_inbox";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            return;
        }

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            recipient_user_id BIGINT UNSIGNED NOT NULL,
            recipient_player_id BIGINT UNSIGNED DEFAULT NULL,
            template_key VARCHAR(64) NOT NULL,
            message_type VARCHAR(64) NOT NULL,
            subject VARCHAR(255) DEFAULT NULL,
            body MEDIUMTEXT,
            payload_json MEDIUMTEXT,
            read_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_recipient_unread (recipient_user_id, read_at),
            KEY idx_club_created (club_id, created_at),
            KEY idx_uuid (uuid),
            KEY idx_message_type (message_type)
        ) {$charset_collate};" );
    }

};
