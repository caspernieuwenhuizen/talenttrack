<?php
namespace TT\Modules\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AllTeamsScope — single answer to "may this user see beyond their own
 * teams on this surface?" (#1942).
 *
 * Replaces the phantom-cap idiom
 * `current_user_can( 'tt_view_all_teams' ) || current_user_can( 'tt_edit_settings' )`
 * that gated the academy-wide lens across reports, analytics, the cohort
 * board, the team planner, match-execution surfaces and the
 * matches-needing-review widget. `tt_view_all_teams` was never granted to
 * any role, so the real gate was `tt_edit_settings` plus the WP-admin
 * bypass — an over-coarse settings cap standing in for "club-wide read".
 *
 * The replacement asks the matrix directly for **global-scope read on the
 * surface's own entity**: a reports surface checks `reports/read/global`,
 * an analytics/attendance surface checks `activities/read/global`, the
 * evaluations audit override checks `evaluations/read/global`. Head of
 * Development and Academy Admin keep the wide view (they hold global read
 * on every surface); scouts gain the club-wide reports / analytics lens
 * where the seed already grants them global read — intended, since a scout
 * reads cross-team by design. Team-scoped coaches stay narrowed to their
 * own teams, as before.
 *
 * Frontend renders and REST permission callbacks both route through here,
 * so the two sides can no longer answer the all-teams question differently.
 */
final class AllTeamsScope {

    /**
     * Does the user hold global-scope read on the given surface entity —
     * i.e. may they see every team's data on a surface backed by that
     * entity, not just the teams they are assigned to?
     *
     * @param int    $user_id WP user id.
     * @param string $entity  The surface's own matrix entity
     *                        (e.g. 'reports', 'activities', 'evaluations').
     */
    public static function canSeeAllTeams( int $user_id, string $entity ): bool {
        return MatrixGate::can( $user_id, $entity, MatrixGate::READ, MatrixGate::SCOPE_GLOBAL );
    }

    /** Reports surfaces — global read on `reports`. */
    public static function canSeeAllTeamsReports( int $user_id ): bool {
        return self::canSeeAllTeams( $user_id, 'reports' );
    }

    /** Analytics / attendance / minutes surfaces — global read on `activities`. */
    public static function canSeeAllTeamsActivities( int $user_id ): bool {
        return self::canSeeAllTeams( $user_id, 'activities' );
    }

    /** Evaluations audit override — global read on `evaluations`. */
    public static function canSeeAllTeamsEvaluations( int $user_id ): bool {
        return self::canSeeAllTeams( $user_id, 'evaluations' );
    }

    /**
     * #3152 — may this user read **this** team?
     *
     * The sibling above answers "may you see beyond your own teams". Four
     * surfaces asked that question, or the coarser `current_user_can(
     * 'tt_view_teams' )`, and then took the team id straight out of the URL.
     * `tt_view_teams` is club-wide on `tt_coach`, so a head coach could walk
     * `?id=1,2,3…` and read every roster in the academy.
     *
     * This is the per-record form, and it is deliberately the same answer
     * `GET /teams` already gives when it decides which rows to include:
     * a global `team` read (or the WordPress settings admin) sees every
     * squad, everyone else sees the teams they are assigned to.
     *
     * Archived teams count. Whether you coach a team is a fact about the
     * assignment, not about the team's lifecycle — and a detail view has an
     * archived read-only mode that a scope check must not swallow.
     */
    public static function canReadTeam( int $user_id, int $team_id ): bool {
        return self::canReadTeamFor( $user_id, $team_id, 'team' );
    }

    /**
     * #3154 — the same question, asked about a surface whose entity is not
     * `team`.
     *
     * A route that takes a team id and returns something other than the team
     * row — the cohort board's rows, a squad's player statuses — is scoped by
     * the entity that names its *data*, not by `team`. Otherwise a persona
     * with global read on player statuses but not on teams would be refused
     * a board it is explicitly granted.
     *
     * `CohortBoardRestController::callerCanReadTeam()` was the first copy of
     * this and is now a wrapper around it, so a second controller does not
     * mean a second implementation of the rule.
     */
    public static function canReadTeamFor( int $user_id, int $team_id, string $entity ): bool {
        if ( $user_id <= 0 || $team_id <= 0 || $entity === '' ) return false;

        if ( \TT\Infrastructure\Query\QueryHelpers::user_has_global_entity_read( $user_id, $entity ) ) {
            return true;
        }

        foreach ( \TT\Infrastructure\Query\QueryHelpers::get_teams_for_coach( $user_id, true ) as $team ) {
            if ( (int) ( $team->id ?? 0 ) === $team_id ) return true;
        }

        return false;
    }
}
