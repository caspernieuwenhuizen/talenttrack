<?php
/**
 * Migration 0027 — Rename sessions → activities + add typed activities (#0035).
 *
 * The vocabulary flip across the storage layer:
 *
 *  - tt_sessions                       → tt_activities
 *  - tt_attendance.session_id          → tt_attendance.activity_id
 *  - tt_evaluations.match_result       → tt_evaluations.game_result
 *  - tt_lookups[lookup_type=competition_type] → tt_lookups[lookup_type=game_subtype]
 *  - tt_view_sessions / tt_edit_sessions caps → tt_view_activities / tt_edit_activities
 *  - tt_workflow_*[template_key=post_match_evaluation] → post_game_evaluation
 *  - tt_workflow_triggers[event_hook=tt_session_completed] → tt_activity_completed
 *
 * Plus three new columns on `tt_activities`:
 *
 *  - activity_type_key   VARCHAR(50)   default 'training'
 *  - game_subtype_key    VARCHAR(50)   nullable
 *  - other_label         VARCHAR(120)  nullable
 *
 * The `activity_type` lookup is seeded with three rows (game / training /
 * other); the `game_subtype` lookup adds `Friendly` to the existing
 * `League` + `Cup` rows. `other` is a hard-coded special case in the
 * display layer — when activity_type_key='other', the form requires the
 * free-text `other_label`; admin-extended types use their lookup row's
 * name instead.
 *
 * Idempotent: every step checks current state before applying. Safe to
 * re-run on hosts where the runner double-ticks.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0027_rename_sessions_to_activities';
    }

    public function up(): void {
        $this->renameTables();
        $this->addActivityTypeColumns();
        $this->renameEvaluationColumn();
        $this->seedActivityTypeLookup();
        $this->renameCompetitionTypeLookup();
        $this->renameCapabilities();
        $this->rewriteWorkflowRows();
        $this->seedPostGameEvaluationTrigger();
        $this->scheduleAdminNotice();
    }

    /**
     * Add an event-type trigger row that subscribes the post-game
     * evaluation template to `tt_activity_completed`. The template's
     * own `expandTrigger()` filters on activity_type_key='game' so
     * trainings + other types do not spawn tasks.
     *
     * Idempotent: insert is gated on (template_key + event_hook) absence.
     */
    private function seedPostGameEvaluationTrigger(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $triggers_table = "{$p}tt_workflow_triggers";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $triggers_table ) ) !== $triggers_table ) {
            return;
        }

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$triggers_table}
              WHERE template_key = %s AND event_hook = %s",
            'post_game_evaluation',
            'tt_activity_completed'
        ) );
        if ( $existing > 0 ) return;

        $wpdb->insert( $triggers_table, [
            'template_key' => 'post_game_evaluation',
            'trigger_type' => 'event',
            'event_hook'   => 'tt_activity_completed',
            'enabled'      => 1,
            'created_at'   => current_time( 'mysql' ),
        ] );
    }

    /**
     * tt_sessions → tt_activities; tt_attendance.session_id → activity_id.
     * Idempotent: only renames if the source name still exists.
     */
    private function renameTables(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $sessions_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_sessions" ) ) === "{$p}tt_sessions";
        $activities_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_activities" ) ) === "{$p}tt_activities";

        if ( $sessions_exists && ! $activities_exists ) {
            $wpdb->query( "ALTER TABLE {$p}tt_sessions RENAME TO {$p}tt_activities" );
        }

        // Column rename on tt_attendance — only if session_id still exists.
        $attendance_table = "{$p}tt_attendance";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $attendance_table ) ) === $attendance_table ) {
            $col = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'session_id'",
                $attendance_table
            ) );
            if ( $col === 'session_id' ) {
                $wpdb->query( "ALTER TABLE {$attendance_table} CHANGE session_id activity_id BIGINT UNSIGNED NOT NULL" );
                // Rename the index too if it still references the old name.
                $idx = $wpdb->get_var( $wpdb->prepare(
                    "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_session'",
                    $attendance_table
                ) );
                if ( $idx === 'idx_session' ) {
                    $wpdb->query( "ALTER TABLE {$attendance_table} DROP INDEX idx_session" );
                    $wpdb->query( "ALTER TABLE {$attendance_table} ADD KEY idx_activity (activity_id)" );
                }
                // #0026 — the session-scoped guest index too.
                $guest_idx = $wpdb->get_var( $wpdb->prepare(
                    "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_session_guest'",
                    $attendance_table
                ) );
                if ( $guest_idx === 'idx_session_guest' ) {
                    $wpdb->query( "ALTER TABLE {$attendance_table} DROP INDEX idx_session_guest" );
                    $wpdb->query( "ALTER TABLE {$attendance_table} ADD KEY idx_activity_guest (activity_id, is_guest)" );
                }
            }
        }
    }

    /**
     * Add activity_type_key + game_subtype_key + other_label columns.
     * All additive + nullable (or with safe defaults). Existing rows
     * default to 'training'.
     */
    private function addActivityTypeColumns(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_activities";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $cols = [
            'activity_type_key' => "VARCHAR(50) NOT NULL DEFAULT 'training'",
            'game_subtype_key'  => "VARCHAR(50) DEFAULT NULL",
            'other_label'       => "VARCHAR(120) DEFAULT NULL",
        ];

        foreach ( $cols as $name => $defn ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table,
                $name
            ) );
            if ( $exists === null ) {
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$defn}" );
            }
        }

        // Backfill safety net: explicit UPDATE for any pre-existing rows
        // that landed before the DEFAULT applied.
        $wpdb->query( "UPDATE {$table} SET activity_type_key = 'training' WHERE activity_type_key IS NULL OR activity_type_key = ''" );

        // Index for type-filtered queries (HoD review form, list filter chips).
        $idx = $wpdb->get_var( $wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_activity_type'",
            $table
        ) );
        if ( $idx === null ) {
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_activity_type (activity_type_key)" );
        }
    }

    /**
     * tt_evaluations.match_result → game_result. Idempotent.
     */
    private function renameEvaluationColumn(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_evaluations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }
        $col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'match_result'",
            $table
        ) );
        if ( $col === 'match_result' ) {
            $wpdb->query( "ALTER TABLE {$table} CHANGE match_result game_result VARCHAR(50) DEFAULT NULL" );
        }
    }

    /**
     * Seed three rows in tt_lookups[lookup_type=activity_type]: game,
     * training, other. Skips rows that already exist by name+type.
     */
    private function seedActivityTypeLookup(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_lookups";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $rows = [
            [ 'game',     'A match — friendly, cup, or league.',                                  1 ],
            [ 'training', 'A training session — the default for most activities.',                 2 ],
            [ 'other',    'Anything else (team-building, club meeting, tournament). Free-form.',   3 ],
        ];

        foreach ( $rows as $row ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE lookup_type = %s AND name = %s",
                'activity_type',
                $row[0]
            ) );
            if ( $existing === 0 ) {
                $wpdb->insert( $table, [
                    'lookup_type' => 'activity_type',
                    'name'        => $row[0],
                    'description' => $row[1],
                    'sort_order'  => $row[2],
                ] );
            }
        }
    }

    /**
     * Rename competition_type lookup to game_subtype + add Friendly row.
     * Existing League + Cup rows survive with their lookup_type updated.
     */
    private function renameCompetitionTypeLookup(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_lookups";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Step 1: rename existing rows (idempotent — re-running just no-ops).
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET lookup_type = %s WHERE lookup_type = %s",
            'game_subtype',
            'competition_type'
        ) );

        // Step 2: ensure Friendly row exists.
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE lookup_type = %s AND name = %s",
            'game_subtype',
            'Friendly'
        ) );
        if ( $existing === 0 ) {
            $wpdb->insert( $table, [
                'lookup_type' => 'game_subtype',
                'name'        => 'Friendly',
                'description' => 'Pre-season friendly or non-competitive match.',
                'sort_order'  => 0,
            ] );
        }
    }

    /**
     * Grant the new caps to every role currently holding the old ones,
     * then revoke the old caps. Idempotent.
     */
    private function renameCapabilities(): void {
        $map = [
            'tt_view_sessions' => 'tt_view_activities',
            'tt_edit_sessions' => 'tt_edit_activities',
        ];

        $all_roles = wp_roles();
        if ( ! $all_roles ) return;

        foreach ( $all_roles->roles as $slug => $_def ) {
            $role = get_role( $slug );
            if ( ! $role ) continue;
            foreach ( $map as $old => $new ) {
                if ( $role->has_cap( $old ) && ! $role->has_cap( $new ) ) {
                    $role->add_cap( $new );
                }
                if ( $role->has_cap( $old ) ) {
                    $role->remove_cap( $old );
                }
            }
        }
    }

    /**
     * Update workflow rows that reference the old template_key
     * (post_match_evaluation) or the old event_hook (tt_session_completed).
     * Covers triggers, template_config, and any pending tasks. Safe to
     * run multiple times.
     */
    private function rewriteWorkflowRows(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Triggers — both template_key and event_hook may need updating.
        $triggers_table = "{$p}tt_workflow_triggers";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $triggers_table ) ) === $triggers_table ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$triggers_table} SET template_key = %s WHERE template_key = %s",
                'post_game_evaluation',
                'post_match_evaluation'
            ) );
            $event_col = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'event_hook'",
                $triggers_table
            ) );
            if ( $event_col === 'event_hook' ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$triggers_table} SET event_hook = %s WHERE event_hook = %s",
                    'tt_activity_completed',
                    'tt_session_completed'
                ) );
            }
        }

        // Template config.
        $config_table = "{$p}tt_workflow_template_config";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $config_table ) ) === $config_table ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$config_table} SET template_key = %s WHERE template_key = %s",
                'post_game_evaluation',
                'post_match_evaluation'
            ) );
        }

        // Existing tasks — pending or completed. Forensic consistency.
        $tasks_table = "{$p}tt_workflow_tasks";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tasks_table ) ) === $tasks_table ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$tasks_table} SET template_key = %s WHERE template_key = %s",
                'post_game_evaluation',
                'post_match_evaluation'
            ) );
        }
    }

    /**
     * Drop a one-time transient flag so the next admin load shows the
     * "X activities migrated from sessions" notice. Cleared on dismiss.
     */
    private function scheduleAdminNotice(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_activities WHERE activity_type_key = 'training'" );
        set_transient( 'tt_activities_migrated_notice', $count, 30 * DAY_IN_SECONDS );
    }
};
