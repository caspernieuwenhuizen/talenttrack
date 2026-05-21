<?php
/**
 * Migration 0120 — MatchExecution schema (#847).
 *
 * Three new tables capturing the live-match state captured by the
 * assistant coach from a phone on the sideline:
 *   - tt_match_execution (1:1 with the match activity)
 *   - tt_match_execution_substitutions (append-only event log)
 *   - tt_match_execution_goal_events (append-only event log)
 *
 * Plus two new columns on tt_activities (home_score, away_score) and
 * one on tt_attendance (minutes_played) so the end-of-match auto-flow
 * can copy state from the execution row into the canonical reporting
 * surfaces.
 *
 * Idempotent — CREATE TABLE IF NOT EXISTS + ALTER ADD COLUMN IF NOT
 * EXISTS-equivalent guards (SHOW COLUMNS check).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0120_match_execution';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_match_execution (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            activity_id BIGINT UNSIGNED NOT NULL,
            match_prep_id BIGINT UNSIGNED NOT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'not_started',
            first_half_started_at DATETIME DEFAULT NULL,
            first_half_ended_at DATETIME DEFAULT NULL,
            second_half_started_at DATETIME DEFAULT NULL,
            second_half_ended_at DATETIME DEFAULT NULL,
            first_half_pause_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            second_half_pause_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            home_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            away_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by BIGINT UNSIGNED,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_activity (activity_id),
            UNIQUE KEY uniq_uuid (uuid),
            KEY idx_club (club_id)
        ) {$charset}" );

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_match_execution_substitutions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_uuid CHAR(36) NOT NULL,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            execution_id BIGINT UNSIGNED NOT NULL,
            half TINYINT UNSIGNED NOT NULL,
            minute_in_half SMALLINT UNSIGNED NOT NULL,
            player_off_id BIGINT UNSIGNED NOT NULL,
            player_on_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            reversed_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_event (event_uuid),
            KEY idx_exec (execution_id),
            KEY idx_exec_half (execution_id, half)
        ) {$charset}" );

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_match_execution_goal_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_uuid CHAR(36) NOT NULL,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            execution_id BIGINT UNSIGNED NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            half TINYINT UNSIGNED NOT NULL,
            minute_in_half SMALLINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            reversed_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_event (event_uuid),
            KEY idx_exec_player (execution_id, player_id)
        ) {$charset}" );

        // home_score + away_score on tt_activities for the end-of-match
        // copy. minutes_played on tt_attendance for per-player minutes.
        self::addColumnIfMissing( $wpdb, "{$p}tt_activities", 'home_score', "TINYINT UNSIGNED DEFAULT NULL" );
        self::addColumnIfMissing( $wpdb, "{$p}tt_activities", 'away_score', "TINYINT UNSIGNED DEFAULT NULL" );
        self::addColumnIfMissing( $wpdb, "{$p}tt_attendance",  'minutes_played', "SMALLINT UNSIGNED DEFAULT NULL" );
    }

    private static function addColumnIfMissing( \wpdb $wpdb, string $table, string $column, string $spec ): void {
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;
        $existing = $wpdb->get_results( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            $column
        ) );
        if ( ! empty( $existing ) ) return;
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$spec}" );
    }
};
