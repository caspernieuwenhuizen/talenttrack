<?php
/**
 * Migration 0130 — MatchPrep roles + set-piece takers (#965).
 *
 * Adds `tt_match_prep_roles` so the 3-column match-prep rework can
 * persist captain + 5 set-piece taker assignments per match prep.
 *
 * One row per (match_prep_id, role_key). Recommended role_key set:
 *   captain, corner_l, corner_r, fk_l, fk_r, penalty
 * Operators may extend the set later (new key, no schema change).
 *
 * Idempotent — CREATE TABLE IF NOT EXISTS.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0130_match_prep_roles';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_match_prep_roles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            match_prep_id BIGINT UNSIGNED NOT NULL,
            role_key VARCHAR(40) NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_prep_role (match_prep_id, role_key),
            UNIQUE KEY uniq_uuid (uuid),
            KEY idx_prep (match_prep_id),
            KEY idx_player (player_id),
            KEY idx_club (club_id)
        ) {$charset}" );
    }
};
