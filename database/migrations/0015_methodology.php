<?php
/**
 * Migration 0015 — Methodology module (#0027).
 *
 * Creates the catalogue tables for the football-methodology library:
 *
 *   - tt_formations                         — formation definitions (1:4:2:3:1, etc.)
 *   - tt_formation_positions                — per-jersey-number role cards
 *   - tt_principles                         — coded game principles (AO-01, etc.)
 *   - tt_set_pieces                         — corners / free kicks / penalties / throw-ins
 *   - tt_methodology_visions                — club-level vision record
 *   - tt_methodology_principle_links        — reverse index (consumed by #0006 etc.)
 *   - tt_session_principles                 — session ↔ principle pivot
 *
 * Plus two column additions to existing tables:
 *
 *   - tt_goals.linked_principle_id          — optional principle a goal supports
 *
 * Multilingual fields use JSON columns keyed by locale, same pattern as
 * `tt_lookups.translations` (migration 0014). The MultilingualField helper
 * resolves locale → fallback (NL → EN → empty) at render time.
 *
 * `is_shipped = 1` rows are TT-curated; clubs cannot edit. `is_shipped = 0`
 * are club-authored. `cloned_from_id` records the originating shipped row
 * for clubs that started from a curated template.
 *
 * Idempotent: every CREATE TABLE uses IF NOT EXISTS; column adds are
 * preceded by an information_schema check.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0015_methodology';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $sql   = [];

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_formations (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug            VARCHAR(64)     NOT NULL,
            name_json       TEXT            DEFAULT NULL,
            description_json TEXT           DEFAULT NULL,
            diagram_data_json LONGTEXT      DEFAULT NULL,
            is_shipped      TINYINT(1)      NOT NULL DEFAULT 0,
            cloned_from_id  BIGINT UNSIGNED DEFAULT NULL,
            archived_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_slug (slug),
            KEY idx_is_shipped (is_shipped)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_formation_positions (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            formation_id    BIGINT UNSIGNED NOT NULL,
            jersey_number   TINYINT UNSIGNED NOT NULL,
            short_name_json TEXT            DEFAULT NULL,
            long_name_json  TEXT            DEFAULT NULL,
            attacking_tasks_json LONGTEXT   DEFAULT NULL,
            defending_tasks_json LONGTEXT   DEFAULT NULL,
            sort_order      INT             NOT NULL DEFAULT 0,
            is_shipped      TINYINT(1)      NOT NULL DEFAULT 0,
            cloned_from_id  BIGINT UNSIGNED DEFAULT NULL,
            archived_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_formation (formation_id),
            KEY idx_jersey (formation_id, jersey_number)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_principles (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code            VARCHAR(16)     NOT NULL,
            team_function_key  VARCHAR(64)  NOT NULL,
            team_task_key      VARCHAR(64)  NOT NULL,
            title_json      TEXT            DEFAULT NULL,
            explanation_json LONGTEXT       DEFAULT NULL,
            team_guidance_json LONGTEXT     DEFAULT NULL,
            line_guidance_json LONGTEXT     DEFAULT NULL,
            default_formation_id BIGINT UNSIGNED DEFAULT NULL,
            diagram_overlay_json LONGTEXT   DEFAULT NULL,
            is_shipped      TINYINT(1)      NOT NULL DEFAULT 0,
            cloned_from_id  BIGINT UNSIGNED DEFAULT NULL,
            archived_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_code (code),
            KEY idx_function (team_function_key),
            KEY idx_task (team_task_key),
            KEY idx_is_shipped (is_shipped)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_set_pieces (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug            VARCHAR(64)     NOT NULL,
            kind_key        VARCHAR(32)     NOT NULL,
            side            VARCHAR(16)     NOT NULL,
            title_json      TEXT            DEFAULT NULL,
            bullets_json    LONGTEXT        DEFAULT NULL,
            default_formation_id BIGINT UNSIGNED DEFAULT NULL,
            diagram_overlay_json LONGTEXT   DEFAULT NULL,
            is_shipped      TINYINT(1)      NOT NULL DEFAULT 0,
            cloned_from_id  BIGINT UNSIGNED DEFAULT NULL,
            archived_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_slug (slug),
            KEY idx_kind_side (kind_key, side),
            KEY idx_is_shipped (is_shipped)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_methodology_visions (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_scope      VARCHAR(64)     DEFAULT NULL,
            formation_id    BIGINT UNSIGNED DEFAULT NULL,
            style_of_play_key VARCHAR(64)   DEFAULT NULL,
            way_of_playing_json LONGTEXT    DEFAULT NULL,
            important_traits_json LONGTEXT  DEFAULT NULL,
            notes_json      LONGTEXT        DEFAULT NULL,
            is_shipped      TINYINT(1)      NOT NULL DEFAULT 0,
            archived_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_is_shipped (is_shipped)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_methodology_principle_links (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            principle_id    BIGINT UNSIGNED NOT NULL,
            entity_type     VARCHAR(32)     NOT NULL,
            entity_id       BIGINT UNSIGNED NOT NULL,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_principle (principle_id),
            KEY idx_entity (entity_type, entity_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_session_principles (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id      BIGINT UNSIGNED NOT NULL,
            principle_id    BIGINT UNSIGNED NOT NULL,
            sort_order      INT             NOT NULL DEFAULT 0,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_session_principle (session_id, principle_id),
            KEY idx_session (session_id),
            KEY idx_principle (principle_id)
        ) {$charset};";

        foreach ( $sql as $statement ) $wpdb->query( $statement );

        // tt_goals.linked_principle_id — additive column.
        $goals_table = $p . 'tt_goals';
        $col_exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'linked_principle_id'",
            $goals_table
        ) );
        if ( $col_exists === 0 ) {
            $wpdb->query( "ALTER TABLE {$goals_table} ADD COLUMN linked_principle_id BIGINT UNSIGNED DEFAULT NULL" );
            $wpdb->query( "ALTER TABLE {$goals_table} ADD KEY idx_linked_principle (linked_principle_id)" );
        }
    }
};
