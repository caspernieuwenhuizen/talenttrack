<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ProspectStageClassifier (v3.110.81, refined in v3.110.84) — single
 * source of truth for the mapping from a prospect's workflow state to
 * its onboarding-pipeline stage.
 *
 * Stages are reached states, NOT currently-assigned work. Pilot feedback
 * surfaced the original bug:
 *
 *   "the prospect is a prospect and not an invited player until the
 *    email is actually send out, that means the task to do so is
 *    completed."
 *
 * The v3.110.81 fix expressed each transition as a milestone (typically
 * the COMPLETION of a chain task), not as the existence of an open task.
 * v3.110.84 then fixed a follow-on bug: `admit_to_trial` (the test-
 * outcome path that promotes the prospect into a trial group) sets BOTH
 * `promoted_to_player_id` AND `promoted_to_trial_case_id` on the
 * prospect, with a fresh `tt_players` row at status='trial'. The
 * original rule put `promoted_to_player_id` first, which classified
 * trial-group prospects as **Joined** — wrong. Joined now requires
 * the player to have graduated past `status='trial'` (= the academy
 * accepted them after trial).
 *
 *     log_prospect open                                    → Prospects
 *     invite_to_test_training open                         → Prospects
 *     invite_to_test_training completed                    → Invited
 *     confirm_test_training open                           → Invited
 *     confirm_test_training completed                      → Test training
 *     record_test_training_outcome open                    → Test training
 *     record_test_training_outcome done                    → Test training
 *     promoted_to_trial_case_id + player.status='trial'    → Trial group
 *     await_team_offer_decision open                       → Team offer
 *     promoted_to_player_id + player.status != 'trial'     → Joined
 *
 * Mutually exclusive — returns exactly one of
 * prospects / invited / test / trial / offer / joined, or null when the
 * prospect should not appear (joined > 90 days ago — they belong to
 * the players surface now, not the funnel).
 *
 * The query row passed in must carry these properties:
 *
 *   - promoted_to_player_id      (int|null)
 *   - promoted_to_trial_case_id  (int|null)
 *   - player_status              (string|null) — `tt_players.status` of
 *                                  the promoted player; LEFT JOIN'd via
 *                                  `tt_players ON pl.id = p.promoted_to_player_id`.
 *                                  Empty/null when no player record exists.
 *                                  v3.110.84 added — required to tell
 *                                  Trial group from Joined when the
 *                                  prospect has been admitted to trial.
 *   - created_at                 (string, mysql datetime; for joined window)
 *   - open_invite                (0/1 or task id; both work with !empty)
 *   - open_confirm               (0/1 or task id)
 *   - open_outcome               (0/1 or task id)
 *   - open_offer                 (0/1 or task id)
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
        $player_id     = (int) ( $row->promoted_to_player_id     ?? 0 );
        $trial_case_id = (int) ( $row->promoted_to_trial_case_id ?? 0 );
        $player_status = (string) ( $row->player_status ?? '' );

        // Joined — the player record exists AND has graduated past
        // `status='trial'`. The trial-admit path creates the player at
        // status='trial' so that condition alone is not enough; we need
        // to see the academy upgrade it before calling this prospect
        // Joined. (See AwaitTeamOfferDecisionForm — accepted offers
        // currently update the trial-case decision but not the player
        // status; that's a known follow-up. Until it's wired, "Joined"
        // is reached only via the manual player-status flip in the
        // players UI, which is consistent with current operator
        // workflow.)
        $graduated = $player_id > 0
            && $player_status !== ''
            && $player_status !== 'trial';
        if ( $graduated ) {
            $created = strtotime( (string) ( $row->created_at ?? '' ) );
            return ( $created !== false && $created >= $joined_cutoff ) ? 'joined' : null;
        }

        // Open team-offer task wins over trial-group classification:
        // the offer is the current focus even though the trial case is
        // still on the prospect.
        if ( ! empty( $row->open_offer ) ) {
            return 'offer';
        }

        // Trial group — the prospect was admitted via `admit_to_trial`,
        // a `tt_trial_cases` row exists, and the player is still at
        // `status='trial'` (or no player row exists yet, edge case).
        if ( $trial_case_id > 0 ) {
            return 'trial';
        }

        // Defensive fallback: if a player_id exists but player_status
        // came back empty (e.g. the player row was deleted, or the
        // LEFT JOIN didn't match), still classify as Joined under the
        // 90-day window so the prospect doesn't silently disappear.
        if ( $player_id > 0 && $player_status === '' ) {
            $created = strtotime( (string) ( $row->created_at ?? '' ) );
            return ( $created !== false && $created >= $joined_cutoff ) ? 'joined' : null;
        }

        // Test training reached: confirm is done (parent confirmed) OR
        // an outcome task exists in any state. done_confirm covers the
        // edge case where the chain stopped after confirm; done_outcome
        // covers the case where the test was recorded but no
        // trial/decline decision has been written yet.
        if ( ! empty( $row->open_outcome ) || ! empty( $row->done_outcome ) || ! empty( $row->done_confirm ) ) {
            return 'test';
        }

        // Invited reached: the invite was actually sent (= completed)
        // OR a parent-confirmation task is open. open_invite by itself
        // stays Prospects — the email is still in the HoD's inbox
        // waiting to go out.
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
