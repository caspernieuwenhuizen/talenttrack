<?php
/**
 * Migration 0149 — backfill `tt_team_people.is_head_coach` for rows
 * written before the #1314 fix (closes #1314).
 *
 * Background. Migration 0030 added the `is_head_coach` column with
 * `DEFAULT 0` and backfilled the OLDEST coach assignment per team as
 * `is_head_coach = 1`. After that, the column had no writer — neither
 * `PeopleRepository::assignToTeam()` nor the new-team wizard's
 * `ReviewStep` set it. v3.110.200 then removed the legacy
 * `tt_teams.head_coach_id` dropdown, leaving `tt_team_people` as the
 * only assignment surface — and every head coach assigned through it
 * landed on the assistant_coach persona dashboard because the column
 * stayed at 0.
 *
 * The #1314 PR wires the writer at both insert sites. This migration
 * patches existing installs where rows were already written with
 * `is_head_coach = 0` despite holding the head-coach functional role.
 *
 * Backfill criterion. Flip `is_head_coach` to 1 wherever:
 *   - the linked `tt_functional_roles.role_key = 'head_coach'`, OR
 *   - the row's `role_in_team` string equals `'head_coach'` (covers
 *     rows whose `functional_role_id` is null but role_in_team string
 *     carries the slug).
 *
 * Idempotent — re-running on installs that already match this shape
 * is a no-op (the UPDATE filters on `is_head_coach = 0`).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0149_backfill_is_head_coach_from_role';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_team_people";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Guard: the `is_head_coach` column must exist (migration 0030).
        $col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'is_head_coach'",
            $table
        ) );
        if ( $col === null ) return;

        // Flip via the functional-role join (the modern path).
        $fr_table = "{$p}tt_functional_roles";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fr_table ) ) === $fr_table ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} tp
                 INNER JOIN {$fr_table} fr
                    ON fr.id = tp.functional_role_id
                   AND fr.club_id = tp.club_id
                    SET tp.is_head_coach = 1
                  WHERE tp.is_head_coach = 0
                    AND fr.role_key = %s",
                'head_coach'
            ) );
        }

        // Fallback: rows whose functional_role_id is NULL but
        // role_in_team carries the slug. Covers wizard-seeded rows
        // and any defensive write that bypassed the FR lookup.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
                SET is_head_coach = 1
              WHERE is_head_coach = 0
                AND role_in_team = %s",
            'head_coach'
        ) );
    }

    public function down(): void {
        // Forward-only. Reverting would re-strand head coaches on the
        // assistant_coach dashboard — the regression this migration is
        // closing.
    }
};
