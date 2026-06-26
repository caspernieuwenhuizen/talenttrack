<?php
/**
 * Migration 0176 — player-controlled parent visibility (#1867, epic #1846).
 *
 * Lets a player (child) hide individual sections of their record from a
 * linked parent. Default-visible semantics: the ABSENCE of a row means
 * the section is visible, so existing parents keep today's access with
 * no backfill. A row with `visible = 0` hides that section for the
 * player's parents; `visible = 1` is an explicit "shared" record.
 *
 * Tenancy scaffold (`club_id`) per CLAUDE.md §4. Forward-only, additive,
 * idempotent. Run alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0176_player_parent_visibility';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$p}tt_player_parent_visibility (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                player_id BIGINT UNSIGNED NOT NULL,
                section_key VARCHAR(32) NOT NULL,
                visible TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_player_section (club_id, player_id, section_key),
                KEY idx_player (player_id)
            ) {$charset}"
        );
    }
};
