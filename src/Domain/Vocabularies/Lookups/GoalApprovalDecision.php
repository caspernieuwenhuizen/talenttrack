<?php
/**
 * GoalApprovalDecision — typed constants for the three approval-form
 * decisions stored in `tt_workflow_tasks.response_json` for the coach's
 * goal-approval review (approve / amend / reject).
 *
 * Backs the `goal_approval_decision` lookup (operator-editable, seeded by
 * migration 0111 with per-locale translations through `tt_translations`).
 *
 * The form-side `GoalApprovalForm::DECISION_*` constants are the existing
 * contract between the form widget and the response JSON; this Vocabulary
 * class mirrors them as the canonical reference for any other PHP site
 * comparing a stored decision value.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $decision === GoalApprovalDecision::APPROVE ) { ... }
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class GoalApprovalDecision {

    public const APPROVE = 'approve';
    public const AMEND   = 'amend';
    public const REJECT  = 'reject';

    /** @var list<string> */
    public const ALL = [
        self::APPROVE,
        self::AMEND,
        self::REJECT,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
