<?php
/**
 * Migration 0066 — Prospects entity (#0081 child 1).
 *
 * Two new tables for the front half of the recruitment journey:
 *
 *   tt_prospects        — identity + scouting context + parent contact
 *                         that persist across the workflow chain.
 *                         Status of the journey lives in tt_workflow_tasks,
 *                         NOT on this row (deliberate: avoids dual-state-
 *                         machine drift). The only lifecycle column is
 *                         `archived_at` (soft-delete on terminal outcome).
 *   tt_test_trainings   — the scheduled test-training session a prospect
 *                         is invited to. Many-to-many to prospects through
 *                         workflow tasks (TaskContext carries
 *                         `test_training_id`), not a join table.
 *
 * No `status` column on tt_prospects. Querying "which prospects are at
 * stage X" joins through tt_workflow_tasks. See spec
 * specs/0081-epic-onboarding-pipeline.md "no status on prospect" decision.
 *
 * Idempotent. CREATE TABLE IF NOT EXISTS via dbDelta.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0066_prospects_entity';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $prospects = "CREATE TABLE IF NOT EXISTS {$p}tt_prospects (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) DEFAULT NULL,

            first_name VARCHAR(255) NOT NULL,
            last_name VARCHAR(255) NOT NULL,
            date_of_birth DATE DEFAULT NULL,
            age_group_lookup_id BIGINT UNSIGNED DEFAULT NULL,

            discovered_at DATE NOT NULL,
            discovered_by_user_id BIGINT UNSIGNED NOT NULL,
            discovered_at_event VARCHAR(255) DEFAULT NULL,
            current_club VARCHAR(255) DEFAULT NULL,
            preferred_position_lookup_id BIGINT UNSIGNED DEFAULT NULL,
            scouting_notes TEXT DEFAULT NULL,

            parent_name VARCHAR(255) DEFAULT NULL,
            parent_email VARCHAR(255) DEFAULT NULL,
            parent_phone VARCHAR(50) DEFAULT NULL,
            consent_given_at DATETIME DEFAULT NULL,

            promoted_to_player_id BIGINT UNSIGNED DEFAULT NULL,
            promoted_to_trial_case_id BIGINT UNSIGNED DEFAULT NULL,

            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME DEFAULT NULL,
            archived_by BIGINT UNSIGNED DEFAULT NULL,
            archive_reason VARCHAR(40) DEFAULT NULL,

            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club (club_id),
            KEY idx_discovered_by (discovered_by_user_id, discovered_at),
            KEY idx_age_group (age_group_lookup_id),
            KEY idx_player (promoted_to_player_id),
            KEY idx_trial (promoted_to_trial_case_id),
            KEY idx_archived (archived_at)
        ) $charset;";
        dbDelta( $prospects );

        $test_trainings = "CREATE TABLE IF NOT EXISTS {$p}tt_test_trainings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) DEFAULT NULL,
            date DATETIME NOT NULL,
            location VARCHAR(255) DEFAULT NULL,
            age_group_lookup_id BIGINT UNSIGNED DEFAULT NULL,
            coach_user_id BIGINT UNSIGNED NOT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by BIGINT UNSIGNED NOT NULL,
            archived_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club_date (club_id, date),
            KEY idx_age_group (age_group_lookup_id),
            KEY idx_coach (coach_user_id)
        ) $charset;";
        dbDelta( $test_trainings );
    }
};
