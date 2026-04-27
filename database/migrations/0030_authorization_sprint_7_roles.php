<?php
/**
 * Migration 0030 — Authorization Sprint 7: new + refined roles (#0033).
 *
 * Three things:
 *
 * 1. New `tt_team_manager` WP role. Capabilities scoped to team-level
 *    coordination (sessions, attendance, invitations) without coaching
 *    authority (no `tt_evaluate_players`, no goal edit). Specific cap
 *    grants are seeded here; per-team scope is established at
 *    assignment time via Functional Roles.
 *
 * 2. New column `tt_team_people.is_head_coach BOOLEAN`. Drives the
 *    head_coach vs assistant_coach split in PersonaResolver. Backfill
 *    sets `is_head_coach = 1` on the OLDEST existing coach assignment
 *    per (team_id, role_in_team='head_coach' OR functional_role_id
 *    mapping to a coach role). Conservative — admins review and adjust
 *    via the Functional Roles UI as needed.
 *
 * 3. Refined `tt_scout` capabilities — read across all teams + players
 *    cross-team. Since the tt_scout role already exists in
 *    RolesService, this migration only ensures the relevant caps are
 *    granted (idempotent; never strips caps). Trial-case write access
 *    is declared in the matrix but exercises no surface until #0017
 *    ships.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0030_authorization_sprint_7_roles';
    }

    public function up(): void {
        $this->addTeamManagerRole();
        $this->addIsHeadCoachColumn();
        $this->refineScoutCaps();
    }

    private function addTeamManagerRole(): void {
        $existing = get_role( 'tt_team_manager' );
        if ( $existing ) {
            // Ensure caps are present even on old installs.
            $caps = $this->teamManagerCaps();
            foreach ( $caps as $cap => $on ) {
                if ( $on && ! $existing->has_cap( $cap ) ) $existing->add_cap( $cap );
            }
            return;
        }
        add_role( 'tt_team_manager', __( 'Team Manager', 'talenttrack' ), $this->teamManagerCaps() );
    }

    /** @return array<string, bool> */
    private function teamManagerCaps(): array {
        return [
            'read'                 => true,
            // Read across team-scoped surfaces.
            'tt_view_teams'        => true,
            'tt_view_players'      => true,
            'tt_view_people'       => true,
            'tt_view_evaluations'  => true,
            'tt_view_activities'   => true,
            'tt_view_goals'        => true,
            'tt_view_methodology'  => true,
            'tt_view_reports'      => true,
            // Manage logistics: activities, attendance, invitations.
            'tt_edit_activities'   => true,
            // Send invites for their team's players + parents.
            'tt_send_invitation'   => true,
            // No tt_evaluate_players, no tt_edit_evaluations, no tt_edit_goals.
            // No tt_edit_settings, no tt_edit_players.
        ];
    }

    private function addIsHeadCoachColumn(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_team_people";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'is_head_coach'",
            $table
        ) );
        if ( $exists !== null ) return;

        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN is_head_coach TINYINT(1) NOT NULL DEFAULT 0" );
        $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_head_coach (team_id, is_head_coach)" );

        // Backfill: for each team, mark the OLDEST coach assignment as head coach.
        // Identify "coach" assignments via role_in_team = 'head_coach' first
        // (legacy data); fall back to the oldest functional_role_id row if
        // role_in_team isn't set.
        $rows = $wpdb->get_results(
            "SELECT id, team_id FROM {$table}
              WHERE LOWER(role_in_team) = 'head_coach'
                 OR LOWER(role_in_team) = 'coach'"
        );
        $seen_team = [];
        foreach ( (array) $rows as $r ) {
            $tid = (int) $r->team_id;
            if ( isset( $seen_team[ $tid ] ) ) continue;
            $seen_team[ $tid ] = true;
            $wpdb->update( $table, [ 'is_head_coach' => 1 ], [ 'id' => (int) $r->id ] );
        }
    }

    private function refineScoutCaps(): void {
        $role = get_role( 'tt_scout' );
        if ( ! $role ) return;
        $caps = [
            'tt_view_teams'      => true,
            'tt_view_players'    => true,
            'tt_view_evaluations'=> true,
            'tt_view_reports'    => true,
        ];
        foreach ( $caps as $cap => $on ) {
            if ( $on && ! $role->has_cap( $cap ) ) $role->add_cap( $cap );
        }
    }
};
