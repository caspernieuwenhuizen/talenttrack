<?php
namespace TT\Modules\Trials\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;

/**
 * Per-case access decisions for the trial module.
 *
 * The capability layer (RolesService + ensureCapabilities) decides
 * "can this user manage trials at all?" — coarse-grained. This class
 * answers the per-case follow-up: given a logged-in user and a case,
 * what are they allowed to do?
 *
 *   - canManageCase   — full edit, decision, archive, release-inputs.
 *   - canViewSynthesis — read execution + aggregation tabs.
 *   - canSubmitInput  — write own input on the Staff Inputs tab.
 */
final class TrialCaseAccessPolicy {

    public static function isManager( int $user_id ): bool {
        return user_can( $user_id, 'tt_manage_trials' );
    }

    public static function canManageCase( int $user_id, int $case_id ): bool {
        return self::isManager( $user_id );
    }

    public static function canViewSynthesis( int $user_id, int $case_id ): bool {
        if ( self::isManager( $user_id ) ) return true;
        if ( ! user_can( $user_id, 'tt_view_trial_synthesis' ) ) return false;
        return ( new TrialCaseStaffRepository() )->isAssigned( $case_id, $user_id );
    }

    public static function canSubmitInput( int $user_id, int $case_id ): bool {
        if ( ! user_can( $user_id, 'tt_submit_trial_input' ) ) return false;
        if ( self::isManager( $user_id ) ) return true;
        return ( new TrialCaseStaffRepository() )->isAssigned( $case_id, $user_id );
    }
}
