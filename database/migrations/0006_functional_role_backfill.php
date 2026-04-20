<?php
/**
 * Migration 0006 — Functional role backfill.
 *
 * Sprint 1G (v2.10.0) — translates legacy team-staff data into the new
 * functional-role model.
 *
 * What this does:
 *   1. For every tt_team_people row where functional_role_id IS NULL,
 *      look up tt_functional_roles.id by role_key = role_in_team and
 *      set the column.
 *   2. For every tt_teams row where head_coach_id > 0, ensure a
 *      tt_team_people row exists representing that WP user as head_coach
 *      of that team (creating a tt_people record for the user if one
 *      doesn't already exist). This retires the legacy head_coach_id
 *      bridge in favor of explicit tt_team_people entries.
 *
 * Prerequisites:
 *   - tt_functional_roles must be populated (done by
 *     Activator::seedFunctionalRolesIfEmpty() which runs before the
 *     MigrationRunner on activation).
 *
 * Idempotency:
 *   - Step 1 only touches rows where functional_role_id IS NULL.
 *   - Step 2 checks for existing tt_team_people rows before inserting.
 *   - Safe to re-run (but the MigrationRunner won't re-run once applied).
 *
 * Non-destructive:
 *   - Does NOT drop role_in_team from tt_team_people.
 *   - Does NOT drop head_coach_id from tt_teams.
 *   Both columns stay for backward compatibility with any external
 *   queries. AuthorizationService no longer reads them from v2.10.0 on.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0006_functional_role_backfill';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Defensive: if the new tables don't exist (e.g. activation hook
        // didn't fire for some reason), bail early rather than blow up.
        if ( ! $this->tableExists( "{$p}tt_functional_roles" ) ) return;
        if ( ! $this->tableExists( "{$p}tt_functional_role_auth_roles" ) ) return;
        if ( ! $this->columnExists( "{$p}tt_team_people", 'functional_role_id' ) ) return;

        /* ═══════════════════════════════════════════════════════════
         *  Step 1 — backfill tt_team_people.functional_role_id from
         *           the existing role_in_team string values.
         * ═══════════════════════════════════════════════════════════ */

        $rows = $wpdb->get_results(
            "SELECT id, role_in_team
             FROM {$p}tt_team_people
             WHERE functional_role_id IS NULL"
        );

        if ( is_array( $rows ) && ! empty( $rows ) ) {
            // Pre-fetch role_key → id map in one query.
            $fn_map = [];
            $all_fn = $wpdb->get_results( "SELECT id, role_key FROM {$p}tt_functional_roles" );
            if ( is_array( $all_fn ) ) {
                foreach ( $all_fn as $f ) {
                    $fn_map[ (string) $f->role_key ] = (int) $f->id;
                }
            }

            foreach ( $rows as $r ) {
                $role_key = (string) $r->role_in_team;
                if ( ! isset( $fn_map[ $role_key ] ) ) {
                    // Fall back to 'other' for any unrecognized value so the
                    // row still resolves to something after the bridge retires.
                    $fn_id = $fn_map['other'] ?? 0;
                } else {
                    $fn_id = $fn_map[ $role_key ];
                }
                if ( $fn_id <= 0 ) continue;

                $wpdb->update(
                    "{$p}tt_team_people",
                    [ 'functional_role_id' => $fn_id ],
                    [ 'id' => (int) $r->id ]
                );
            }
        }

        /* ═══════════════════════════════════════════════════════════
         *  Step 2 — retire tt_teams.head_coach_id bridge.
         *
         *  For each team with a non-zero head_coach_id, ensure there's
         *  an explicit tt_team_people row for that WP user as head_coach.
         *  Creates a tt_people record for the user if one doesn't exist.
         * ═══════════════════════════════════════════════════════════ */

        if ( ! $this->columnExists( "{$p}tt_teams", 'head_coach_id' ) ) return;

        $head_coach_fn_id = (int) $wpdb->get_var(
            "SELECT id FROM {$p}tt_functional_roles WHERE role_key = 'head_coach'"
        );
        if ( $head_coach_fn_id <= 0 ) return;

        $teams_with_legacy = $wpdb->get_results(
            "SELECT id, head_coach_id FROM {$p}tt_teams WHERE head_coach_id IS NOT NULL AND head_coach_id > 0"
        );
        if ( ! is_array( $teams_with_legacy ) || empty( $teams_with_legacy ) ) return;

        foreach ( $teams_with_legacy as $team ) {
            $team_id = (int) $team->id;
            $wp_user_id = (int) $team->head_coach_id;
            if ( $team_id <= 0 || $wp_user_id <= 0 ) continue;

            // Find or create tt_people record for this WP user.
            $person_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_people WHERE wp_user_id = %d LIMIT 1",
                $wp_user_id
            ) );

            if ( $person_id <= 0 ) {
                $user = get_userdata( $wp_user_id );
                if ( ! $user ) continue; // WP user no longer exists; skip.

                $first = '';
                $last  = '';
                if ( ! empty( $user->first_name ) || ! empty( $user->last_name ) ) {
                    $first = (string) ( $user->first_name ?? '' );
                    $last  = (string) ( $user->last_name ?? '' );
                }
                if ( $first === '' && $last === '' ) {
                    // Fall back to display_name split on first space.
                    $display = (string) ( $user->display_name ?? $user->user_login ?? '' );
                    $parts = preg_split( '/\s+/', trim( $display ), 2 );
                    $first = (string) ( $parts[0] ?? $display );
                    $last  = (string) ( $parts[1] ?? '' );
                }
                if ( $first === '' ) $first = '(unknown)';

                $wpdb->insert( "{$p}tt_people", [
                    'first_name' => $first,
                    'last_name'  => $last,
                    'email'      => $user->user_email ?: null,
                    'role_type'  => 'coach',
                    'wp_user_id' => $wp_user_id,
                    'status'     => 'active',
                ] );
                $person_id = (int) $wpdb->insert_id;
                if ( $person_id <= 0 ) continue;
            }

            // Skip if a head_coach tt_team_people row already exists for
            // this team + person combination (in either column).
            $already = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_team_people
                 WHERE team_id = %d AND person_id = %d
                   AND (functional_role_id = %d OR role_in_team = 'head_coach')",
                $team_id, $person_id, $head_coach_fn_id
            ) );
            if ( $already > 0 ) continue;

            $wpdb->insert( "{$p}tt_team_people", [
                'team_id'            => $team_id,
                'person_id'          => $person_id,
                'functional_role_id' => $head_coach_fn_id,
                'role_in_team'       => 'head_coach',
            ] );
        }
    }

    /* ═══ inline helpers ═══ */

    private function tableExists( string $table ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    private function columnExists( string $table, string $column ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $column ) );
        return $row !== null;
    }
};
