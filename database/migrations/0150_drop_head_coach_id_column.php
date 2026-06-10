<?php
/**
 * Migration 0150 — drop the retired `tt_teams.head_coach_id` column
 * (closes #1315).
 *
 * Background. v3.110.200 (#820) removed the legacy "Head coach"
 * dropdown from the frontend team form. v4.20.84 (#1315) finished
 * the retirement: removed the wp-admin counterpart, dropped the
 * column from PersonaResolver / TeamHeadCoachResolver / TeamsRest /
 * QueryHelpers / demo generators / REST response shape, and updated
 * the Activator CREATE TABLE. This migration drops the column.
 *
 * Defensive backfill (no-op after #1314's 0149). For any team whose
 * `head_coach_id` references a WP user, ensure that user has a
 * `tt_team_people` row with `is_head_coach = 1` on that team. Then
 * drop the column.
 *
 * Idempotent — the ALTER guards on column presence.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0150_drop_head_coach_id_column';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $teams_table = "{$p}tt_teams";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $teams_table ) ) !== $teams_table ) {
            return;
        }

        // Column already gone? Nothing to do.
        $col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'head_coach_id'",
            $teams_table
        ) );
        if ( $col === null ) return;

        // Defensive backfill: walk every team whose `head_coach_id`
        // points at a WP user and make sure that user has an
        // `is_head_coach = 1` row in `tt_team_people` on that team.
        // Should be a no-op after migration 0149 — guards against any
        // team that slipped through.
        $tp_table = "{$p}tt_team_people";
        $tp_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tp_table ) ) === $tp_table;
        if ( $tp_exists ) {
            $is_head_coach_col = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'is_head_coach'",
                $tp_table
            ) );
            if ( $is_head_coach_col !== null ) {
                $stranded = $wpdb->get_results(
                    "SELECT t.id AS team_id, t.club_id, t.head_coach_id AS wp_user_id
                       FROM {$teams_table} t
                      WHERE t.head_coach_id > 0
                        AND NOT EXISTS (
                            SELECT 1 FROM {$tp_table} tp
                              JOIN {$p}tt_people pe ON pe.id = tp.person_id
                             WHERE tp.team_id = t.id
                               AND tp.is_head_coach = 1
                               AND pe.wp_user_id = t.head_coach_id
                        )"
                );
                foreach ( (array) $stranded as $row ) {
                    $person_id = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM {$p}tt_people WHERE wp_user_id = %d AND club_id = %d LIMIT 1",
                        (int) $row->wp_user_id, (int) $row->club_id
                    ) );
                    if ( $person_id <= 0 ) continue;
                    $wpdb->insert( $tp_table, [
                        'club_id'        => (int) $row->club_id,
                        'team_id'        => (int) $row->team_id,
                        'person_id'      => $person_id,
                        'role_in_team'   => 'head_coach',
                        'is_head_coach'  => 1,
                    ] );
                }
            }
        }

        // Drop the column.
        $wpdb->query( "ALTER TABLE {$teams_table} DROP COLUMN head_coach_id" );
    }

    public function down(): void {
        // Forward-only. Reverting would restore a dead column with no
        // writer and no way to repopulate it short of replaying every
        // assignment in tt_team_people.
    }
};
