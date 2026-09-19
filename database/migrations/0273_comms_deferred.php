<?php
/**
 * Migration 0273 — the queue behind "Held until morning" (#3646).
 *
 * A non-urgent message sent inside the quiet-hours window used to be logged
 * as `quiet_hours` and dropped: nothing sent it later, while the message log,
 * the compose-screen warning and the docs all said it would go out in the
 * morning. `tt_comms_deferred` holds the one thing the log cannot: the
 * message itself.
 *
 * WHY THE REQUEST AND NOT THE RENDERED MESSAGE
 *
 * The log keeps a SHA-256 of the body, never the body, so it cannot be the
 * source of a re-send. This table holds the *unrendered* request — template
 * key, message type, payload tokens, the flags — plus the one recipient it
 * was addressed to. The message is rendered at send time, so a template the
 * club edited overnight goes out as edited, and an opt-out or a template
 * switched off overnight is honoured.
 *
 * WHY IT IS SHORT-LIVED
 *
 * A row lives from the moment quiet hours catch a message until the first
 * heartbeat after the window ends, when it is sent and deleted. `expires_at`
 * is `created_at + 24h`: a message caught at 21:00 should have gone by 08:00,
 * so anything older means the heartbeat is not running, and the sweep marks
 * the log row failed and deletes the queue row rather than sending yesterday's
 * news. There is deliberately no attachment column: a request carrying a file
 * is refused at enqueue, because keeping a report about minors on disk
 * overnight is the wrong trade.
 *
 * `log_uuid` is the `tt_comms_log.uuid` of the row the send updates — one
 * message, one log row, `attempt` going from 1 to 2.
 *
 * Timestamps are written by the application in UTC rather than by
 * `CURRENT_TIMESTAMP`, so expiry does not depend on the database server's
 * time zone (#3696).
 *
 * `club_id` per CLAUDE.md §4. No `uuid` of its own: this is a queue, not a
 * record anybody refers to, and `log_uuid` is its public key. Idempotent:
 * CREATE TABLE IF NOT EXISTS. Forward-only. Run alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0273_comms_deferred';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_comms_deferred (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            log_uuid CHAR(36) NOT NULL,
            request_json LONGTEXT NOT NULL,
            recipient_json TEXT NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_log_uuid (log_uuid),
            KEY idx_club_created (club_id, created_at)
        ) {$charset};" );
    }
};
