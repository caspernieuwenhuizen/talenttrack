<?php
namespace TT\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\MigrationRunner;
use TT\Infrastructure\Security\RolesService;

/**
 * Activator — runs on plugin activation.
 *
 * v2.10.1: Adds a self-healing repair routine (repairFunctionalRoleBackfill)
 * that detects tt_team_people rows where role_in_team is set but
 * functional_role_id is NULL and backfills them directly. This is the
 * belt to migration 0006's suspenders: if the migration ever fails to
 * persist (as it did on at least one host under the pre-2.10.1
 * eval-based migration loader), every subsequent activation catches up.
 * Idempotent — does nothing if all rows are already backfilled. Also
 * removes 0005_authorization_rbac from the pre-applied migrations list
 * (it never had a file on disk) and cleans up its orphan row.
 * v2.10.0 (Sprint 1G): Adds tt_functional_roles and
 * tt_functional_role_auth_roles tables plus a functional_role_id column on
 * tt_team_people. Separates "what is this person's job" (functional role)
 * from "what are they allowed to do" (authorization role). One functional
 * role can map to multiple authorization roles, enabling cases like
 * "Head Coach who also does physio". Seeds 5 system functional roles,
 * a new `team_member` authorization role (for the `other` functional
 * role), and the default 1-to-1 functional→auth mapping.
 * v2.9.0 (Sprint 1F): Adds tt_roles, tt_role_permissions, and
 * tt_user_role_scopes for the data-driven authorization model. Seeds the
 * 9 system roles and their permission matrix on first activation.
 * v2.7.0: Adds tt_people and tt_team_people tables.
 */
class Activator {

    public static function activate(): void {
        self::runMigrations();
        flush_rewrite_rules();
    }

    /**
     * v3.0.0 — extracted from activate() so it can be triggered on
     * demand from the admin UI (Plugins page action link, or the
     * "Run now" button in the schema-out-of-date admin notice).
     *
     * Idempotent: every step is a no-op when the target state is
     * already reached. Safe to run at any time.
     *
     * After successful completion, stores TT_VERSION in the
     * `tt_installed_version` option so the schema-check can detect
     * whether further runs are needed after a code update.
     */
    public static function runMigrations(): void {
        ( new RolesService() )->installRoles();

        // v2.12.1: repair the tt_eval_categories table before ensureSchema
        // creates its real shape. Needed for sites where the 2.12.0 schema
        // ran through dbDelta and silently dropped the `key` column (a MySQL
        // reserved word). The routine detects the corrupt state and drops
        // the table so ensureSchema below can recreate it with the new
        // column name (category_key). Idempotent: no-op if the table has
        // the expected shape or doesn't exist yet.
        self::repairEvalCategoriesTableIfCorrupt();

        self::ensureSchema();
        self::relaxLegacyConstraints();
        self::backfillAttendanceStatus();
        self::markMigrationsApplied();
        self::cleanupOrphanMigrationRecords();
        self::seedRolesIfEmpty();
        self::seedFunctionalRolesIfEmpty();
        self::seedEvalCategoriesIfEmpty();

        try {
            ( new MigrationRunner() )->run();
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[TalentTrack] Migration runner threw during runMigrations(): ' . $e->getMessage() );
            }
        }

        // Self-healing: runs AFTER the migration so fresh installs that
        // ran 0006 successfully also hit it (no-op in that case). Catches
        // up any site where the backfill previously failed silently.
        self::repairFunctionalRoleBackfill();

        update_option( 'tt_installed_version', TT_VERSION );
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    /* ═══════════════════════════════════════════════════════════
     *  Schema: the full authoritative definition of every table
     * ═══════════════════════════════════════════════════════════ */

    private static function ensureSchema(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $queries = [];

        /* ─── Migration tracking ─── */
        $queries[] = "CREATE TABLE {$p}tt_migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration VARCHAR(191) NOT NULL,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_migration (migration)
        ) $c;";

        /* ─── Lookups & config ─── */
        $queries[] = "CREATE TABLE {$p}tt_lookups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lookup_type VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            meta TEXT,
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_type (lookup_type)
        ) $c;";

        $queries[] = "CREATE TABLE {$p}tt_config (
            config_key VARCHAR(191) NOT NULL,
            config_value LONGTEXT,
            PRIMARY KEY (config_key)
        ) $c;";

        /* ─── Teams & players ─── */
        $queries[] = "CREATE TABLE {$p}tt_teams (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            age_group VARCHAR(100) DEFAULT '',
            head_coach_id BIGINT UNSIGNED DEFAULT 0,
            notes TEXT,
            archived_at DATETIME NULL DEFAULT NULL,
            archived_by BIGINT UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_archived_at (archived_at)
        ) $c;";

        $queries[] = "CREATE TABLE {$p}tt_players (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(255) NOT NULL,
            last_name VARCHAR(255) NOT NULL,
            date_of_birth DATE,
            nationality VARCHAR(100) DEFAULT '',
            height_cm SMALLINT UNSIGNED DEFAULT NULL,
            weight_kg SMALLINT UNSIGNED DEFAULT NULL,
            preferred_foot VARCHAR(20) DEFAULT '',
            preferred_positions TEXT,
            jersey_number SMALLINT UNSIGNED DEFAULT NULL,
            team_id BIGINT UNSIGNED DEFAULT 0,
            date_joined DATE,
            photo_url VARCHAR(500) DEFAULT '',
            guardian_name VARCHAR(255) DEFAULT '',
            guardian_email VARCHAR(255) DEFAULT '',
            guardian_phone VARCHAR(50) DEFAULT '',
            wp_user_id BIGINT UNSIGNED DEFAULT 0,
            status VARCHAR(50) DEFAULT 'active',
            archived_at DATETIME NULL DEFAULT NULL,
            archived_by BIGINT UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_team (team_id),
            KEY idx_user (wp_user_id),
            KEY idx_archived_at (archived_at)
        ) $c;";

        /* ─── People & team-staff assignments ─── */
        $queries[] = "CREATE TABLE {$p}tt_people (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(255) NOT NULL,
            last_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            role_type VARCHAR(50) DEFAULT 'other',
            wp_user_id BIGINT UNSIGNED DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'active',
            archived_at DATETIME NULL DEFAULT NULL,
            archived_by BIGINT UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_role_type (role_type),
            KEY idx_wp_user (wp_user_id),
            KEY idx_archived_at (archived_at)
        ) $c;";

        $queries[] = "CREATE TABLE {$p}tt_team_people (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id BIGINT UNSIGNED NOT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            functional_role_id BIGINT UNSIGNED DEFAULT NULL,
            role_in_team VARCHAR(50) NOT NULL,
            start_date DATE DEFAULT NULL,
            end_date DATE DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_team_person_role (team_id, person_id, role_in_team),
            UNIQUE KEY uniq_team_person_fnrole (team_id, person_id, functional_role_id),
            KEY idx_team (team_id),
            KEY idx_person (person_id),
            KEY idx_functional_role (functional_role_id)
        ) $c;";

        /* ─── Functional roles (NEW in v2.10.0, Sprint 1G) ─── */
        // Catalogue of jobs people can hold on a team. Decouples "what is this
        // person's job" from "what are they allowed to do". The mapping table
        // below defines which authorization roles each functional role grants.
        $queries[] = "CREATE TABLE {$p}tt_functional_roles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            role_key VARCHAR(64) NOT NULL,
            label VARCHAR(191) NOT NULL,
            description TEXT,
            is_system TINYINT(1) DEFAULT 0,
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_fnrole_key (role_key)
        ) $c;";

        // Mapping table: functional role → 0..N authorization roles.
        // Default seeding is 1-to-1 but the admin UI can add rows (e.g. a
        // head_coach functional role that also maps to the physio auth role).
        $queries[] = "CREATE TABLE {$p}tt_functional_role_auth_roles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            functional_role_id BIGINT UNSIGNED NOT NULL,
            auth_role_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_fnrole_authrole (functional_role_id, auth_role_id),
            KEY idx_fnrole (functional_role_id),
            KEY idx_authrole (auth_role_id)
        ) $c;";

        /* ─── Roles, permissions, user-role scopes (NEW in v2.9.0) ─── */
        $queries[] = "CREATE TABLE {$p}tt_roles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            role_key VARCHAR(64) NOT NULL,
            label VARCHAR(191) NOT NULL,
            description TEXT,
            is_system TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_role_key (role_key)
        ) $c;";

        $queries[] = "CREATE TABLE {$p}tt_role_permissions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            role_id BIGINT UNSIGNED NOT NULL,
            permission VARCHAR(128) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_role_perm (role_id, permission),
            KEY idx_role (role_id),
            KEY idx_perm (permission)
        ) $c;";

        // scope_id is nullable for scope_type='global'. UNIQUE constraint
        // across (person, role, scope_type, scope_id) prevents exact
        // duplicates; MySQL treats NULLs as distinct so multiple global
        // grants of the same role are technically allowed but not expected.
        $queries[] = "CREATE TABLE {$p}tt_user_role_scopes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            person_id BIGINT UNSIGNED NOT NULL,
            role_id BIGINT UNSIGNED NOT NULL,
            scope_type VARCHAR(20) NOT NULL DEFAULT 'global',
            scope_id BIGINT UNSIGNED DEFAULT NULL,
            start_date DATE DEFAULT NULL,
            end_date DATE DEFAULT NULL,
            granted_by_person_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_person (person_id),
            KEY idx_role (role_id),
            KEY idx_scope (scope_type, scope_id)
        ) $c;";

        /* ─── Evaluations (FULL v2.x schema) ─── */
        $queries[] = "CREATE TABLE {$p}tt_evaluations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id BIGINT UNSIGNED NOT NULL,
            coach_id BIGINT UNSIGNED NOT NULL,
            eval_type_id BIGINT UNSIGNED DEFAULT NULL,
            eval_date DATE NOT NULL,
            notes TEXT,
            opponent VARCHAR(255) DEFAULT NULL,
            competition VARCHAR(255) DEFAULT NULL,
            match_result VARCHAR(50) DEFAULT NULL,
            home_away VARCHAR(10) DEFAULT NULL,
            minutes_played SMALLINT UNSIGNED DEFAULT NULL,
            category_id BIGINT UNSIGNED DEFAULT NULL,
            rating DECIMAL(3,1) DEFAULT NULL,
            archived_at DATETIME NULL DEFAULT NULL,
            archived_by BIGINT UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_player (player_id),
            KEY idx_coach (coach_id),
            KEY idx_type (eval_type_id),
            KEY idx_archived_at (archived_at)
        ) $c;";

        $queries[] = "CREATE TABLE {$p}tt_eval_ratings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            evaluation_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            rating DECIMAL(4,1) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_eval (evaluation_id),
            KEY idx_cat (category_id)
        ) $c;";

        /* ─── Evaluation categories (v2.12.0) ─── */
        // First-class hierarchy: main categories have parent_id IS NULL,
        // subcategories point at their parent's id. The `category_key`
        // column is a stable slug like 'technical_short_pass' — translations
        // live in the .po file keyed by label, while category_key stays
        // constant. v2.12.1: renamed from `key` (which is a MySQL reserved
        // word that dbDelta silently dropped on some hosts) to category_key.
        $queries[] = "CREATE TABLE {$p}tt_eval_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT UNSIGNED NULL,
            category_key VARCHAR(64) NOT NULL,
            label VARCHAR(255) NOT NULL,
            description TEXT,
            display_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            is_system TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_category_key (category_key),
            KEY idx_parent (parent_id),
            KEY idx_active (is_active)
        ) $c;";

        /* ─── Category weights per age group (v2.13.0) ─── */
        // Per-(age_group, main_category) percentage weight used to compute
        // an overall rating per evaluation. Weights sum to 100 for a given
        // age group when configured; sites without weights for an age
        // group fall back to equal weighting at compute time.
        // age_group_id references tt_lookups.id (lookup_type='age_group').
        // main_category_id references tt_eval_categories.id (parent_id IS NULL).
        $queries[] = "CREATE TABLE {$p}tt_category_weights (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            age_group_id BIGINT UNSIGNED NOT NULL,
            main_category_id BIGINT UNSIGNED NOT NULL,
            weight TINYINT UNSIGNED NOT NULL DEFAULT 25,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_age_main (age_group_id, main_category_id),
            KEY idx_age_group (age_group_id),
            KEY idx_main_category (main_category_id)
        ) $c;";

        /* ─── Usage events (v2.18.0) ─── */
        // Tracks login + admin page-view events for app-usage analytics.
        // 90-day retention enforced by daily WP-Cron prune. No IPs, no
        // user agents — just user id + event type + optional target.
        $queries[] = "CREATE TABLE {$p}tt_usage_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            event_target VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_time (user_id, created_at),
            KEY idx_type_time (event_type, created_at),
            KEY idx_created_at (created_at)
        ) $c;";

        /* ─── Sessions & attendance ─── */
        $queries[] = "CREATE TABLE {$p}tt_activitys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            session_date DATE NOT NULL,
            location VARCHAR(255) DEFAULT '',
            team_id BIGINT UNSIGNED DEFAULT 0,
            coach_id BIGINT UNSIGNED DEFAULT 0,
            notes TEXT,
            archived_at DATETIME NULL DEFAULT NULL,
            archived_by BIGINT UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_team (team_id),
            KEY idx_archived_at (archived_at)
        ) $c;";

        $queries[] = "CREATE TABLE {$p}tt_attendance (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(50) DEFAULT 'present',
            notes TEXT,
            PRIMARY KEY (id),
            KEY idx_session (session_id),
            KEY idx_player (player_id)
        ) $c;";

        /* ─── Goals ─── */
        $queries[] = "CREATE TABLE {$p}tt_goals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            status VARCHAR(50) DEFAULT 'pending',
            priority VARCHAR(50) DEFAULT 'medium',
            due_date DATE,
            created_by BIGINT UNSIGNED NOT NULL,
            archived_at DATETIME NULL DEFAULT NULL,
            archived_by BIGINT UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_player (player_id),
            KEY idx_archived_at (archived_at)
        ) $c;";

        /* ─── Reports ─── */
        $queries[] = "CREATE TABLE {$p}tt_report_presets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            config LONGTEXT NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $c;";

        /* ─── Audit log ─── */
        $queries[] = "CREATE TABLE {$p}tt_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED DEFAULT 0,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT UNSIGNED DEFAULT 0,
            details LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_created (created_at)
        ) $c;";

        /* ─── Custom fields ─── */
        $queries[] = "CREATE TABLE {$p}tt_custom_fields (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            field_key VARCHAR(100) NOT NULL,
            label VARCHAR(255) NOT NULL,
            field_type VARCHAR(50) NOT NULL,
            options LONGTEXT,
            is_required TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            insert_after VARCHAR(64) DEFAULT NULL,
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_entity_key (entity_type, field_key),
            KEY idx_entity (entity_type),
            KEY idx_active (is_active),
            KEY idx_insert_after (entity_type, insert_after)
        ) $c;";

        $queries[] = "CREATE TABLE {$p}tt_custom_values (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            field_id BIGINT UNSIGNED NOT NULL,
            value LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_entity_field (entity_type, entity_id, field_id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_field (field_id)
        ) $c;";

        foreach ( $queries as $sql ) {
            dbDelta( $sql );
        }
    }

    private static function relaxLegacyConstraints(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        self::ensureColumnNullable( "{$p}tt_evaluations", 'category_id', 'BIGINT(20) UNSIGNED NULL' );
        self::ensureColumnNullable( "{$p}tt_evaluations", 'rating', 'DECIMAL(3,1) NULL' );
    }

    private static function ensureColumnNullable( string $table, string $column, string $definition ): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $column ) );
        if ( ! $row ) return;
        if ( ( $row->Null ?? '' ) === 'YES' ) return;
        $wpdb->query( "ALTER TABLE `$table` MODIFY COLUMN `$column` $definition" );
    }

    private static function backfillAttendanceStatus(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $has_present = $wpdb->get_row( "SHOW COLUMNS FROM `{$p}tt_attendance` LIKE 'present'" );
        if ( ! $has_present ) return;
        $wpdb->query( "UPDATE {$p}tt_attendance SET status='present' WHERE present=1 AND (status IS NULL OR status='')" );
        $wpdb->query( "UPDATE {$p}tt_attendance SET status='absent'  WHERE present=0 AND (status IS NULL OR status='')" );
    }

    private static function markMigrationsApplied(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_migrations';

        // v2.10.1: 0005_authorization_rbac removed from this list.
        // No such migration file ever existed — the Sprint 1F (v2.9.0)
        // schema changes were applied via Activator::ensureSchema +
        // seedRolesIfEmpty. Pre-marking a nonexistent migration as
        // applied caused a spurious "applied but file missing" warning
        // on the migrations admin page. cleanupOrphanMigrationRecords()
        // below removes the leftover row on existing sites.
        $to_mark = [
            '0001_initial_schema',
            '0002_create_audit_log',
            '0003_create_custom_fields',
            '0004_schema_reconciliation',
        ];

        foreach ( $to_mark as $name ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table WHERE migration = %s LIMIT 1",
                $name
            ) );
            if ( $exists ) continue;
            $wpdb->insert( $table, [
                'migration'  => $name,
                'applied_at' => current_time( 'mysql' ),
            ] );
        }

        self::seedDefaultsIfEmpty();
    }

    /**
     * v2.10.1 — one-shot cleanup of migration tracking rows whose file
     * never existed on disk. Idempotent; safe to run on every activation.
     * Currently only targets 0005_authorization_rbac (see markMigrationsApplied
     * for context). If a future bad entry slips in, extend the list here.
     */
    private static function cleanupOrphanMigrationRecords(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_migrations';

        $orphans = [
            '0005_authorization_rbac',
        ];

        foreach ( $orphans as $name ) {
            $wpdb->delete( $table, [ 'migration' => $name ] );
        }
    }

    private static function seedDefaultsIfEmpty(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_lookups" );
        if ( $count > 0 ) return;

        // v2.12.0: evaluation categories migrated out of tt_lookups into
        // the dedicated tt_eval_categories table. The seed here populates
        // the new table on fresh installs with the four canonical main
        // categories AND all 21 subcategories in one pass, matching the
        // end state that migration 0008 produces on upgrades.
        self::seedEvalCategoriesIfEmpty();

        // v2.6.0+: lookups for types, positions, foot, age groups, etc.
        foreach ( [
            [ 'Training', 'Regular training session evaluation', '{"requires_match_details":false}', 1 ],
            [ 'Match',    'Competitive match evaluation', '{"requires_match_details":true}', 2 ],
            [ 'Friendly', 'Friendly / scrimmage evaluation', '{"requires_match_details":true}', 3 ],
        ] as $t ) {
            $wpdb->insert( "{$p}tt_lookups", [
                'lookup_type' => 'eval_type',
                'name' => $t[0], 'description' => $t[1], 'meta' => $t[2], 'sort_order' => $t[3],
            ] );
        }

        foreach ( [ 'GK','CB','LB','RB','CDM','CM','CAM','LW','RW','ST','CF' ] as $i => $pos ) {
            $wpdb->insert( "{$p}tt_lookups", [ 'lookup_type' => 'position', 'name' => $pos, 'sort_order' => $i + 1 ] );
        }
        foreach ( [ 'Left','Right','Both' ] as $i => $f ) {
            $wpdb->insert( "{$p}tt_lookups", [ 'lookup_type' => 'foot_option', 'name' => $f, 'sort_order' => $i + 1 ] );
        }
        foreach ( [ 'U8','U10','U12','U14','U16','U19','Senior' ] as $i => $a ) {
            $wpdb->insert( "{$p}tt_lookups", [ 'lookup_type' => 'age_group', 'name' => $a, 'sort_order' => $i + 1 ] );
        }
        foreach ( [ 'Pending','In Progress','Completed','On Hold','Cancelled' ] as $i => $s ) {
            $wpdb->insert( "{$p}tt_lookups", [ 'lookup_type' => 'goal_status', 'name' => $s, 'sort_order' => $i + 1 ] );
        }
        foreach ( [ 'Low','Medium','High' ] as $i => $pr ) {
            $wpdb->insert( "{$p}tt_lookups", [ 'lookup_type' => 'goal_priority', 'name' => $pr, 'sort_order' => $i + 1 ] );
        }
        foreach ( [ 'Present','Absent','Late','Injured','Excused' ] as $i => $a ) {
            $wpdb->insert( "{$p}tt_lookups", [ 'lookup_type' => 'attendance_status', 'name' => $a, 'sort_order' => $i + 1 ] );
        }

        $defaults = [
            'rating_min' => '1', 'rating_max' => '5', 'rating_step' => '0.5',
            'season_label' => '2025/2026', 'academy_name' => 'Soccer Academy',
            'footer_text' => '', 'date_format' => 'Y-m-d',
            'default_report_range' => '3', 'composite_weights' => '{}',
            'modules_enabled' => '["evaluations","goals","attendance","activities","reports","people","authorization"]',
            'primary_color' => '#0b3d2e', 'secondary_color' => '#e8b624',
            'logo_url' => '',
            'show_logo' => '0',
            'tile_scale' => '100',
            'login_redirect_enabled' => '1',
            'dashboard_page_id' => '0',
        ];
        foreach ( $defaults as $k => $v ) {
            $wpdb->replace( "{$p}tt_config", [ 'config_key' => $k, 'config_value' => $v ] );
        }
    }

    /* ═══════════════════════════════════════════════════════════
     *  v2.9.0 — seed 9 system roles + permission matrix
     *  v2.10.0 — top up missing system roles on existing sites
     *            (so team_member lands on Sprint 1F installs)
     * ═══════════════════════════════════════════════════════════ */

    private static function seedRolesIfEmpty(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $roles = self::defaultRoleDefinitions();

        $existing = $wpdb->get_col( "SELECT role_key FROM {$p}tt_roles" );
        $existing = is_array( $existing ) ? array_map( 'strval', $existing ) : [];

        foreach ( $roles as $role ) {
            if ( in_array( $role['key'], $existing, true ) ) continue;

            $wpdb->insert( "{$p}tt_roles", [
                'role_key'    => $role['key'],
                'label'       => $role['label'],
                'description' => $role['description'],
                'is_system'   => 1,
            ] );
            $role_id = (int) $wpdb->insert_id;
            if ( $role_id <= 0 ) continue;

            foreach ( $role['permissions'] as $perm ) {
                $wpdb->insert( "{$p}tt_role_permissions", [
                    'role_id'    => $role_id,
                    'permission' => $perm,
                ] );
            }
        }
    }

    /* ═══════════════════════════════════════════════════════════
     *  v2.10.0 (Sprint 1G) — seed functional roles + default mapping
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Seed the 5 system functional roles (head_coach, assistant_coach,
     * manager, physio, other) and the default 1-to-1 mapping to
     * authorization roles. Idempotent: only seeds functional roles that
     * don't already exist, and only inserts mapping rows that aren't
     * already there. Safe to call on every activation.
     */
    private static function seedFunctionalRolesIfEmpty(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $defs = self::defaultFunctionalRoleDefinitions();

        $existing = $wpdb->get_col( "SELECT role_key FROM {$p}tt_functional_roles" );
        $existing = is_array( $existing ) ? array_map( 'strval', $existing ) : [];

        foreach ( $defs as $def ) {
            if ( ! in_array( $def['key'], $existing, true ) ) {
                $wpdb->insert( "{$p}tt_functional_roles", [
                    'role_key'    => $def['key'],
                    'label'       => $def['label'],
                    'description' => $def['description'],
                    'is_system'   => 1,
                    'sort_order'  => (int) $def['sort_order'],
                ] );
            }

            // Resolve functional_role_id (just-inserted or pre-existing).
            $fn_role_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_functional_roles WHERE role_key = %s",
                $def['key']
            ) );
            if ( $fn_role_id <= 0 ) continue;

            // Seed default auth-role mappings (idempotent, one row per mapping).
            foreach ( $def['maps_to'] as $auth_role_key ) {
                $auth_role_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}tt_roles WHERE role_key = %s",
                    $auth_role_key
                ) );
                if ( $auth_role_id <= 0 ) continue;

                $already = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}tt_functional_role_auth_roles
                     WHERE functional_role_id = %d AND auth_role_id = %d",
                    $fn_role_id, $auth_role_id
                ) );
                if ( $already > 0 ) continue;

                $wpdb->insert( "{$p}tt_functional_role_auth_roles", [
                    'functional_role_id' => $fn_role_id,
                    'auth_role_id'       => $auth_role_id,
                ] );
            }
        }
    }

    /**
     * v2.10.1 — self-healing backfill.
     *
     * Finds tt_team_people rows where role_in_team has a value but
     * functional_role_id is NULL and fills in the FK. Idempotent: does
     * nothing if every row is already backfilled.
     *
     * This is the safety net for sites where migration 0006 ran but its
     * UPDATE statements didn't persist (an issue caused by the pre-2.10.1
     * eval-based migration loader on some PHP hosts). Unlike the
     * migration, this routine runs on every activation so any site that
     * got stuck self-heals as soon as 2.10.1 activates.
     *
     * Does not fail activation: errors are logged under WP_DEBUG but do
     * not throw. The goal is to quietly converge toward the correct
     * state; if convergence fails the user will still see the empty
     * assignment lists and can diagnose via the Permission Debug page.
     */
    /**
     * v2.12.0 — ensure tt_eval_categories is populated.
     *
     * Runs on every activation. Idempotent. On fresh installs this is what
     * actually populates the new table (seedDefaultsIfEmpty calls this
     * before seeding lookups). On upgraded sites migration 0008 already
     * populated the table, so this is a no-op — but if migration 0008 ran
     * before the four canonical main categories existed (because an admin
     * had renamed or deleted them), this routine will still create the
     * canonical set. Never overwrites existing entries.
     *
     * Mirrors the seed list in migration 0008_eval_categories_hierarchy.
     */
    private static function seedEvalCategoriesIfEmpty(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Prereq: table must exist (ensureSchema ran).
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_eval_categories" ) ) !== "{$p}tt_eval_categories" ) {
            return;
        }

        // v2.14.0 — respect existing admin intent. If ANY main category
        // already exists (in any language, with any key), skip the
        // canonical seed entirely. The per-key checks below would
        // otherwise insert English "Technical/Tactical/Physical/Mental"
        // alongside user-renamed or migration-seeded mains like the
        // Dutch "Technisch/Tactisch/Fysiek/Mentaal", creating confusing
        // duplicates.
        $any_main_exists = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}tt_eval_categories WHERE parent_id IS NULL"
        );
        if ( $any_main_exists > 0 ) return;

        // The 4 canonical main categories.
        $mains = [
            [ 'technical', 'Technical', 'Ball control, passing, shooting, dribbling', 10 ],
            [ 'tactical',  'Tactical',  'Positioning, decision-making, game reading', 20 ],
            [ 'physical',  'Physical',  'Speed, endurance, strength, agility',        30 ],
            [ 'mental',    'Mental',    'Focus, leadership, attitude, resilience',    40 ],
        ];
        foreach ( $mains as $m ) {
            [ $key, $label, $description, $display_order ] = $m;
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_eval_categories WHERE category_key = %s", $key
            ) );
            if ( $exists > 0 ) continue;
            $wpdb->insert( "{$p}tt_eval_categories", [
                'parent_id'     => null,
                'category_key'  => $key,
                'label'         => $label,
                'description'   => $description,
                'display_order' => $display_order,
                'is_active'     => 1,
                'is_system'     => 1,
            ] );
        }

        // 21 subcategories seeded as children of the canonical mains.
        $subs = [
            [ 'technical', 'technical_short_pass',             'Short pass',            10 ],
            [ 'technical', 'technical_long_pass',              'Long pass',             20 ],
            [ 'technical', 'technical_first_touch',            'First touch',           30 ],
            [ 'technical', 'technical_dribbling',              'Dribbling',             40 ],
            [ 'technical', 'technical_shooting',               'Shooting',              50 ],
            [ 'technical', 'technical_heading',                'Heading',               60 ],
            [ 'tactical',  'tactical_positioning_offensive',   'Offensive positioning', 10 ],
            [ 'tactical',  'tactical_positioning_defensive',   'Defensive positioning', 20 ],
            [ 'tactical',  'tactical_game_reading',            'Game reading',          30 ],
            [ 'tactical',  'tactical_decision_making',         'Decision making',       40 ],
            [ 'tactical',  'tactical_off_ball_movement',       'Off-ball movement',     50 ],
            [ 'physical',  'physical_speed',                   'Speed',                 10 ],
            [ 'physical',  'physical_endurance',               'Endurance',             20 ],
            [ 'physical',  'physical_strength',                'Strength',              30 ],
            [ 'physical',  'physical_agility',                 'Agility',               40 ],
            [ 'physical',  'physical_coordination',            'Coordination',          50 ],
            [ 'mental',    'mental_focus',                     'Focus',                 10 ],
            [ 'mental',    'mental_leadership',                'Leadership',            20 ],
            [ 'mental',    'mental_attitude',                  'Attitude',              30 ],
            [ 'mental',    'mental_resilience',                'Resilience',            40 ],
            [ 'mental',    'mental_coachability',              'Coachability',          50 ],
        ];
        foreach ( $subs as $s ) {
            [ $parent_key, $key, $label, $display_order ] = $s;
            $parent_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_eval_categories WHERE category_key = %s", $parent_key
            ) );
            if ( $parent_id <= 0 ) continue;
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_eval_categories WHERE category_key = %s", $key
            ) );
            if ( $exists > 0 ) continue;
            $wpdb->insert( "{$p}tt_eval_categories", [
                'parent_id'     => $parent_id,
                'category_key'  => $key,
                'label'         => $label,
                'description'   => null,
                'display_order' => $display_order,
                'is_active'     => 1,
                'is_system'     => 1,
            ] );
        }
    }

    /**
     * v2.12.1 — repair a corrupt tt_eval_categories table.
     * v2.12.2 — detection improved: also treats obsolete tt_lookups-shape
     *           columns (`name`, `sort_order`) and blank-label rows as
     *           corruption signals, because on some sites dbDelta added
     *           `category_key` to an already-corrupt table without
     *           clearing the stale columns or garbage rows.
     *
     * Background: v2.12.0 defined the tt_eval_categories schema with a
     * column literally named `key`, which is a MySQL reserved word.
     * dbDelta on some hosts (including Strato / MariaDB 10.x) silently
     * dropped that column when creating the table, leaving the table
     * in an unusable state for migration 0008.
     *
     * On affected sites the table may have:
     *   - the wrong columns (old `tt_lookups`-shape `name` / `sort_order`)
     *   - stray rows with empty `category_key` / `label`
     *   - both, if a previous 2.12.1 activation added `category_key` without
     *     clearing the garbage
     *
     * This routine:
     *
     *   1. Returns immediately if the table doesn't exist (fresh install).
     *   2. Checks for corruption signals:
     *       - Missing `category_key` column  (original 2.12.0 symptom)
     *       - Presence of stale `name` or `sort_order` columns from the
     *         tt_lookups shape  (v2.12.2: new signal)
     *       - Any row with blank `category_key` AND blank `label`
     *         (v2.12.2: new signal)
     *      If none present, the table is healthy — return.
     *   3. Safety guard: refuse to drop if any tt_eval_ratings row
     *      references an ID in the current table. Log WP_DEBUG warning
     *      and return.
     *   4. DROP the table. ensureSchema will recreate cleanly on the
     *      next step of activate().
     *
     * Idempotent. No-op on healthy schemas. No-op on fresh installs.
     */
    private static function repairEvalCategoriesTableIfCorrupt(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // 1. Does the table exist?
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_eval_categories" ) ) !== "{$p}tt_eval_categories" ) {
            return;
        }

        // 2. Check every corruption signal. ANY of them triggers repair.
        $is_corrupt = false;

        $has_category_key = $wpdb->get_row( $wpdb->prepare(
            "SHOW COLUMNS FROM {$p}tt_eval_categories LIKE %s",
            'category_key'
        ) );
        if ( $has_category_key === null ) {
            $is_corrupt = true;
        }

        if ( ! $is_corrupt ) {
            // v2.12.2: stale tt_lookups-shape columns are a corruption
            // signal even when category_key is also present, because a
            // partially-reconciled dbDelta run can leave both.
            $has_stale_name = $wpdb->get_row( $wpdb->prepare(
                "SHOW COLUMNS FROM {$p}tt_eval_categories LIKE %s",
                'name'
            ) );
            $has_stale_sort = $wpdb->get_row( $wpdb->prepare(
                "SHOW COLUMNS FROM {$p}tt_eval_categories LIKE %s",
                'sort_order'
            ) );
            if ( $has_stale_name !== null || $has_stale_sort !== null ) {
                $is_corrupt = true;
            }
        }

        if ( ! $is_corrupt ) {
            // v2.12.2: any row with a blank category_key AND blank label
            // is an artifact of dbDelta's half-reconciliation on sites
            // that had the reserved-word bug. A normal row always has
            // both populated (the repo enforces non-empty category_key
            // at insert time).
            $blank_rows = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$p}tt_eval_categories
                 WHERE (category_key IS NULL OR category_key = '')
                    OR (label IS NULL OR label = '')"
            );
            if ( $blank_rows > 0 ) {
                $is_corrupt = true;
            }
        }

        if ( ! $is_corrupt ) {
            // All clear — healthy schema, no repair needed.
            return;
        }

        // 3. Safety guard: refuse to drop if tt_eval_ratings references
        //    IDs in this table. Manual intervention required.
        $referencing = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}tt_eval_ratings r
             INNER JOIN {$p}tt_eval_categories c ON r.category_id = c.id"
        );
        if ( $referencing > 0 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[TalentTrack 2.12.2] repairEvalCategoriesTableIfCorrupt: refusing to drop tt_eval_categories — %d tt_eval_ratings rows reference it. Manual intervention required.',
                    $referencing
                ) );
            }
            return;
        }

        // 4. Drop the corrupt table; ensureSchema recreates it cleanly.
        $wpdb->query( "DROP TABLE IF EXISTS {$p}tt_eval_categories" );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[TalentTrack 2.12.2] repairEvalCategoriesTableIfCorrupt: dropped corrupt tt_eval_categories table; ensureSchema will recreate.' );
        }
    }

    private static function repairFunctionalRoleBackfill(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Prerequisite: the new tables must exist. If they don't, this
        // activation didn't get as far as ensureSchema — not our problem
        // to paper over here.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_functional_roles" ) ) !== "{$p}tt_functional_roles" ) {
            return;
        }
        if ( ! self::columnExists( "{$p}tt_team_people", 'functional_role_id' ) ) {
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT id, role_in_team
             FROM {$p}tt_team_people
             WHERE functional_role_id IS NULL AND role_in_team IS NOT NULL AND role_in_team <> ''"
        );
        if ( ! is_array( $rows ) || empty( $rows ) ) return;

        // Build role_key → id map in one query.
        $fn_map = [];
        $all_fn = $wpdb->get_results( "SELECT id, role_key FROM {$p}tt_functional_roles" );
        if ( is_array( $all_fn ) ) {
            foreach ( $all_fn as $f ) {
                $fn_map[ (string) $f->role_key ] = (int) $f->id;
            }
        }
        if ( empty( $fn_map ) ) return;

        $filled = 0;
        foreach ( $rows as $r ) {
            $role_key = (string) $r->role_in_team;
            $fn_id = $fn_map[ $role_key ] ?? ( $fn_map['other'] ?? 0 );
            if ( $fn_id <= 0 ) continue;

            $result = $wpdb->update(
                "{$p}tt_team_people",
                [ 'functional_role_id' => $fn_id ],
                [ 'id' => (int) $r->id ],
                [ '%d' ],
                [ '%d' ]
            );
            if ( $result !== false ) {
                $filled++;
            } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[TalentTrack] repairFunctionalRoleBackfill: UPDATE failed on tt_team_people.id=%d. Last wpdb error: %s',
                    (int) $r->id,
                    (string) $wpdb->last_error
                ) );
            }
        }

        if ( $filled > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[TalentTrack] repairFunctionalRoleBackfill: filled %d tt_team_people row(s).', $filled ) );
        }
    }

    /**
     * Helper: does a column exist on a table? Used by the repair
     * routine above. Matches the pattern used in migration 0004's
     * inline helpers.
     */
    private static function columnExists( string $table, string $column ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $column ) );
        return $row !== null;
    }

    /**
     * The authoritative definition of the 5 system functional roles and
     * their default 1-to-1 mapping to authorization roles. Editable in the
     * admin UI (TalentTrack → Functional Roles) after activation.
     *
     * Mapping default per Sprint 1G design:
     *   head_coach      → [head_coach]
     *   assistant_coach → [assistant_coach]
     *   manager         → [manager]
     *   physio          → [physio]
     *   other           → [team_member]   (new minimal read-only auth role)
     *
     * @return array<int, array{key:string, label:string, description:string, sort_order:int, maps_to:string[]}>
     */
    public static function defaultFunctionalRoleDefinitions(): array {
        return [
            [
                'key'         => 'head_coach',
                'label'       => 'Head Coach',
                'description' => 'Lead coach for a team. Owns methodology, selection, and session planning.',
                'sort_order'  => 10,
                'maps_to'     => [ 'head_coach' ],
            ],
            [
                'key'         => 'assistant_coach',
                'label'       => 'Assistant Coach',
                'description' => 'Supports the head coach with training and evaluations within a team.',
                'sort_order'  => 20,
                'maps_to'     => [ 'assistant_coach' ],
            ],
            [
                'key'         => 'manager',
                'label'       => 'Manager',
                'description' => 'Handles logistics, roster, sessions, and team settings. Not an evaluator.',
                'sort_order'  => 30,
                'maps_to'     => [ 'manager' ],
            ],
            [
                'key'         => 'physio',
                'label'       => 'Physio',
                'description' => 'Medical / physical support staff attached to a team.',
                'sort_order'  => 40,
                'maps_to'     => [ 'physio' ],
            ],
            [
                'key'         => 'other',
                'label'       => 'Other',
                'description' => 'Anything that does not fit the other categories. Minimal read-only access by default.',
                'sort_order'  => 50,
                'maps_to'     => [ 'team_member' ],
            ],
        ];
    }

    /**
     * The authoritative definition of the 9 system roles and their
     * permission strings. This runs once on first activation; after that
     * the data lives in the database and is editable (in a future release)
     * via the admin UI.
     *
     * Permission naming convention: `<domain>.<action>`.
     *
     * Special tokens:
     *   `*.*`       — grants everything (club_admin only)
     *   `*.view`    — view across all domains
     *
     * @return array<int, array{key:string, label:string, description:string, permissions:string[]}>
     */
    public static function defaultRoleDefinitions(): array {
        return [
            [
                'key'         => 'club_admin',
                'label'       => 'Club Admin',
                'description' => 'Full access across the academy. Can manage all entities, assign staff, and configure the system.',
                'permissions' => [ '*.*' ],
            ],
            [
                'key'         => 'head_of_development',
                'label'       => 'Head of Development',
                'description' => 'Shapes methodology and reviews output. Read-all across the academy plus evaluations management. No player-data editing, no staff reassignment.',
                'permissions' => [
                    'players.view',
                    'evaluations.view',
                    'evaluations.create',
                    'evaluations.edit_any',
                    'evaluations.delete',
                    'reports.view',
                    'config.view',
                    'people.view',
                    'teams.view',
                    'goals.view',
                    'sessions.view',
                ],
            ],
            [
                'key'         => 'head_coach',
                'label'       => 'Head Coach',
                'description' => 'Full control within assigned teams — players, evaluations, sessions, goals, and team settings. Scoped to team.',
                'permissions' => [
                    'players.view',
                    'players.edit',
                    'evaluations.view',
                    'evaluations.create',
                    'evaluations.edit_own',
                    'evaluations.edit_any',
                    'sessions.view',
                    'sessions.manage',
                    'goals.view',
                    'goals.manage',
                    'team.manage',
                    'people.view',
                ],
            ],
            [
                'key'         => 'assistant_coach',
                'label'       => 'Assistant Coach',
                'description' => 'Evaluate and observe within assigned teams. Can create and edit own evaluations; cannot edit other coaches\' evaluations. Scoped to team.',
                'permissions' => [
                    'players.view',
                    'evaluations.view',
                    'evaluations.create',
                    'evaluations.edit_own',
                    'sessions.view',
                    'goals.view',
                ],
            ],
            [
                'key'         => 'manager',
                'label'       => 'Manager',
                'description' => 'Runs logistics within assigned teams — roster, sessions, team settings. No evaluation permissions. Scoped to team.',
                'permissions' => [
                    'players.view',
                    'players.edit',
                    'team.manage',
                    'sessions.view',
                    'sessions.manage',
                    'goals.view',
                    'people.view',
                ],
            ],
            [
                'key'         => 'physio',
                'label'       => 'Physio',
                'description' => 'Read-only access to players and sessions within assigned teams.',
                'permissions' => [
                    'players.view',
                    'sessions.view',
                ],
            ],
            [
                'key'         => 'team_member',
                'label'       => 'Team Member',
                'description' => 'Minimal read-only access within assigned teams. Default authorization for the "Other" functional role — see only players and sessions of the teams you are assigned to, nothing more.',
                'permissions' => [
                    'players.view',
                    'sessions.view',
                ],
            ],
            [
                'key'         => 'scout',
                'label'       => 'Scout',
                'description' => 'View any player and create scouting evaluations. Can be assigned globally or to specific teams.',
                'permissions' => [
                    'players.view',
                    'evaluations.view',
                    'evaluations.create',
                    'evaluations.edit_own',
                ],
            ],
            [
                'key'         => 'parent',
                'label'       => 'Parent',
                'description' => 'Read-only access to linked children\'s records. Scoped to specific players.',
                'permissions' => [
                    'players.view_own_children',
                    'evaluations.view_own_children',
                    'goals.view_own_children',
                ],
            ],
            [
                'key'         => 'player',
                'label'       => 'Player',
                'description' => 'Read-only access to own profile. Auto-derived from tt_players.wp_user_id — not manually grantable.',
                'permissions' => [
                    'players.view_own',
                    'evaluations.view_own',
                    'goals.view_own',
                ],
            ],
        ];
    }
}
