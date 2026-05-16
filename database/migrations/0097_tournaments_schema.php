<?php
/**
 * Migration 0097 — #0093 tournament planner foundation. Creates the
 * four tables that back the Tournaments module:
 *
 *   tt_tournaments              — container (name, dates, anchor team,
 *                                 default formation). Root entity gets
 *                                 a uuid column per CLAUDE.md § 4 SaaS
 *                                 readiness.
 *   tt_tournament_matches       — N matches per tournament with
 *                                 per-match duration + substitution
 *                                 windows (JSON) + opponent level +
 *                                 optional formation override + an
 *                                 optional activity_id FK that gets
 *                                 set on kickoff/complete (planning
 *                                 stays free of activities-table churn
 *                                 until the match is real).
 *   tt_tournament_squad         — composite PK (tournament_id, player_id),
 *                                 carries per-player eligible positions
 *                                 (JSON) + optional target_minutes
 *                                 override.
 *   tt_tournament_assignments   — one row per (match, period, player).
 *                                 Bench rows have position_code='BENCH'.
 *                                 Period 0 = opening lineup (starts
 *                                 counter pulls from here).
 *
 * Tenancy: every table carries club_id NOT NULL DEFAULT 1 per
 * CLAUDE.md § 4. The Tournaments REST controller (chunk 2) will filter
 * by club_id in every WHERE clause.
 *
 * Idempotent. dbDelta + IF NOT EXISTS.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0097_tournaments_schema';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Container. team_id is NOT NULL per the shaping decision —
        // every tournament is anchored to a single team; cross-team
        // squad additions happen via tt_tournament_squad rows, not by
        // making team_id nullable.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_tournaments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            name VARCHAR(190) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE DEFAULT NULL,
            default_formation VARCHAR(32) DEFAULT NULL,
            team_id BIGINT UNSIGNED NOT NULL,
            notes TEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            archived_at DATETIME DEFAULT NULL,
            archived_by BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club_active (club_id, archived_at),
            KEY idx_team (team_id),
            KEY idx_dates (start_date, end_date)
        ) $charset;" );

        // Matches inside a tournament. substitution_windows is the
        // canonical source for period count: N windows → N+1 periods.
        // The activity_id FK is OPTIONAL (planning-only matches stay
        // unlinked); kickoff/complete sets it and creates an activity
        // row of type 'match' so the player-journey + attendance
        // rollups fire automatically.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_tournament_matches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tournament_id BIGINT UNSIGNED NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            sequence TINYINT UNSIGNED NOT NULL DEFAULT 1,
            label VARCHAR(190) DEFAULT NULL,
            opponent_name VARCHAR(190) DEFAULT NULL,
            opponent_level VARCHAR(64) DEFAULT NULL,
            formation VARCHAR(32) DEFAULT NULL,
            duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 20,
            substitution_windows JSON NOT NULL,
            scheduled_at DATETIME DEFAULT NULL,
            kicked_off_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            activity_id BIGINT UNSIGNED DEFAULT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_tournament_seq (tournament_id, sequence),
            KEY idx_activity (activity_id),
            KEY idx_lifecycle (kicked_off_at, completed_at)
        ) $charset;" );

        // Squad. Composite PK (tournament_id, player_id) — a player
        // can be in many tournaments but only once per tournament.
        // eligible_positions is a JSON array of position types
        // ('GK','DEF','MID','FWD'). target_minutes is the per-player
        // escape hatch overriding the equal-share default (e.g.
        // 30 min for a player returning from injury).
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_tournament_squad (
            tournament_id BIGINT UNSIGNED NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            eligible_positions JSON NOT NULL,
            target_minutes SMALLINT UNSIGNED DEFAULT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (tournament_id, player_id),
            KEY idx_player_history (player_id)
        ) $charset;" );

        // Assignments — the actual plan. One row per (match, period,
        // player). Bench rows have position_code='BENCH'. Period 0 is
        // the opening lineup; starts = COUNT(DISTINCT match_id)
        // WHERE period_index=0 AND position_code != 'BENCH'.
        //
        // The index on player_id is for the per-player rollup query
        // used by the minutes ticker + (later) the profile tab in
        // follow-up #0094.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_tournament_assignments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            match_id BIGINT UNSIGNED NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            period_index TINYINT UNSIGNED NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            position_code VARCHAR(16) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_slot (match_id, period_index, player_id),
            KEY idx_match_period (match_id, period_index, position_code),
            KEY idx_player (player_id)
        ) $charset;" );
    }
};
