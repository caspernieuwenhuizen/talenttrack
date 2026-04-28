<?php
/**
 * Migration 0038 — SaaS-readiness tenancy scaffold (#0052 PR-A).
 *
 * Adds `club_id INT UNSIGNED NOT NULL DEFAULT 1` to every tenant-scoped
 * `tt_*` table that doesn't already have one, and `uuid CHAR(36) UNIQUE`
 * to the five root entities:
 *
 *   - tt_players
 *   - tt_teams
 *   - tt_evaluations
 *   - tt_activities (the renamed-to name from migration 0027)
 *   - tt_goals
 *
 * Idempotent: every column add is gated on `SHOW COLUMNS` so re-running
 * is a no-op. Tables that don't exist on this install (e.g. trial
 * subordinates if Trials migration didn't run yet) are skipped via
 * `SHOW TABLES LIKE`.
 *
 * UUID backfill walks rows in 500-row batches and sets uuid =
 * wp_generate_uuid4() where NULL or empty. After backfill the column
 * is left as VARCHAR(36) DEFAULT NULL with a UNIQUE index — we do NOT
 * promote it to NOT NULL because dbDelta on subsequent migrations may
 * recreate the table without the constraint, and the UNIQUE index +
 * application-level enforcement (every INSERT carries a fresh uuid via
 * wp_generate_uuid4) is sufficient.
 *
 * The `tt_config` schema reshape (add club_id + composite UNIQUE +
 * wp_options copy) lives separately in 0039_tt_config_tenancy.php so
 * the two concerns can be reverted independently.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0038_tenancy_scaffold';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $this->addClubIdColumn( $this->tablesNeedingClubId() );
        $this->addUuidColumn( $this->rootEntities() );
    }

    /**
     * Tables that hold tenant-scoped data and need a `club_id` column.
     * Tables already shipping with one (post-#0017 trial cases, post-#0053
     * journey events) are deliberately omitted — their migrations carry
     * the column from creation.
     *
     * @return list<string>
     */
    private function tablesNeedingClubId(): array {
        return [
            // Core domain
            'tt_players',
            'tt_teams',
            'tt_people',
            'tt_team_people',
            'tt_user_role_scopes',
            'tt_player_parents',
            'tt_player_team_history',

            // Performance + activity
            'tt_evaluations',
            'tt_eval_ratings',
            'tt_eval_categories',
            'tt_category_weights',
            'tt_activities',
            'tt_attendance',
            'tt_goals',
            'tt_goal_links',
            'tt_seasons',

            // PDP
            'tt_pdp_files',
            'tt_pdp_conversations',
            'tt_pdp_verdicts',
            'tt_pdp_calendar_links',

            // Reports / chemistry / formations
            'tt_player_reports',
            'tt_report_presets',
            'tt_team_formations',
            'tt_team_playing_styles',
            'tt_formation_templates',
            'tt_team_chemistry_pairings',

            // Custom fields + invitations + audit + authorization
            'tt_custom_fields',
            'tt_custom_values',
            'tt_invitations',
            'tt_audit_log',
            'tt_authorization_changelog',
            'tt_authorization_matrix',

            // Workflow
            'tt_workflow_tasks',
            'tt_workflow_triggers',
            'tt_workflow_event_log',
            'tt_workflow_template_config',

            // Per-club configuration tables
            'tt_module_state',
            'tt_dev_ideas',
            'tt_dev_tracks',
            'tt_functional_roles',
            'tt_functional_role_auth_roles',
            'tt_roles',
            'tt_role_permissions',

            // Methodology — admin-extensible per club
            'tt_methodology_assets',
            'tt_methodology_framework_primers',
            'tt_methodology_influence_factors',
            'tt_methodology_learning_goals',
            'tt_methodology_phases',
            'tt_methodology_principle_links',
            'tt_methodology_visions',
            'tt_principles',
            'tt_set_pieces',
            'tt_football_actions',
            'tt_formation_positions',
            'tt_formations',
            'tt_session_principles',

            // Lookups — admin-extensible per club; SaaS migration will need
            // per-tenant lookup overrides
            'tt_lookups',

            // Trial subordinates (cases + tracks + letter_templates already
            // have club_id from #0017 migration)
            'tt_trial_case_staff',
            'tt_trial_extensions',
            'tt_trial_case_staff_inputs',

            // Translations (per-tenant detected source language + caches)
            'tt_translation_source_meta',
            'tt_translations_cache',
            'tt_translations_usage',

            // Usage telemetry — separate per-club rollups in SaaS
            'tt_usage_events',
            'tt_demo_tags',
        ];
    }

    /**
     * Root entities that get a `uuid CHAR(36) UNIQUE` column. The five
     * named in CLAUDE.md § 3 + the spec's § Decisions locked Q2.
     *
     * @return list<string>
     */
    private function rootEntities(): array {
        return [
            'tt_players',
            'tt_teams',
            'tt_evaluations',
            'tt_activities',
            'tt_goals',
        ];
    }

    /**
     * @param list<string> $tables
     */
    private function addClubIdColumn( array $tables ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach ( $tables as $bare ) {
            $table = $p . $bare;
            if ( ! $this->tableExists( $table ) ) continue;
            if ( $this->columnExists( $table, 'club_id' ) ) continue;

            $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `club_id` INT UNSIGNED NOT NULL DEFAULT 1" );

            // Best-effort secondary index on club_id. Some legacy tables
            // already have many indexes; if MySQL refuses (innodb 64-index
            // ceiling, or duplicate name on a fresh-install activator path),
            // we swallow the error — the column is what matters.
            $idx_name = 'idx_club_id';
            $existing_idx = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND INDEX_NAME = %s",
                $table, $idx_name
            ) );
            if ( (int) $existing_idx === 0 ) {
                @$wpdb->query( "ALTER TABLE `$table` ADD INDEX `$idx_name` (`club_id`)" );
            }
        }
    }

    /**
     * @param list<string> $tables
     */
    private function addUuidColumn( array $tables ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach ( $tables as $bare ) {
            $table = $p . $bare;
            if ( ! $this->tableExists( $table ) ) continue;
            if ( $this->columnExists( $table, 'uuid' ) ) {
                $this->backfillUuid( $table );
                continue;
            }

            $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `uuid` VARCHAR(36) DEFAULT NULL" );

            $idx_name = 'uniq_uuid';
            $existing_idx = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND INDEX_NAME = %s",
                $table, $idx_name
            ) );
            if ( (int) $existing_idx === 0 ) {
                @$wpdb->query( "ALTER TABLE `$table` ADD UNIQUE INDEX `$idx_name` (`uuid`)" );
            }

            $this->backfillUuid( $table );
        }
    }

    /**
     * Walk rows where uuid is NULL or empty in 500-row batches. Cheap
     * MySQL guard so a 5,000-row table doesn't lock the DB. Idempotent:
     * already-set rows are skipped on the WHERE clause.
     */
    private function backfillUuid( string $table ): void {
        global $wpdb;

        $batch_size = 500;
        for ( $i = 0; $i < 200; $i++ ) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM `$table` WHERE uuid IS NULL OR uuid = '' LIMIT %d",
                $batch_size
            ) );
            if ( empty( $ids ) ) return;

            foreach ( $ids as $id ) {
                $wpdb->update( $table, [ 'uuid' => wp_generate_uuid4() ], [ 'id' => (int) $id ] );
            }

            // Defensive yield to MySQL between batches.
            usleep( 50000 );
        }
    }

    private function tableExists( string $table ): bool {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        return $found === $table;
    }

    private function columnExists( string $table, string $column ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SHOW COLUMNS FROM `$table` LIKE %s",
            $column
        ) );
        return $row !== null;
    }
};
