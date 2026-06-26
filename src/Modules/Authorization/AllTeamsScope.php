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
}
