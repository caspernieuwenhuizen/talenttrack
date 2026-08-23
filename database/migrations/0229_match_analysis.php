<?php
/**
 * Migration 0229 — per-match analysis (#2705, epic #2704).
 *
 * Three tables behind one aggregate: the analysis of a single match, the
 * per-team-function sections that make up its body, and the per-player
 * items that name who stood out and who fell short.
 *
 * ## Why the sections are rows and not columns
 *
 * `tt_match_prep` stores its five goal boxes as five columns, and that is
 * the shape this deliberately does not copy. The section vocabulary here is
 * the methodology's own team functions (`MethodologyEnums::teamFunctions()`)
 * plus set pieces and a general summary — a taxonomy the module docblock
 * describes as opinionated but code-level extensible. A row per section
 * means adding one is a constant, not an ALTER; a column per section means
 * every academy that phrases its methodology differently is stuck with
 * ours.
 *
 * ## One analysis per activity
 *
 * `uq_activity` is on `(club_id, activity_id)`. A match has one analysis:
 * two coaches writing separate reviews of the same game would split the
 * player's record in half, which is the thing CLAUDE.md §1 exists to
 * prevent. Disagreement belongs in the text, not in a second row.
 *
 * ## Tenancy and identity
 *
 * `club_id` on all three tables per CLAUDE.md §4, and `uuid` on the root
 * entity — it is what the share link addresses, so it has to be
 * unguessable independent of the sequential id.
 *
 * `share_token_seed` backs the rotatable HMAC share link
 * (`MatchAnalysisShareToken`), the same mechanism `tt_team_blueprints`
 * uses. It is lazily initialised on first use rather than at insert, so an
 * analysis nobody shares never has a secret sitting on it.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0229_match_analysis';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_match_analyses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            activity_id BIGINT UNSIGNED NOT NULL,
            match_prep_id BIGINT UNSIGNED DEFAULT NULL,
            match_execution_id BIGINT UNSIGNED DEFAULT NULL,
            summary TEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            share_token_seed VARCHAR(64) NOT NULL DEFAULT '',
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            UNIQUE KEY uk_activity (club_id, activity_id),
            KEY idx_club_status (club_id, status)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_match_analysis_sections (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            analysis_id BIGINT UNSIGNED NOT NULL,
            section_key VARCHAR(64) NOT NULL,
            rating VARCHAR(16) DEFAULT NULL,
            notes TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_analysis_section (analysis_id, section_key),
            KEY idx_club_analysis (club_id, analysis_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_match_analysis_players (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            analysis_id BIGINT UNSIGNED NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            marker VARCHAR(16) NOT NULL DEFAULT '',
            note TEXT NULL,
            team_function VARCHAR(64) DEFAULT NULL,
            minutes_played SMALLINT UNSIGNED DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_analysis_player (analysis_id, player_id),
            KEY idx_club_player (club_id, player_id)
        ) {$charset};" );
    }
};
