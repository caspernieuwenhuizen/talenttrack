<?php
/**
 * Migration 0171 — backfill team-scope grants for every team-people link (#1758).
 *
 * Coach/staff team visibility resolves through
 * `QueryHelpers::get_teams_for_coach()`, whose single source of truth is
 * an active `tt_user_role_scopes` row (scope_type='team') for the staff
 * member's `tt_people` id. The Staff section writes that grant at every
 * assignment (`PeopleRepository::assignToTeam` -> `syncTeamScopeRow`).
 *
 * But the legacy `tt_teams.head_coach_id` backfill (migration 0006) only
 * created `tt_team_people` rows — it never created the matching
 * `tt_user_role_scopes` grant. So a head coach assigned the legacy way has
 * a people-link but no matrix scope grant, and `coach_owns_player()`
 * returns false: their players' PDP files (and any other team-scoped
 * surface) are hidden from them, while HoD/admin see them via the global
 * branch. (#1758)
 *
 * Fix, idempotently: for every `tt_team_people` link that lacks an active
 * `tt_user_role_scopes` team grant, create one — mirroring the shape
 * `syncTeamScopeRow()` writes (role_id 0, open date window). Set-based and
 * re-runnable: once the grants exist the LEFT JOIN finds them and nothing
 * is inserted.
 *
 * Forward-only: the grant IS the authorization fact; removing it would
 * re-hide legacy-assigned coaches.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0171_backfill_team_people_role_scopes';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $people_t = "{$p}tt_team_people";
        $urs_t    = "{$p}tt_user_role_scopes";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $people_t ) ) !== $people_t ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $urs_t ) ) !== $urs_t ) return;
        // club_id (tenancy scaffold, 0038) is part of the grant key.
        if ( ! $this->columnExists( $people_t, 'club_id' ) ) return;
        if ( ! $this->columnExists( $urs_t, 'club_id' ) ) return;

        // One team-scope grant per (person, team, club). role_id 0 and the
        // open date window match syncTeamScopeRow(); get_teams_for_coach()
        // keys on (person, scope_type, scope_id, club_id) + active window,
        // not role_id, so a single grant suffices.
        $wpdb->query(
            "INSERT INTO {$urs_t}
                 (club_id, person_id, role_id, scope_type, scope_id, start_date, end_date)
             SELECT DISTINCT tp.club_id, tp.person_id, 0, 'team', tp.team_id, NULL, NULL
               FROM {$people_t} tp
               LEFT JOIN {$urs_t} urs
                 ON urs.person_id = tp.person_id
                AND urs.scope_type = 'team'
                AND urs.scope_id   = tp.team_id
                AND urs.club_id    = tp.club_id
              WHERE urs.id IS NULL
                AND tp.person_id > 0
                AND tp.team_id   > 0"
        );
    }

    private function columnExists( string $table, string $column ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table, $column
        ) );
    }

    public function down(): void {
        // Forward-only — the grant is the authorization fact; dropping it
        // would re-hide legacy-assigned coaches (#1758).
    }
};
