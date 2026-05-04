<?php
/**
 * Migration 0062 — FR assignment scope backfill (#0079).
 *
 * Sprint 7 of #0071 stated *"a person assigned as Head Coach via
 * Functional Role gets the Head Coach persona's profile, scoped to that
 * team — assignment auto-elevates within scope"*. The matrix's team-scope
 * check at `MatrixGate::userHasAnyScope` reads `tt_user_role_scopes`
 * exclusively, but the FR assignment write path
 * (`PeopleRepository::assignToTeam`) was never wired to insert the
 * matching scope row. Result on every install since #0071 shipped:
 * FR-assigned head coaches / assistant coaches / team managers fail every
 * team-scoped tile gate and see a near-empty dashboard.
 *
 * #0079 fixes the write path going forward; this migration backfills
 * existing FR assignments so the install lands on the correct state on
 * upgrade.
 *
 * Behaviour:
 *   For every (person_id, team_id) pair present in `tt_team_people` with
 *   a non-null `functional_role_id`, ensure a corresponding
 *   `tt_user_role_scopes` row exists with `scope_type = 'team'` and
 *   `scope_id = team_id`. Idempotent — re-running inserts nothing.
 *
 * `role_id` is set to 0 on inserted rows. Matrix scope checks do not read
 * the column for team scopes (#0033 schema kept the column for the
 * legacy `0028_authorization_user_walk` migration; the matrix path
 * consults `scope_type` + `scope_id` + date range only).
 *
 * Multi-tenant: scoped per club_id via the join, so multi-club installs
 * backfill each club's assignments independently.
 *
 * No data is destroyed; no existing rows are mutated.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0062_fr_assignment_scope_backfill';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Insert one scope row per (person_id, team_id) pair from
        // tt_team_people that does not yet have a matching row in
        // tt_user_role_scopes. The DISTINCT collapses multi-role-on-
        // same-team assignments to a single scope row per pair (matches
        // the runtime sync rule in PeopleRepository::syncTeamScopeRow).
        $sql = "INSERT INTO {$p}tt_user_role_scopes
                    (club_id, person_id, role_id, scope_type, scope_id,
                     start_date, end_date, granted_by_person_id, created_at)
                SELECT DISTINCT
                    tp.club_id,
                    tp.person_id,
                    0          AS role_id,
                    'team'     AS scope_type,
                    tp.team_id AS scope_id,
                    NULL       AS start_date,
                    NULL       AS end_date,
                    NULL       AS granted_by_person_id,
                    NOW()      AS created_at
                FROM {$p}tt_team_people tp
                LEFT JOIN {$p}tt_user_role_scopes urs
                       ON urs.club_id    = tp.club_id
                      AND urs.person_id  = tp.person_id
                      AND urs.scope_type = 'team'
                      AND urs.scope_id   = tp.team_id
                WHERE tp.functional_role_id IS NOT NULL
                  AND urs.id IS NULL";

        // Suppress wpdb's stderr mirror for this DDL — fresh installs
        // ship the table empty, and that's the expected state.
        $inserted = $wpdb->query( $sql );

        if ( $inserted === false ) {
            // Re-raise so the migration runner records the failure rather
            // than silently moving on.
            throw new \RuntimeException(
                'Backfill failed inserting tt_user_role_scopes rows: ' . (string) $wpdb->last_error
            );
        }

        // Best-effort logging — the migration runner emits its own
        // success line; this gives operators reading the audit log a
        // concrete count. Skipped silently when the audit-log table
        // doesn't yet exist on a fresh install.
        if ( function_exists( 'do_action' ) ) {
            do_action(
                'tt_audit_log_event',
                'authorization.fr_scope_backfill',
                [ 'inserted_rows' => (int) $inserted ]
            );
        }
    }

    public function down(): void {
        // No-op. The inserted scope rows are now the authoritative
        // representation of FR assignments. Reversing the migration
        // would re-introduce the original bug (FR-assigned coaches see
        // a near-empty dashboard) without removing the FR rows that
        // produced them.
    }
};
