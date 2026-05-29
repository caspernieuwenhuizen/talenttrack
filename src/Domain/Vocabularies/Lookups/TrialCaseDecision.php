<?php
/**
 * TrialCaseDecision — typed constants for the six decision outcomes
 * stored in `tt_trial_cases.decision`.
 *
 * Backs the `trial_case_decision` lookup (operator-editable, seeded by
 * migration 0116 with per-locale translations through `tt_translations`).
 * Heavy operator surface — trial workflow varies a lot by academy (#803
 * audit; #842) — but the six stored keys stay sacred.
 *
 * Three are the classic admit / decline / decline-with-encouragement
 * triad; the remaining three (#0081 child 4) drive the rolling-membership
 * loop for trial-group continuation:
 *
 *  - `OFFERED_TEAM_POSITION`     -> AwaitTeamOfferDecisionTemplate spawns
 *  - `DECLINED_OFFERED_POSITION` -> terminal (parent + player declined)
 *  - `CONTINUE_IN_TRIAL_GROUP`   -> case stays open, ReviewTrialGroupMembershipTemplate
 *                                    re-spawns in 90 days
 *
 * `TrialCasesRepository::DECISION_*` constants are the existing internal
 * contract; this Vocabulary class mirrors them as the canonical reference
 * for any other PHP site comparing a stored decision value.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $case->decision === TrialCaseDecision::ADMIT ) { ... }
 *     in_array( $case->decision, [
 *         TrialCaseDecision::DENY_FINAL,
 *         TrialCaseDecision::DENY_ENCOURAGEMENT,
 *     ], true );
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class TrialCaseDecision {

    public const ADMIT                       = 'admit';
    public const DENY_FINAL                  = 'deny_final';
    public const DENY_ENCOURAGEMENT          = 'deny_encouragement';
    public const OFFERED_TEAM_POSITION       = 'offered_team_position';
    public const DECLINED_OFFERED_POSITION   = 'declined_offered_position';
    public const CONTINUE_IN_TRIAL_GROUP     = 'continue_in_trial_group';

    /** @var list<string> */
    public const ALL = [
        self::ADMIT,
        self::DENY_FINAL,
        self::DENY_ENCOURAGEMENT,
        self::OFFERED_TEAM_POSITION,
        self::DECLINED_OFFERED_POSITION,
        self::CONTINUE_IN_TRIAL_GROUP,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
