<?php
/**
 * Migration: 0033_team_chemistry_extras
 *
 * #0018 sprints 2-5 — completes the team development epic schema.
 *
 * Adds:
 *   - tt_team_chemistry_pairings  (sprint 4 — coach-marked pairings)
 *   - tt_players.position_side_preference  (sprint 2 — left/right/center, NULL)
 *
 * Idempotent. Safe to re-run.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0033_team_chemistry_extras';
    }

    public function up(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$p}tt_team_chemistry_pairings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id BIGINT UNSIGNED NOT NULL,
            player_a_id BIGINT UNSIGNED NOT NULL,
            player_b_id BIGINT UNSIGNED NOT NULL,
            note VARCHAR(500) DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_pairing (team_id, player_a_id, player_b_id),
            KEY idx_team (team_id),
            KEY idx_player_a (player_a_id),
            KEY idx_player_b (player_b_id)
        ) $c;";
        dbDelta( $sql );

        // Side preference column on tt_players. dbDelta is unreliable for
        // ALTER on existing tables; use a guarded direct ALTER.
        $players_table = $p . 'tt_players';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $players_table ) ) === $players_table ) {
            $has_col = $wpdb->get_row( $wpdb->prepare(
                "SHOW COLUMNS FROM `$players_table` LIKE %s",
                'position_side_preference'
            ) );
            if ( $has_col === null ) {
                $wpdb->query(
                    "ALTER TABLE `$players_table` ADD COLUMN `position_side_preference` VARCHAR(10) DEFAULT NULL"
                );
            }
        }
    }
};
