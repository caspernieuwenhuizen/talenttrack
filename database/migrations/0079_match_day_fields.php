<?php
/**
 * Migration 0079 — #0063 use case 4 (match-day team sheet) — adds
 * the match-specific fields on `tt_activities` and the per-row
 * lineup-role + position-played fields on `tt_attendance`.
 *
 * Per user-direction shaping (2026-05-08):
 *   - Q1 yes — filter `tt_activities.activity_type_key = 'match'` to
 *     find matches; add `opponent`, `home_away`, `kickoff_time`,
 *     `formation` columns. Rows whose `activity_type_key` isn't
 *     `'match'` keep these columns NULL — the team-sheet exporter
 *     refuses to render for non-match activities.
 *   - Q2 ok — `tt_attendance.lineup_role` ∈ `start` / `bench`. The
 *     PDF renders Starting XI from `lineup_role = 'start'` and Bench
 *     from `lineup_role = 'bench'`; rows with NULL fall through to
 *     "Squad" if the operator hasn't yet edited the team sheet.
 *   - Q3 ok — `tt_attendance.position_played` per-match override.
 *     Defaults to NULL → fall back to `tt_players.preferred_positions[0]`.
 *
 * Idempotent. SHOW COLUMNS guards.
 *
 * Additive only. No backfill — existing match activities (if any
 * exist with `activity_type_key='match'`) will read NULL for these
 * columns until the operator edits the team sheet for the first time.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0079_match_day_fields';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $activities = "{$p}tt_activities";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $activities ) ) === $activities ) {
            self::addColumn( $activities, 'opponent',     "VARCHAR(255) DEFAULT NULL", 'notes' );
            self::addColumn( $activities, 'home_away',    "VARCHAR(10) DEFAULT NULL",  'opponent' );
            self::addColumn( $activities, 'kickoff_time', "TIME DEFAULT NULL",         'home_away' );
            self::addColumn( $activities, 'formation',    "VARCHAR(20) DEFAULT NULL",  'kickoff_time' );
        }

        $attendance = "{$p}tt_attendance";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $attendance ) ) === $attendance ) {
            self::addColumn( $attendance, 'lineup_role',     "VARCHAR(10) DEFAULT NULL", 'notes' );
            self::addColumn( $attendance, 'position_played', "VARCHAR(20) DEFAULT NULL", 'lineup_role' );
        }
    }

    private static function addColumn( string $table, string $column, string $def, string $after ): void {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            $column
        ) );
        if ( $exists === $column ) return;
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$def} AFTER {$after}" );
    }

};
