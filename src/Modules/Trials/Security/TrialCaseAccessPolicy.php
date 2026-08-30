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
 *   - canOpenCase     — reach the case at all.
 *   - canViewSynthesis — read execution + aggregation tabs.
 *   - canSubmitInput  — write own input on the Staff Inputs tab.
 *
 * #3222 — `canOpenCase()` exists because the case view used to gate its
 * entry on `canViewSynthesis()`, treating "may read other coaches' input"
 * as a proxy for "may see this case at all". Those are not the same
 * question, and an assistant coach is the persona that falls between
 * them: the seed grants them `trial_inputs: change` and no
 * `trial_synthesis`, so an assigned assistant coach was entitled to write
 * an input and could not open the screen holding the field. The
 * capability was reachable over REST and by no UI at all.
 */
final class TrialCaseAccessPolicy {

    public static function isManager( int $user_id ): bool {
        return user_can( $user_id, 'tt_manage_trials' );
    }

    public static function canManageCase( int $user_id, int $case_id ): bool {
        return self::isManager( $user_id );
    }

    /**
     * May this user reach the case at all?
     *
     * Deliberately the union of the two things a non-manager can be here
     * to do: read the synthesis, or write their own input. It does NOT
     * widen either of those — what a user sees once inside is still
     * decided per tab, and `canViewSynthesis()` remains the gate on the
     * Execution tab and on every aggregation of other coaches' input.
     *
     * Both branches already require assignment for a non-manager, so this
     * grants nothing to a coach who is not on the case.
     */
    public static function canOpenCase( int $user_id, int $case_id ): bool {
        return self::canViewSynthesis( $user_id, $case_id )
            || self::canSubmitInput( $user_id, $case_id );
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
