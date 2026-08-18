<?php
/**
 * Migration 0213 — training plan foundation (#2496, Training epic #2493).
 *
 * Five tables. The split between them is the epic's load-bearing design
 * decision (D3 + D5), so it is worth stating here rather than only in the
 * spec:
 *
 *   tt_training_plans          the TEMPLATE. Reusable, team-shaped or
 *                              club-wide, mutable, no player data.
 *   tt_training_plan_blocks    its ordered rows, each pinned to a specific
 *                              tt_exercises row.
 *   tt_training_plan_runs      one EXECUTION of a plan against one activity
 *                              on one date. This is the player-bearing
 *                              record, and the only place history lives.
 *   tt_training_plan_run_blocks what actually happened per block — real
 *                              durations, skips, notes.
 *   tt_training_plan_principles which methodology principles a plan covers,
 *                              derived from its blocks' exercises plus any
 *                              the coach pins manually.
 *
 * D5 — plans carry NO version chain. A coach nudges a plan's durations
 * repeatedly; versioning that would produce churn and force a
 * `superseded_by_id IS NULL` filter into every list query. Instead the run
 * snapshots its blocks at attach time and never rewrites them, so editing
 * a plan can never rewrite what a past session contained. `exercise_id` on
 * a block still pins a specific tt_exercises row, which is separately
 * versioned, so editing an *exercise* cannot rewrite a past plan either.
 *
 * A plan is not a player record. The run is. That separation is what keeps
 * a reusable template from quietly becoming team-centric — see the epic's
 * player-centricity section.
 *
 * Per CLAUDE.md §4 every table carries club_id and the root entities carry
 * uuid, both unused today and both what separates an easy SaaS migration
 * from rewriting every query.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0213_training_plans_foundation';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // The template. Mutable by design (D5) — history lives in the runs.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_training_plans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            title VARCHAR(190) NOT NULL,
            notes TEXT NULL,
            team_id BIGINT UNSIGNED DEFAULT NULL,
            age_group_key VARCHAR(40) DEFAULT NULL,
            season_id BIGINT UNSIGNED DEFAULT NULL,
            theme_key VARCHAR(64) DEFAULT NULL,
            total_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            intensity_target TINYINT UNSIGNED DEFAULT NULL,
            is_template TINYINT(1) NOT NULL DEFAULT 0,
            visibility VARCHAR(20) NOT NULL DEFAULT 'club',
            source VARCHAR(20) NOT NULL DEFAULT 'manual',
            author_user_id BIGINT UNSIGNED DEFAULT NULL,
            archived_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club_active (club_id, archived_at),
            KEY idx_club_team (club_id, team_id, archived_at),
            KEY idx_club_template (club_id, is_template, archived_at),
            KEY idx_author (author_user_id)
        ) $charset;" );

        // Ordered rows of a plan. exercise_id pins a specific tt_exercises
        // row (itself versioned), so editing an exercise never rewrites a
        // plan that already used it. NULL exercise_id is a free-text block
        // — a team talk, a walk-through, a drill not yet in the library.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_training_plan_blocks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            plan_id BIGINT UNSIGNED NOT NULL,
            order_index SMALLINT UNSIGNED NOT NULL,
            block_type VARCHAR(24) NOT NULL DEFAULT 'main',
            exercise_id BIGINT UNSIGNED DEFAULT NULL,
            title_override VARCHAR(190) DEFAULT NULL,
            organisation TEXT NULL,
            coaching_points TEXT NULL,
            duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            intensity_band TINYINT UNSIGNED DEFAULT NULL,
            players_min TINYINT UNSIGNED DEFAULT NULL,
            players_max TINYINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            UNIQUE KEY uk_plan_order (club_id, plan_id, order_index),
            KEY idx_plan (plan_id),
            KEY idx_exercise (exercise_id)
        ) $charset;" );

        // Which principles a plan covers. Derived rows (is_manual = 0) are
        // rebuilt from the blocks' exercises whenever the blocks change;
        // manual rows (is_manual = 1) are the coach's own pins and survive
        // that rebuild. Keeping both in one table means the planning
        // surfaces ask one question, not two.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_training_plan_principles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            plan_id BIGINT UNSIGNED NOT NULL,
            principle_id BIGINT UNSIGNED NOT NULL,
            is_manual TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_plan_principle (club_id, plan_id, principle_id),
            KEY idx_principle (principle_id)
        ) $charset;" );

        // One execution of a plan against one activity. The player-bearing
        // record: attendance on the activity plus these blocks is what
        // yields per-player principle exposure in wave 7.
        //
        // blocks_snapshot_json is written once, at attach time, and never
        // rewritten. UNIQUE (club_id, activity_id) enforces one run per
        // activity at the database rather than trusting the caller.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_training_plan_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            plan_id BIGINT UNSIGNED NOT NULL,
            activity_id BIGINT UNSIGNED NOT NULL,
            team_id BIGINT UNSIGNED DEFAULT NULL,
            run_date DATE NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'planned',
            blocks_snapshot_json LONGTEXT NULL,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            UNIQUE KEY uk_club_activity (club_id, activity_id),
            KEY idx_club_plan (club_id, plan_id),
            KEY idx_club_team_date (club_id, team_id, run_date),
            KEY idx_status (club_id, status)
        ) $charset;" );

        // What actually happened, per block. plan_block_id is nullable so a
        // run survives its plan being edited or a block being deleted — the
        // snapshot is authoritative, this row is the delta against it.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_training_plan_run_blocks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            run_id BIGINT UNSIGNED NOT NULL,
            plan_block_id BIGINT UNSIGNED DEFAULT NULL,
            order_index SMALLINT UNSIGNED NOT NULL,
            actual_duration_minutes SMALLINT UNSIGNED DEFAULT NULL,
            was_skipped TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_run_order (club_id, run_id, order_index),
            KEY idx_run (run_id),
            KEY idx_plan_block (plan_block_id)
        ) $charset;" );
    }
};
