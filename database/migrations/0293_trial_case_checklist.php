<?php
/**
 * Migration 0293 — the follow-up checklist on an admitted trial case (#4008).
 *
 * An ADMIT decision used to be followed by nothing the case could show:
 * whether the letter had gone, whether the player was on a team, whether
 * anybody had a guardian's e-mail address was carried in people's heads.
 * `tt_trial_case_checklist` holds one row per follow-up item per case,
 * created when ADMIT is recorded. Each row can name who does it and records
 * who ticked it and when; `done_auto` marks a tick the case made itself when
 * the condition behind the item came true.
 *
 * A table rather than columns on `tt_trial_cases`, so a later decision type
 * can carry its own list without a migration per item. The rows go with
 * their case on purge (`CascadeRegistry`, `PlayerDeletionCascade`).
 *
 * `club_id` and `uuid` per CLAUDE.md §4. Idempotent: CREATE TABLE IF NOT
 * EXISTS. Forward-only. Run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0293_trial_case_checklist';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();
        $table   = "{$p}tt_trial_case_checklist";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) DEFAULT NULL,
            trial_case_id BIGINT UNSIGNED NOT NULL,
            item_key VARCHAR(40) NOT NULL,
            assignee_user_id BIGINT UNSIGNED DEFAULT NULL,
            done_at DATETIME DEFAULT NULL,
            done_by BIGINT UNSIGNED DEFAULT NULL,
            done_auto TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_case_item (trial_case_id, item_key),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club_case (club_id, trial_case_id)
        ) {$charset};" );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        $this->report( $exists ? 1 : 0, $exists ? 'tt_trial_case_checklist present' : 'tt_trial_case_checklist could not be created' );
    }
};
