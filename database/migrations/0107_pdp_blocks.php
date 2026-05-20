<?php
/**
 * Migration 0107 — `tt_pdp_blocks` table for academy-configurable PDP
 * cycle blocks (v3.110.191, pilot ask 2026-05-20).
 *
 * Before this ship, PDP conversation windows were always evenly
 * distributed across the season by `PdpConversationsRepository::createCycle()`
 * — the academy had no say in WHEN each block of the cycle landed.
 * Some academies' first PDP block is June–August; others run from
 * August–October; the even-divide didn't capture either.
 *
 * Shape: one row per (academy × season × sequence). The sequence
 * column is 1..N where N is the academy's chosen cycle size for
 * that season. When `createCycle()` runs and a matching set of
 * blocks exists, it copies (start_date, end_date) from this table
 * into the new `tt_pdp_conversations.planning_window_start/_end`
 * pair. When no blocks are configured, the existing even-divide
 * fallback runs (installs that don't configure blocks see no
 * behaviour change).
 *
 * Idempotent. SHOW TABLES guard.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0107_pdp_blocks';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_pdp_blocks';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            return;
        }

        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            season_id BIGINT UNSIGNED NOT NULL,
            sequence TINYINT UNSIGNED NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_club_season_seq (club_id, season_id, sequence),
            KEY idx_club_season (club_id, season_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
};
