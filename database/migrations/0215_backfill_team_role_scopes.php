<?php
/**
 * Migration 0215 — backfill `tt_user_role_scopes` from `tt_team_people` (#2571).
 *
 * Team assignment is written to `tt_team_people`; team *scope* — the thing
 * `QueryHelpers::get_teams_for_coach()` and `MatrixGate`'s team-scope check
 * read — lives in `tt_user_role_scopes`. `PeopleRepository::syncTeamScopeRow()`
 * mirrors the first into the second, but only on the write paths that call it.
 * The demo generator and the Excel importer both inserted into
 * `tt_team_people` directly, so installs seeded or imported through either
 * path have assignments with no matching scope row.
 *
 * Symptom that surfaced it: the Evaluations list showed a coach nothing at
 * all. With no team scope the list query collapses to "evaluations I
 * personally authored", while the team rating and the player Evaluations tab
 * — neither of which is coach-scoped — kept showing the same data. On the
 * install that reported it, every staff assignment had drifted.
 *
 * This migration inserts the missing scope rows. Shape matches
 * `syncTeamScopeRow()` exactly (`role_id = 0`, open-ended dates) so the
 * migration and the runtime path can't produce different rows:
 *
 *   - one row per distinct (club_id, person_id, team_id) — `tt_team_people`
 *     may hold several assignments for the same pair (different roles), and
 *     the scope is per team, not per role.
 *   - only where the team still exists; an assignment pointing at a deleted
 *     team gets no scope.
 *   - `INSERT ... SELECT ... WHERE NOT EXISTS`, so re-runs are no-ops and
 *     operator-edited rows (dated scopes) are left untouched.
 *
 * Idempotent. Defensive against missing tables.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    public function getName(): string {
        return '0215_backfill_team_role_scopes';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $team_people = "{$p}tt_team_people";
        $scopes      = "{$p}tt_user_role_scopes";
        $teams       = "{$p}tt_teams";

        foreach ( [ $team_people, $scopes, $teams ] as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                Logger::info( 'migration.0215.table_missing', [ 'table' => $table ] );
                return;
            }
        }

        $missing_before = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT tp.club_id, tp.person_id, tp.team_id)
               FROM {$team_people} tp
               JOIN {$teams} t ON t.id = tp.team_id AND t.club_id = tp.club_id
              WHERE NOT EXISTS (
                    SELECT 1 FROM {$scopes} urs
                     WHERE urs.person_id  = tp.person_id
                       AND urs.club_id    = tp.club_id
                       AND urs.scope_type = 'team'
                       AND urs.scope_id   = tp.team_id )"
        );

        $wpdb->query(
            "INSERT INTO {$scopes}
                    (club_id, person_id, role_id, scope_type, scope_id, start_date, end_date)
             SELECT DISTINCT tp.club_id, tp.person_id, 0, 'team', tp.team_id, NULL, NULL
               FROM {$team_people} tp
               JOIN {$teams} t ON t.id = tp.team_id AND t.club_id = tp.club_id
              WHERE NOT EXISTS (
                    SELECT 1 FROM {$scopes} urs
                     WHERE urs.person_id  = tp.person_id
                       AND urs.club_id    = tp.club_id
                       AND urs.scope_type = 'team'
                       AND urs.scope_id   = tp.team_id )"
        );

        $written = (int) $wpdb->rows_affected;

        // Orphans: a scope pointing at a team that no longer exists. The
        // team cascade removed `tt_team_people` but left the scope behind,
        // and `MatrixGate::userHasAnyScope()` counts rows without joining
        // `tt_teams` — so a stale row keeps granting team-scoped access.
        // The cascade now clears these going forward; this clears the
        // backlog.
        $wpdb->query(
            "DELETE urs FROM {$scopes} urs
              LEFT JOIN {$teams} t ON t.id = urs.scope_id AND t.club_id = urs.club_id
              WHERE urs.scope_type = 'team'
                AND t.id IS NULL"
        );

        $orphans_removed = (int) $wpdb->rows_affected;

        Logger::info( 'migration.0215.summary', [
            'missing_before'  => $missing_before,
            'scopes_written'  => $written,
            'orphans_removed' => $orphans_removed,
        ] );
    }
};
