<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ProspectStageClassifier (v3.110.81) — single source of truth for the
 * mapping from a prospect's workflow state to its onboarding-pipeline
 * stage.
 *
 * Stages are reached states, NOT currently-assigned work. Pilot feedback
 * surfaced the prior bug (v3.110.48 logic, fixed in this release):
 *
 *   "the prospect is a prospect and not an invited player until the
 *    email is actually send out, that means the task to do so is
 *    completed."
 *
 * The fix expresses each transition as a milestone (typically the
 * COMPLETION of a chain task), not as the existence of an open task.
 * Concretely, a prospect with an open `invite_to_test_training` task
 * is still in Prospects — nobody has clicked Send yet. Once the HoD
 * completes that task (= the email goes out, the chain dispatches
 * `confirm_test_training`), the prospect moves to Invited.
 *
 *     log_prospect open                    → Prospects (drafted, no chain yet)
 *     invite_to_test_training open         → Prospects (email NOT yet sent)
 *     invite_to_test_training completed    → Invited
 *     confirm_test_training open           → Invited (email sent, awaiting parent)
 *     confirm_test_training completed      → Test training (parent confirmed)
 *     record_test_training_outcome open    → Test training (session scheduled)
 *     record_test_training_outcome done    → Test training (test done, decision pending)
 *     promoted_to_trial_case_id            → Trial group
 *     await_team_offer_decision open       → Team offer
 *     promoted_to_player_id (≤ 90d)        → Joined (rolling window)
 *
 * Mutually exclusive — the function returns exactly one of
 * prospects / invited / test / trial / offer / joined, or null when the
 * prospect should not appear (joined > 90 days ago — they belong to
 * the players surface now, not the funnel).
 *
 * The query row passed in must carry these properties (column names
 * mirror what both consumers — `OnboardingPipelineWidget` and
 * `FrontendOnboardingPipelineView` — select):
 *
 *   - promoted_to_player_id      (int|null)
 *   - promoted_to_trial_case_id  (int|null)
 *   - created_at                 (string, mysql datetime; for joined window)
 *   - open_invite                (0/1)
 *   - open_confirm               (0/1)
 *   - open_outcome               (0/1)
 *   - open_offer                 (0/1)
 *   - done_invite                (0/1) — invite_to_test_training in 'completed'
 *   - done_confirm               (0/1) — confirm_test_training in 'completed'
 *   - done_outcome               (0/1) — record_test_training_outcome in 'completed'
 */
final class ProspectStageClassifier {

    /**
     * @param object $row           query row (see class docblock for required columns)
     * @param int    $joined_cutoff unix ts; promotions older than this fall off the funnel
     * @return ?string  one of: 'prospects', 'invited', 'test', 'trial', 'offer', 'joined'
     *                  null = excluded from the funnel (joined >90d ago)
     */
    public static function classify( object $row, int $joined_cutoff ): ?string {
        // Joined is a 90-day rolling window so a long-promoted player
        // doesn't sit in the funnel forever.
        if ( ! empty( $row->promoted_to_player_id ) ) {
            $created = strtotime( (string) ( $row->created_at ?? '' ) );
            return ( $created !== false && $created >= $joined_cutoff ) ? 'joined' : null;
        }
        // Open team-offer task wins over trial-group promotion: the
        // offer is the current focus, even if the prospect is still on
        // a trial-case row.
        if ( ! empty( $row->open_offer ) ) {
            return 'offer';
        }
        if ( ! empty( $row->promoted_to_trial_case_id ) ) {
            return 'trial';
        }
        // Test training reached: confirm is done (parent confirmed) OR
        // an outcome task exists in any state (open = waiting, done =
        // decision pending). The done_confirm clause covers the edge
        // case where the chain stopped after confirm (e.g. an outcome
        // task was completed and trial promotion was decided externally
        // before the next chain step). done_outcome covers the case
        // where the chain stopped after the test was recorded but no
        // trial/decline decision has been written yet.
        if ( ! empty( $row->open_outcome ) || ! empty( $row->done_outcome ) || ! empty( $row->done_confirm ) ) {
            return 'test';
        }
        // Invited reached: the invite was actually sent (= completed)
        // OR a parent-confirmation task is open (chain advanced past
        // invite). open_invite by itself stays Prospects — the email
        // is still in the HoD's inbox waiting to go out.
        if ( ! empty( $row->open_confirm ) || ! empty( $row->done_invite ) ) {
            return 'invited';
        }
        // Default: drafted but no outbound action yet. Covers (a) fresh
        // wizard entries before the chain dispatches, (b) prospects
        // with only an open invite task, (c) orphaned/abandoned chains
        // that operators didn't archive.
        return 'prospects';
    }
}
