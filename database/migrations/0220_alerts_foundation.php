<?php
/**
 * Migration 0220 — alerts foundation (#2631, epic #2629).
 *
 * One table: `tt_alert_occurrences`. A row is one condition, currently
 * true, for one recipient.
 *
 * The distinction that shapes this schema: an alert is not a task. A task
 * is work someone must do and mark done; an alert is a condition that
 * stops being true when the underlying data changes. Nothing here records
 * completion, because there is nothing to complete — a coach marks the
 * activity done in the activities list and the next sweep stamps
 * `resolved_at` without the coach ever touching the alert.
 *
 * That is what `first_seen_at` / `last_seen_at` / `resolved_at` are for.
 * The evaluator reconciles each run against what is stored: seen again →
 * bump `last_seen_at`; new → insert; absent → stamp `resolved_at`. Three
 * columns and one unique key buy self-resolution, per-user read state, and
 * a bounded history, without a state machine.
 *
 * One row per recipient (epic decision 5) rather than one row per subject
 * with the audience resolved at read time. It costs write amplification on
 * the sweep and buys per-user `read_at` / `snoozed_until` / `dismissed_at`
 * with no side table and no authorization join on the hot read path. The
 * recipient is therefore part of `dedupe_key`.
 *
 * `player_id` is denormalised out of the subject so the player-record
 * surface (#2633) can find a player's open alerts without knowing how each
 * definition's subject relates to a player. NULL where the condition is not
 * about a player.
 *
 * No `escalated_task_id` column yet — escalation into Workflow is #2635.
 * It will land as an ALTER rather than being scaffolded here on spec.
 *
 * Retention (epic decision 8) is 90 days on `resolved_at`, enforced by the
 * purge cron in #2634. These rows carry `player_id`, so that is a
 * data-minimisation obligation and not housekeeping. Nothing in this
 * migration expires anything; it only makes expiry indexable.
 *
 * Per CLAUDE.md §4 the table carries `club_id` and `uuid`: `uuid` is the
 * identity the REST surface addresses, `id` is an implementation detail of
 * this database.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0220_alerts_foundation';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // `dedupe_key` is (alert_key + subject + recipient), hashed by the
        // repository so a long subject can't overflow the 191-byte index
        // limit. UNIQUE with club_id is what makes the reconcile an upsert
        // rather than a select-then-branch, and what makes a double sweep
        // idempotent.
        //
        // severity is stored, not derived at read time, because it can
        // escalate with age — the reconcile recomputes it on every run.
        // Deriving it in the read query would mean every surface
        // re-implementing each definition's ageing rule.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_alert_occurrences (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            alert_key VARCHAR(100) NOT NULL DEFAULT '',
            recipient_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            subject_type VARCHAR(32) NOT NULL DEFAULT '',
            subject_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            player_id BIGINT UNSIGNED DEFAULT NULL,
            dedupe_key VARCHAR(191) NOT NULL DEFAULT '',
            severity VARCHAR(16) NOT NULL DEFAULT 'attention',
            payload_json LONGTEXT NULL,
            first_seen_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            resolved_at DATETIME DEFAULT NULL,
            read_at DATETIME DEFAULT NULL,
            snoozed_until DATETIME DEFAULT NULL,
            dismissed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_club_dedupe (club_id, dedupe_key),
            UNIQUE KEY uq_uuid (uuid),
            KEY idx_inbox (recipient_user_id, resolved_at, severity),
            KEY idx_sweep (club_id, alert_key, resolved_at),
            KEY idx_player (player_id, resolved_at),
            KEY idx_retention (resolved_at)
        ) {$charset};" );
    }
};
