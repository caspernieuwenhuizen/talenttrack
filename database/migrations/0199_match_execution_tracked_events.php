<?php
/**
 * Migration 0199 — tracked development-action events.
 *
 * Append-only, soft-delete (reversed_at) timed log mirroring
 * tt_match_execution_goal_events. One row per coach tap of the +/- counter
 * on a prep-flagged ("tracked") player during a live match. These are
 * individual development actions (e.g. "runs in behind", "duels won"), NOT
 * the score — goals live in tt_match_execution_goal_events.
 *
 * action_label is denormalized from the prep attention_text at log time so
 * historical reports stay stable even if the prep text is later edited or
 * the prep row is deleted. action_key is reserved for a future normalized
 * vocabulary. event_uuid is the offline-queue idempotency key (INSERT IGNORE
 * on replay).
 *
 * SaaS conventions: club_id DEFAULT 1 (tenancy scaffold). Append-only log
 * rows are not root entities, so — like the sibling goal_events /
 * substitutions tables — they carry event_uuid but no separate uuid root
 * column. Idempotent CREATE TABLE IF NOT EXISTS. Forward-only. Run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0199_match_execution_tracked_events';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_match_execution_tracked_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_uuid CHAR(36) NOT NULL,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            execution_id BIGINT UNSIGNED NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            half TINYINT UNSIGNED NOT NULL,
            minute_in_half SMALLINT UNSIGNED NOT NULL,
            action_key VARCHAR(64) DEFAULT NULL,
            action_label VARCHAR(191) NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            reversed_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_event (event_uuid),
            KEY idx_exec_player (execution_id, player_id),
            KEY idx_club (club_id)
        ) {$charset}" );
    }
};
