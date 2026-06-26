<?php
namespace TT\Modules\TeamDevelopment;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\MatrixGate;

/**
 * TeamChemistryAccess — the single `team_chemistry` authorization
 * decision (#1922, child of #1757).
 *
 * Before this class the chemistry / blueprint surfaces decided access
 * with raw `current_user_can( 'tt_view_team_chemistry' )` /
 * `current_user_can( 'tt_manage_team_chemistry' )` gates in three
 * places — the frontend blueprint view, the REST controller, and the
 * share-link rotation handler. Those caps are NOT routed through the
 * authorization matrix (they are absent from `LegacyCapMapper::MAPPING`),
 * so the effective answer was the raw WP role grants written by
 * `TeamDevelopmentModule::ensureCapabilities()`, which had drifted from
 * the matrix seed.
 *
 * This helper makes the authorization matrix the single source of truth
 * for `team_chemistry`. The frontend render gates and the REST
 * `permission_callback`s both call into it, so the two surfaces can
 * never diverge.
 *
 * Authority resolution (capabilities / personas, never role-name string
 * compares — docs/access-control.md #0052): the decision is
 * `MatrixGate`-resolved against the `team_chemistry` matrix rows. A
 * future SaaS auth backend that does not preserve WP role slugs gets the
 * same answer.
 *
 * Effective access per the matrix seed (`config/authorization_seed.php`):
 *   - head_coach        — `team_chemistry [rc, team]`  (read + manage at team scope)
 *   - team_manager      — `team_chemistry [r,  team]`  (read only)
 *   - scout             — `team_chemistry [r,  global]`(read only)
 *   - head_of_development — `team_chemistry [r, global]` (read only)
 *   - academy_admin     — `team_chemistry [rcd, global]`(read + manage)
 *   - assistant_coach   — NO row (removed by the #1060 "AC is operational"
 *                         decision) → no read, no manage.
 *   - readonly_observer — NO entity rows at all → no read, no manage.
 *
 * Feature-toggle split (#1485): the `team_chemistry` *entity* is owned by
 * the `team_chemistry` sub-feature. The chemistry-board surfaces honour
 * that toggle; the Team blueprint editor deliberately stays available
 * when the feature is off (its surface is governed by the
 * `team_blueprints` features instead). So:
 *   - `canReadChemistry()` / `canManageChemistry()` apply the feature gate
 *     (board surfaces).
 *   - `canRead()` / `canManage()` answer matrix authority only, ignoring
 *     the feature toggle (blueprint surfaces that survive the switch).
 */
final class TeamChemistryAccess {

    public const ENTITY = 'team_chemistry';

    /**
     * Matrix authority to READ team-chemistry data, ignoring the
     * sub-feature toggle. The decision the Team blueprint surfaces use.
     */
    public static function canRead( int $user_id ): bool {
        return MatrixGate::hasAuthorityAnyScope( $user_id, self::ENTITY, MatrixGate::READ );
    }

    /**
     * Matrix authority to MANAGE (edit) team-chemistry data, ignoring the
     * sub-feature toggle. The decision the Team blueprint write surfaces
     * (editor controls, REST writes, share-link rotation) use.
     */
    public static function canManage( int $user_id ): bool {
        return MatrixGate::hasAuthorityAnyScope( $user_id, self::ENTITY, MatrixGate::CHANGE );
    }

    /**
     * READ authority AND the `team_chemistry` sub-feature being on — the
     * decision the chemistry-board read routes use (mirrors the prior
     * #1485 `can_view_chemistry()` two-part check).
     */
    public static function canReadChemistry( int $user_id ): bool {
        return MatrixGate::canAnyScope( $user_id, self::ENTITY, MatrixGate::READ );
    }

    /**
     * MANAGE authority AND the `team_chemistry` sub-feature being on — the
     * decision the chemistry-board write routes use (mirrors the prior
     * #1485 `can_manage_chemistry()` two-part check).
     */
    public static function canManageChemistry( int $user_id ): bool {
        return MatrixGate::canAnyScope( $user_id, self::ENTITY, MatrixGate::CHANGE );
    }
}
