<?php
/**
 * Migration 0122 — VCT (Voetbal Conditionele Training) module schema
 * foundation (#906, VCT-1, epic #905). Implements `specs/0095-feat-vct-module.md`
 * § Scope → Schema verbatim.
 *
 * Ten new VCT tables plus the per-player PHV (Peak Height Velocity)
 * flag table. Single migration; every CREATE is `IF NOT EXISTS`; safely
 * re-runnable. All tables carry `club_id INT UNSIGNED NOT NULL DEFAULT 1`
 * (CLAUDE.md §4 tenancy scaffold). User-facing root entities carry
 * `uuid CHAR(36) UNIQUE` for the SaaS port.
 *
 * No DB-level FOREIGN KEY constraints — app-level integrity per the
 * codebase convention (see 0001_initial_schema.php, 0094_scouting_plan_visits.php,
 * 0107_pdp_blocks.php). VctSessionsRepository::delete() performs child
 * cleanup for session blocks in a transaction; integration test asserts
 * no orphans (VCT-5).
 *
 * MD suitability on tt_vct_exercises is denormalised to eight TINYINT
 * bit-flag columns (not a JSON column) so the hot-path candidate-selection
 * query can seek on a composite index instead of scanning JSON.
 *
 * tt_vct_macro_blocks.team_id is NOT NULL DEFAULT 0 (no COALESCE in
 * UNIQUE — illegal in MySQL 5.7). team_id=0 is the club-wide season
 * default; non-zero is a per-team override.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0122_vct_schema';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = [];

        // tt_vct_exercises — exercise catalogue. UUID-bearing root.
        // MD suitability denormalised to 8 TINYINT columns (architecture
        // review H5 — JSON_CONTAINS would force row scan).
        $t = $p . 'tt_vct_exercises';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            $tables[] = "CREATE TABLE {$t} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid CHAR(36) NOT NULL,
                code VARCHAR(64) NOT NULL,
                name_canonical VARCHAR(190) NOT NULL,
                category VARCHAR(64) NOT NULL,
                tactical_theme VARCHAR(64) NULL,
                intensity_band TINYINT UNSIGNED NOT NULL,
                duration_minutes_min SMALLINT UNSIGNED NOT NULL,
                duration_minutes_max SMALLINT UNSIGNED NOT NULL,
                players_min TINYINT UNSIGNED NOT NULL,
                players_max TINYINT UNSIGNED NOT NULL,
                sided_size VARCHAR(16) NULL,
                age_min TINYINT UNSIGNED NOT NULL,
                age_max TINYINT UNSIGNED NOT NULL,
                md_minus_4 TINYINT UNSIGNED NOT NULL DEFAULT 0,
                md_minus_3 TINYINT UNSIGNED NOT NULL DEFAULT 0,
                md_minus_2 TINYINT UNSIGNED NOT NULL DEFAULT 0,
                md_minus_1 TINYINT UNSIGNED NOT NULL DEFAULT 0,
                md_zero TINYINT UNSIGNED NOT NULL DEFAULT 0,
                md_plus_1 TINYINT UNSIGNED NOT NULL DEFAULT 0,
                md_plus_2 TINYINT UNSIGNED NOT NULL DEFAULT 0,
                md_none TINYINT UNSIGNED NOT NULL DEFAULT 1,
                equipment_json LONGTEXT NULL,
                diagram_url VARCHAR(2048) NULL,
                verheijen_classification VARCHAR(64) NULL,
                seed_revision INT UNSIGNED NOT NULL DEFAULT 0,
                archived_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                UNIQUE KEY uniq_club_code (club_id, code),
                KEY idx_candidate_lookup (club_id, archived_at, category, intensity_band, age_min, age_max)
            ) {$charset};";
        }

        // tt_vct_coaching_points — translatable child table for per-exercise cues.
        // Canonical text via tt_translations (#902 lesson — no JSON column).
        $t = $p . 'tt_vct_coaching_points';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            $tables[] = "CREATE TABLE {$t} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                exercise_id BIGINT UNSIGNED NOT NULL,
                sequence TINYINT UNSIGNED NOT NULL,
                code VARCHAR(96) NOT NULL,
                archived_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_exercise_code (exercise_id, code),
                KEY idx_club_exercise (club_id, exercise_id, sequence)
            ) {$charset};";
        }

        // tt_vct_age_profiles — per-club workload envelope per age group.
        $t = $p . 'tt_vct_age_profiles';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            $tables[] = "CREATE TABLE {$t} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid CHAR(36) NOT NULL,
                age_group VARCHAR(8) NOT NULL,
                session_minutes_max SMALLINT UNSIGNED NOT NULL,
                intensity_band_max TINYINT UNSIGNED NOT NULL,
                weekly_load_envelope INT UNSIGNED NOT NULL,
                md_logic_enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
                min_recovery_hours_between_high SMALLINT UNSIGNED NOT NULL DEFAULT 48,
                growth_spurt_load_reduction_pct TINYINT UNSIGNED NOT NULL DEFAULT 20,
                match_load_multiplier_per_minute DECIMAL(3,1) NOT NULL DEFAULT 7.0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                UNIQUE KEY uniq_club_age (club_id, age_group)
            ) {$charset};";
        }

        // tt_vct_session_templates — slot definitions per (age × MD context).
        $t = $p . 'tt_vct_session_templates';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            $tables[] = "CREATE TABLE {$t} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid CHAR(36) NOT NULL,
                age_group VARCHAR(8) NOT NULL,
                md_context VARCHAR(16) NOT NULL,
                slots_json LONGTEXT NOT NULL,
                total_duration_minutes_target SMALLINT UNSIGNED NOT NULL,
                description_nl TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                UNIQUE KEY uniq_club_age_md (club_id, age_group, md_context)
            ) {$charset};";
        }

        // tt_vct_sessions — root session entity. Optional activity_id binds
        // to tt_activities when published. App-level FK only.
        $t = $p . 'tt_vct_sessions';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            $tables[] = "CREATE TABLE {$t} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid CHAR(36) NOT NULL,
                team_id BIGINT UNSIGNED NOT NULL,
                activity_id BIGINT UNSIGNED NULL,
                session_date DATE NOT NULL,
                start_time TIME NULL,
                age_group VARCHAR(8) NOT NULL,
                md_context VARCHAR(16) NOT NULL,
                tactical_theme VARCHAR(64) NULL,
                total_duration_minutes SMALLINT UNSIGNED NOT NULL,
                total_load INT UNSIGNED NOT NULL DEFAULT 0,
                coach_notes TEXT NULL,
                status ENUM('draft','published','completed','archived') NOT NULL DEFAULT 'draft',
                generated_by BIGINT UNSIGNED NOT NULL,
                generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                published_at DATETIME NULL,
                completed_at DATETIME NULL,
                archived_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                KEY idx_club_team_date (club_id, team_id, session_date),
                KEY idx_club_status (club_id, status)
            ) {$charset};";
        }

        // tt_vct_session_blocks — filled slots per session. No DB CASCADE;
        // VctSessionsRepository::delete() does child cleanup in a transaction.
        $t = $p . 'tt_vct_session_blocks';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            $tables[] = "CREATE TABLE {$t} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                session_id BIGINT UNSIGNED NOT NULL,
                sequence TINYINT UNSIGNED NOT NULL,
                slot_category VARCHAR(32) NOT NULL,
                exercise_id BIGINT UNSIGNED NULL,
                custom_label VARCHAR(190) NULL,
                duration_minutes SMALLINT UNSIGNED NOT NULL,
                intensity_band TINYINT UNSIGNED NOT NULL,
                coaching_point_override_codes LONGTEXT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_session_sequence (session_id, sequence),
                KEY idx_club_session (club_id, session_id)
            ) {$charset};";
        }

        // tt_vct_microcycles — weekly aggregate per team.
        $t = $p . 'tt_vct_microcycles';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            $tables[] = "CREATE TABLE {$t} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                team_id BIGINT UNSIGNED NOT NULL,
                week_starts_on DATE NOT NULL,
                match_date DATE NULL,
                total_load_target INT UNSIGNED NOT NULL DEFAULT 0,
                total_load_actual INT UNSIGNED NOT NULL DEFAULT 0,
                notes TEXT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_club_team_week (club_id, team_id, week_starts_on)
            ) {$charset};";
        }

        // tt_vct_workload_snapshots — per-player load aggregation. Nightly
        // job writes one row per (player, day). INT UNSIGNED accumulators
        // documented bounds: session_load_28d typically < 25,000 per player;
        // INT UNSIGNED has 4B headroom.
        $t = $p . 'tt_vct_workload_snapshots';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            $tables[] = "CREATE TABLE {$t} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                player_id BIGINT UNSIGNED NOT NULL,
                snapshot_date DATE NOT NULL,
                session_load_24h INT UNSIGNED NOT NULL DEFAULT 0,
                session_load_7d INT UNSIGNED NOT NULL DEFAULT 0,
                session_load_28d INT UNSIGNED NOT NULL DEFAULT 0,
                acwr DECIMAL(3,2) NULL,
                flag VARCHAR(16) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_club_player_date (club_id, player_id, snapshot_date)
            ) {$charset};";
        }

        // tt_vct_team_schedules — per-team weekly training-day preferences.
        // weekdays_bitmask is 7 bits (bit 0 = Monday … bit 6 = Sunday).
        $t = $p . 'tt_vct_team_schedules';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            $tables[] = "CREATE TABLE {$t} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid CHAR(36) NOT NULL,
                team_id BIGINT UNSIGNED NOT NULL,
                season_id BIGINT UNSIGNED NOT NULL,
                weekdays_bitmask TINYINT UNSIGNED NOT NULL DEFAULT 0,
                default_start_time TIME NULL,
                default_duration_minutes SMALLINT UNSIGNED NULL,
                archived_at DATETIME NULL,
                updated_by BIGINT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                UNIQUE KEY uniq_club_team_season (club_id, team_id, season_id)
            ) {$charset};";
        }

        // tt_vct_macro_blocks — periodization calendar (PDP-blocks pattern).
        // team_id NOT NULL DEFAULT 0 — 0 is club-wide season default;
        // non-zero is per-team override. Plain UNIQUE works without COALESCE.
        $t = $p . 'tt_vct_macro_blocks';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            $tables[] = "CREATE TABLE {$t} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid CHAR(36) NOT NULL,
                season_id BIGINT UNSIGNED NOT NULL,
                team_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                sequence TINYINT UNSIGNED NOT NULL,
                label VARCHAR(190) NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                phase_profile_json LONGTEXT NULL,
                archived_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                UNIQUE KEY uniq_club_team_season_seq (club_id, team_id, season_id, sequence),
                KEY idx_club_season (club_id, season_id)
            ) {$charset};";
        }

        // tt_player_phv_flags — per-player Peak Height Velocity flag.
        // WorkloadCapRule reads is_active and applies the configured
        // growth_spurt_load_reduction_pct from tt_vct_age_profiles.
        $t = $p . 'tt_player_phv_flags';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            $tables[] = "CREATE TABLE {$t} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                player_id BIGINT UNSIGNED NOT NULL,
                is_active TINYINT UNSIGNED NOT NULL DEFAULT 0,
                flagged_at DATETIME NULL,
                flagged_by BIGINT UNSIGNED NULL,
                cleared_at DATETIME NULL,
                cleared_by BIGINT UNSIGNED NULL,
                notes TEXT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_club_player (club_id, player_id)
            ) {$charset};";
        }

        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }
    }
};
