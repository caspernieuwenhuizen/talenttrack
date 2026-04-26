<?php
/**
 * Migration 0017 — Methodology expansion (#0027 follow-up).
 *
 * Adds the per-club framework primer + asset support + football
 * actions catalogue that turn the methodology module from "principles
 * + set-pieces + positions" into a full coaching framework:
 *
 *   - tt_methodology_assets               — polymorphic image attachments
 *                                            (entity_type/entity_id → wp
 *                                            attachment ID + caption + sort)
 *   - tt_methodology_framework_primers    — per-club framework primer
 *                                            (intro, voetbalmodel intro,
 *                                            reflection)
 *   - tt_methodology_phases               — 4 phases × 2 sides per primer
 *                                            (aanvallen 1–4, verdedigen 1–4)
 *   - tt_methodology_learning_goals       — learning goals per teamtaak
 *                                            with bullet checklists
 *   - tt_methodology_influence_factors    — factoren van invloed
 *   - tt_football_actions                 — voetbalhandelingen catalogue
 *                                            (aannemen / passen / vrijlopen
 *                                            etc.) goals can link to
 *
 * Plus column additions:
 *   - tt_goals.linked_action_id           — optional football action
 *                                            this goal supports
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS + information_schema column
 * checks). No data is seeded here — see migration 0018.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0017_methodology_expansion';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $sql   = [];

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_methodology_assets (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type     VARCHAR(32)     NOT NULL,
            entity_id       BIGINT UNSIGNED NOT NULL,
            attachment_id   BIGINT UNSIGNED NOT NULL,
            caption_json    TEXT            DEFAULT NULL,
            sort_order      INT             NOT NULL DEFAULT 0,
            is_primary      TINYINT(1)      NOT NULL DEFAULT 0,
            is_shipped      TINYINT(1)      NOT NULL DEFAULT 0,
            archived_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_attachment (attachment_id),
            KEY idx_is_primary (entity_type, entity_id, is_primary)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_methodology_framework_primers (
            id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_scope                  VARCHAR(64)     DEFAULT NULL,
            title_json                  TEXT            DEFAULT NULL,
            tagline_json                TEXT            DEFAULT NULL,
            intro_json                  LONGTEXT        DEFAULT NULL,
            voetbalmodel_intro_json     LONGTEXT        DEFAULT NULL,
            voetbalhandelingen_intro_json LONGTEXT      DEFAULT NULL,
            phases_intro_json           LONGTEXT        DEFAULT NULL,
            learning_goals_intro_json   LONGTEXT        DEFAULT NULL,
            influence_factors_intro_json LONGTEXT       DEFAULT NULL,
            reflection_json             LONGTEXT        DEFAULT NULL,
            future_json                 LONGTEXT        DEFAULT NULL,
            is_shipped                  TINYINT(1)      NOT NULL DEFAULT 0,
            cloned_from_id              BIGINT UNSIGNED DEFAULT NULL,
            archived_at                 DATETIME        DEFAULT NULL,
            created_at                  DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at                  DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_is_shipped (is_shipped),
            KEY idx_club_scope (club_scope)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_methodology_phases (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            primer_id       BIGINT UNSIGNED NOT NULL,
            side            VARCHAR(16)     NOT NULL,
            phase_number    TINYINT UNSIGNED NOT NULL,
            title_json      TEXT            DEFAULT NULL,
            goal_json       LONGTEXT        DEFAULT NULL,
            sort_order      INT             NOT NULL DEFAULT 0,
            is_shipped      TINYINT(1)      NOT NULL DEFAULT 0,
            archived_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_primer_side (primer_id, side, phase_number),
            KEY idx_is_shipped (is_shipped)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_methodology_learning_goals (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            primer_id       BIGINT UNSIGNED NOT NULL,
            slug            VARCHAR(64)     NOT NULL,
            side            VARCHAR(16)     NOT NULL,
            team_task_key   VARCHAR(64)     DEFAULT NULL,
            title_json      TEXT            DEFAULT NULL,
            bullets_json    LONGTEXT        DEFAULT NULL,
            sort_order      INT             NOT NULL DEFAULT 0,
            is_shipped      TINYINT(1)      NOT NULL DEFAULT 0,
            archived_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_primer (primer_id),
            KEY idx_side (side),
            KEY idx_slug (slug)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_methodology_influence_factors (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            primer_id       BIGINT UNSIGNED NOT NULL,
            slug            VARCHAR(64)     NOT NULL,
            title_json      TEXT            DEFAULT NULL,
            description_json LONGTEXT       DEFAULT NULL,
            sub_factors_json LONGTEXT       DEFAULT NULL,
            sort_order      INT             NOT NULL DEFAULT 0,
            is_shipped      TINYINT(1)      NOT NULL DEFAULT 0,
            archived_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_primer (primer_id),
            KEY idx_slug (slug)
        ) {$charset};";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}tt_football_actions (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug            VARCHAR(64)     NOT NULL,
            category_key    VARCHAR(32)     NOT NULL,
            name_json       TEXT            DEFAULT NULL,
            description_json LONGTEXT       DEFAULT NULL,
            sort_order      INT             NOT NULL DEFAULT 0,
            is_shipped      TINYINT(1)      NOT NULL DEFAULT 0,
            archived_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_slug (slug),
            KEY idx_category (category_key),
            KEY idx_is_shipped (is_shipped)
        ) {$charset};";

        foreach ( $sql as $statement ) $wpdb->query( $statement );

        // tt_goals.linked_action_id — additive column for goal → football
        // action linkage, sibling to migration 0015's linked_principle_id.
        $goals_table = $p . 'tt_goals';
        $col_exists  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'linked_action_id'",
            $goals_table
        ) );
        if ( $col_exists === 0 ) {
            $wpdb->query( "ALTER TABLE {$goals_table} ADD COLUMN linked_action_id BIGINT UNSIGNED DEFAULT NULL" );
            $wpdb->query( "ALTER TABLE {$goals_table} ADD KEY idx_linked_action (linked_action_id)" );
        }
    }
};
