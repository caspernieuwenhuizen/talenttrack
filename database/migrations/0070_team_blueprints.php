<?php
/**
 * Migration 0070 — Team blueprints (#0068 follow-up: Phase 1).
 *
 * Persisted, coach-authored lineups on top of the team-chemistry
 * board. Phase 1 ships the match-day flavour only (squad plan +
 * trial overlay land in Phase 2).
 *
 *   tt_team_blueprints              — meta: name, formation, status
 *   tt_team_blueprint_assignments   — slot-label → player_id
 *
 * Status flow: draft → shared → locked. Locked blueprints are
 * read-only; reopen requires manage cap. Drafts are private to the
 * creator + admin until shared. Engine reuse: BlueprintChemistryEngine
 * (already shipped in v3.96.0) consumes the assignments via
 * `computeForLineup()` so the editor can recompute Link chemistry on
 * every drag-drop without round-tripping the chemistry math.
 *
 * Idempotent. CREATE TABLE IF NOT EXISTS via dbDelta.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0070_team_blueprints';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $blueprints = "CREATE TABLE IF NOT EXISTS {$p}tt_team_blueprints (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) DEFAULT NULL,
            team_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            flavour VARCHAR(20) NOT NULL DEFAULT 'match_day',
            formation_template_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            notes TEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_team (team_id),
            KEY idx_club_team (club_id, team_id),
            KEY idx_status (status)
        ) $charset;";
        dbDelta( $blueprints );

        $assignments = "CREATE TABLE IF NOT EXISTS {$p}tt_team_blueprint_assignments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            blueprint_id BIGINT UNSIGNED NOT NULL,
            slot_label VARCHAR(20) NOT NULL,
            player_id BIGINT UNSIGNED DEFAULT NULL,
            assignment_notes VARCHAR(500) DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_slot (blueprint_id, slot_label),
            KEY idx_blueprint (blueprint_id),
            KEY idx_player (player_id)
        ) $charset;";
        dbDelta( $assignments );
    }
};
