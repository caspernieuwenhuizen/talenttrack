<?php
namespace TT\Modules\Trials\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;

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
            // #3238 — `isInputAuthor()`, NOT `canSubmitInput()`. Once the
            // case is decided the latter is false, and routing this through
            // it would lock an assigned assistant coach out of the case
            // entirely the moment a decision was recorded — reintroducing
            // exactly the #3222 bug from the other direction. Being unable
            // to write your input is not the same as being unable to read
            // the case you wrote it on.
            || self::isInputAuthor( $user_id, $case_id );
    }

    public static function canViewSynthesis( int $user_id, int $case_id ): bool {
        if ( self::isManager( $user_id ) ) return true;
        if ( ! user_can( $user_id, 'tt_view_trial_synthesis' ) ) return false;
        return ( new TrialCaseStaffRepository() )->isAssigned( $case_id, $user_id );
    }

    /**
     * Is this user one of the people whose input belongs on this case?
     *
     * The capability-and-assignment half of `canSubmitInput()`, on its own
     * (#3238). It answers "is this their case to have written on", which
     * stays true forever — where `canSubmitInput()` answers "may they write
     * on it *now*", which stops at the decision.
     */
    public static function isInputAuthor( int $user_id, int $case_id ): bool {
        if ( ! user_can( $user_id, 'tt_submit_trial_input' ) ) return false;
        if ( self::isManager( $user_id ) ) return true;
        return ( new TrialCaseStaffRepository() )->isAssigned( $case_id, $user_id );
    }

    public static function canSubmitInput( int $user_id, int $case_id ): bool {
        return self::isInputAuthor( $user_id, $case_id )
            && self::caseAcceptsInput( $case_id );
    }

    /**
     * Case statuses during which an input may still be written (#3238).
     *
     * A staff input is the evidence behind a decision about a minor —
     * whether the academy wanted them, and why. `upsertDraft()` updates the
     * existing row whenever one is found, looking at neither `submitted_at`
     * nor the case status, so an assigned coach could rewrite through the
     * API what they had said about a child **after** the academy decided on
     * the strength of it. `updated_at` moved and the previous wording was
     * gone.
     */
    public const EDITABLE_STATUSES = [ 'open', 'extended' ];

    /**
     * Is this case still open to input?
     *
     * The rule the **screen** has always applied —
     * `FrontendTrialCaseView::renderInputsTab()` renders the own-input form
     * only for these two statuses — and the API did not. The bug was not
     * that the screen was too permissive; it was that the two surfaces
     * disagreed and only one of them enforced anything, so the rule now
     * lives here and both read it.
     *
     * **The decision, not the submission, is the line.** A coach re-reading
     * their own wording an hour after writing it, before anybody has acted
     * on it, is normal practice and should not need a manager. Submitting
     * is not the moment the text acquires consequences — the decision is.
     *
     * An unknown case returns false. A write to a case that does not exist
     * has nothing sensible to do, and failing closed is the right default
     * for a record about a child.
     */
    public static function caseAcceptsInput( int $case_id ): bool {
        if ( $case_id <= 0 ) return false;

        $case = ( new TrialCasesRepository() )->find( $case_id );
        if ( ! $case ) return false;

        return self::statusAcceptsInput( (string) ( $case->status ?? '' ) );
    }

    /**
     * The same question against a status the caller already holds.
     *
     * The actual primitive; `caseAcceptsInput()` is the convenience that
     * loads a case first. A surface that has already fetched the row —
     * `FrontendTrialCaseView` renders from one — should not go back to the
     * database to re-read a column it is holding.
     */
    public static function statusAcceptsInput( string $status ): bool {
        return in_array( $status, self::EDITABLE_STATUSES, true );
    }
}
