<?php
/**
 * Migration 0118 — MatchPrep schema (#838).
 *
 * Four new tables capturing the head coach's match preparation:
 *   - tt_match_prep (1:1 with match-type activity)
 *   - tt_match_prep_availability (player-level Present/Absent snapshot)
 *   - tt_match_prep_lineup (per-half, per-slot pitch assignment)
 *   - tt_match_prep_player_goals (attention notes + specific-goal + analyst flags)
 *
 * Plus a `hide_from_prep = true` flag inserted into the
 * `Late` row's `tt_lookups.meta` JSON so the AvailabilityStep filters
 * it out of the chip set. Operators can flip the same flag on custom
 * statuses they've added.
 *
 * Idempotent — CREATE TABLE IF NOT EXISTS + JSON_MERGE on meta.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0118_match_prep';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_match_prep (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            activity_id BIGINT UNSIGNED NOT NULL,
            formation_template_id BIGINT UNSIGNED DEFAULT NULL,
            half_length_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 35,
            goals_general TEXT,
            goals_attack TEXT,
            goals_defend TEXT,
            goals_attack_setpiece TEXT,
            goals_defend_setpiece TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by BIGINT UNSIGNED,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_activity (activity_id),
            UNIQUE KEY uniq_uuid (uuid),
            KEY idx_club (club_id)
        ) {$charset}" );

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_match_prep_availability (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            match_prep_id BIGINT UNSIGNED NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Present',
            reason TEXT,
            UNIQUE KEY uniq_prep_player (match_prep_id, player_id),
            KEY idx_prep (match_prep_id),
            KEY idx_club (club_id)
        ) {$charset}" );

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_match_prep_lineup (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            match_prep_id BIGINT UNSIGNED NOT NULL,
            half TINYINT UNSIGNED NOT NULL,
            slot_number TINYINT UNSIGNED NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            UNIQUE KEY uniq_prep_half_slot (match_prep_id, half, slot_number),
            UNIQUE KEY uniq_prep_half_player (match_prep_id, half, player_id),
            KEY idx_prep (match_prep_id),
            KEY idx_club (club_id)
        ) {$charset}" );

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_match_prep_player_goals (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            match_prep_id BIGINT UNSIGNED NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            attention_text TEXT,
            is_specific_goal TINYINT(1) NOT NULL DEFAULT 0,
            analyst_appointed TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_prep_player (match_prep_id, player_id),
            KEY idx_prep (match_prep_id),
            KEY idx_club (club_id)
        ) {$charset}" );

        // Set hide_from_prep flag on the canonical `Late` attendance_status
        // row. Operator-added statuses can be flagged the same way via
        // the lookup admin's meta editor.
        $late_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_lookups
              WHERE club_id = %d AND lookup_type = 'attendance_status' AND name = %s
              LIMIT 1",
            1, 'Late'
        ) );
        if ( $late_id > 0 ) {
            $existing = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT meta FROM {$p}tt_lookups WHERE id = %d",
                $late_id
            ) );
            $meta = json_decode( $existing, true );
            if ( ! is_array( $meta ) ) $meta = [];
            $meta['hide_from_prep'] = true;
            $wpdb->update( "{$p}tt_lookups", [ 'meta' => wp_json_encode( $meta ) ], [ 'id' => $late_id ] );
        }
    }
};
