<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\MatrixGate;

/**
 * TeamSpondAccess (#2388) — the single authority for "may this user manage
 * the Spond connection of *this* team?".
 *
 * Resolves against the `spond_integration` matrix entity's `change`
 * activity: an academy admin holds it globally, a head coach holds it at
 * `team` scope for the team(s) they run. Checking the specific `team_id`
 * (not "any scope") is what scopes a head coach to their own team and
 * closes the pre-#2388 hole where the per-team credential endpoints, gated
 * only on the any-scope `tt_edit_spond_credentials` cap, accepted a head
 * coach's write against *any* team.
 *
 * The REST permission callbacks, the connect view's dispatch guard, and
 * the affordance that links to it all call this one method, so the gate on
 * the button can never drift from the gate on the endpoint (CLAUDE.md §7).
 */
final class TeamSpondAccess {

    public static function canManage( int $user_id, int $team_id ): bool {
        if ( $user_id <= 0 || $team_id <= 0 ) return false;

        // WP administrator + Head of Development: unconditional, mirroring
        // AuthorizationService::userHasPermission's short-circuit, so the
        // admin path never depends on the matrix being seeded or active.
        if ( user_can( $user_id, 'administrator' ) || user_can( $user_id, 'tt_head_dev' ) ) {
            return true;
        }

        // Otherwise: global change authority on spond_integration (an
        // academy-admin persona) OR change authority scoped to THIS exact
        // team (a head coach assigned to it). Checking the specific team is
        // what scopes a head coach to their own team and denies another
        // team — the pre-#2388 hole.
        return MatrixGate::can( $user_id, 'spond_integration', 'change', MatrixGate::SCOPE_GLOBAL )
            || MatrixGate::can( $user_id, 'spond_integration', 'change', MatrixGate::SCOPE_TEAM, $team_id );
    }

    /** Convenience for view/affordance gating with the logged-in user. */
    public static function currentUserCanManage( int $team_id ): bool {
        return self::canManage( get_current_user_id(), $team_id );
    }
}
