<?php
/**
 * Migration 0021 — Workflow & Tasks Engine schema (#0022 Sprint 1).
 *
 * Adds the foundation tables for the orchestration layer:
 *
 *   - tt_workflow_tasks            — task instances (open / in_progress /
 *                                    completed / overdue / skipped /
 *                                    cancelled), one per assignee per
 *                                    template trigger.
 *   - tt_workflow_triggers         — manual / cron / event triggers per
 *                                    template. Sprint 1 lays the table
 *                                    down; live dispatchers ship in
 *                                    Sprint 2.
 *   - tt_workflow_template_config  — per-install overrides (enable flag,
 *                                    cadence override, deadline override,
 *                                    assignee override). One row per
 *                                    template_key.
 *
 * Plus a column addition:
 *   - tt_players.parent_user_id    — nullable FK to wp_users for the
 *                                    minors-assignment policy resolver.
 *                                    Multi-parent support is deferred to
 *                                    Phase 2 via a join table if needed.
 *
 * Plus one config seed:
 *   - tt_config.tt_workflow_minors_assignment_policy = 'age_based'
 *                                    (the most defensible default; can be
 *                                    overridden per install once the
 *                                    Sprint 5 admin UI ships).
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS, INFORMATION_SCHEMA column
 * check, INSERT ... ON DUPLICATE KEY for the config row). No data is
 * seeded into the workflow tables here — the first tasks land in
 * Sprint 3 when Phase 1 templates start firing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0021_workflow_engine';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $sql = [];

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_workflow_tasks (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_key      VARCHAR(64)     NOT NULL,
            assignee_user_id  BIGINT UNSIGNED NOT NULL,
            status            VARCHAR(32)     NOT NULL DEFAULT 'open',
            created_at        DATETIME        DEFAULT CURRENT_TIMESTAMP,
            due_at            DATETIME        NOT NULL,
            completed_at      DATETIME        DEFAULT NULL,
            player_id         BIGINT UNSIGNED DEFAULT NULL,
            team_id           BIGINT UNSIGNED DEFAULT NULL,
            session_id        BIGINT UNSIGNED DEFAULT NULL,
            evaluation_id     BIGINT UNSIGNED DEFAULT NULL,
            goal_id           BIGINT UNSIGNED DEFAULT NULL,
            trial_case_id     BIGINT UNSIGNED DEFAULT NULL,
            parent_task_id    BIGINT UNSIGNED DEFAULT NULL,
            response_json     LONGTEXT        DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_assignee_status (assignee_user_id, status),
            KEY idx_template (template_key),
            KEY idx_due (due_at),
            KEY idx_parent (parent_task_id),
            KEY idx_player (player_id),
            KEY idx_team (team_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_workflow_triggers (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_key      VARCHAR(64)     NOT NULL,
            trigger_type      VARCHAR(32)     NOT NULL,
            cron_expression   VARCHAR(64)     DEFAULT NULL,
            event_hook        VARCHAR(128)    DEFAULT NULL,
            enabled           TINYINT(1)      NOT NULL DEFAULT 1,
            config_json       TEXT            DEFAULT NULL,
            created_at        DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_template (template_key),
            KEY idx_type_enabled (trigger_type, enabled)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_workflow_template_config (
            id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_key                VARCHAR(64)     NOT NULL,
            enabled                     TINYINT(1)      NOT NULL DEFAULT 1,
            cadence_override            VARCHAR(64)     DEFAULT NULL,
            deadline_offset_override    VARCHAR(32)     DEFAULT NULL,
            assignee_override_json      TEXT            DEFAULT NULL,
            updated_at                  DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by                  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_template_key (template_key)
        ) {$charset};";

        foreach ( $sql as $statement ) $wpdb->query( $statement );

        // tt_players.parent_user_id — additive, nullable. Powers the
        // PlayerOrParentResolver's age-based routing in Sprint 1.
        $players_table = $p . 'tt_players';
        $col_exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'parent_user_id'",
            $players_table
        ) );
        if ( $col_exists === 0 ) {
            $wpdb->query( "ALTER TABLE {$players_table} ADD COLUMN parent_user_id BIGINT UNSIGNED DEFAULT NULL" );
            $wpdb->query( "ALTER TABLE {$players_table} ADD KEY idx_parent_user (parent_user_id)" );
        }

        // Seed the minors-assignment policy default. Idempotent: only
        // inserts if the key doesn't already exist (admin may have set
        // it via WP-CLI before activation).
        $config_table = $p . 'tt_config';
        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$config_table} WHERE config_key = %s",
            'tt_workflow_minors_assignment_policy'
        ) );
        if ( $exists === 0 ) {
            $wpdb->insert( $config_table, [
                'config_key'   => 'tt_workflow_minors_assignment_policy',
                'config_value' => 'age_based',
            ] );
        }
    }
};
