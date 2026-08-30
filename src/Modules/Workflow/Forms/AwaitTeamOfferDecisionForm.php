<?php
namespace TT\Modules\Workflow\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * AwaitTeamOfferDecisionForm (#0081 child 4) — HoD records the parent +
 * player's response to a team-offer.
 *
 * Three radio choices: accepted / declined / no-response-mark-withdrawn.
 * Accept and decline are recorded through
 * `TrialCasesRepository::recordDecision()` — `admit` and
 * `declined_offered_position` respectively — so the decision hook fires
 * and both the player's timeline and the player's status follow from it
 * (#3138). On no-response the case is archived without a decision change,
 * announcing nothing, because nothing was decided; the operator can
 * revisit later. The prospect is archived here on decline and no-response.
 */
class AwaitTeamOfferDecisionForm implements FormInterface {

    public function render( array $task ): string {
        $existing = self::decodeResponse( $task );
        $disabled = self::completedAttr( $task );

        ob_start();
        ?>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">
            <p style="margin: 0 0 14px;">
                <?php esc_html_e( 'Record the parent + player response to the team-offer.', 'talenttrack' ); ?>
            </p>

            <p>
                <label>
                    <input type="radio" name="outcome" value="accepted"
                           <?php checked( (string) ( $existing['outcome'] ?? '' ), 'accepted' ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'Accepted — promote to academy team', 'talenttrack' ); ?>
                </label>
            </p>
            <p>
                <label>
                    <input type="radio" name="outcome" value="declined"
                           <?php checked( (string) ( $existing['outcome'] ?? '' ), 'declined' ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'Declined — family chose not to take the offer', 'talenttrack' ); ?>
                </label>
            </p>
            <p>
                <label>
                    <input type="radio" name="outcome" value="no_response"
                           <?php checked( (string) ( $existing['outcome'] ?? '' ), 'no_response' ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'No response — close the case for now', 'talenttrack' ); ?>
                </label>
            </p>

            <p style="margin: 16px 0 6px;">
                <label for="tt-aod-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
            </p>
            <p>
                <textarea id="tt-aod-notes" name="notes" rows="3" style="width:100%;"
                          <?php echo $disabled; ?>><?php
                    echo esc_textarea( (string) ( $existing['notes'] ?? '' ) );
                ?></textarea>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function validate( array $raw, array $task ): array {
        $errors = [];
        $outcome = (string) ( $raw['outcome'] ?? '' );
        if ( ! in_array( $outcome, [ 'accepted', 'declined', 'no_response' ], true ) ) {
            $errors['outcome'] = __( 'Pick one outcome.', 'talenttrack' );
        }
        return $errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        $outcome = (string) ( $raw['outcome'] ?? '' );
        $notes   = sanitize_textarea_field( (string) ( $raw['notes'] ?? '' ) );

        // Trial-case side-effect. The trial_case_id was carried through
        // the chain by InviteToTestTraining → ConfirmTestTraining →
        // RecordTestTrainingOutcome → ReviewTrialGroupMembership → here,
        // and stamped onto the task's trial_case_id column when the
        // chain spawned this template.
        $trial_case_id = (int) ( $task['trial_case_id'] ?? 0 );
        $prospect_id   = (int) ( $task['prospect_id']   ?? 0 );
        $actor         = (int) ( $task['assignee_user_id'] ?? get_current_user_id() );

        if ( $trial_case_id > 0 ) {
            $repo = new TrialCasesRepository();

            // #3138 — through `recordDecision()`, not `update()`. Both
            // decision branches used to write the case row and announce
            // nothing, so a trial that ended here never closed on the
            // player's timeline: the declined branch left a trial that
            // started and never finished, and the accepted branch produced
            // no "Signed after trial" either.
            //
            // The decision hook now carries both, which also means the
            // player's status is written by one owner —
            // `TrialDecisionPlayerStatusSubscriber` (#3116), whose mapping
            // already covers `admit` -> active and
            // `declined_offered_position` -> inactive. The manual
            // `tt_players` write that used to live below is gone with it;
            // two writers with two opinions about the same column is the
            // thing that gap invites.
            if ( $outcome === 'accepted' ) {
                $repo->recordDecision( $trial_case_id, TrialCasesRepository::DECISION_ADMIT, $actor, $notes );
            } elseif ( $outcome === 'declined' ) {
                $repo->recordDecision( $trial_case_id, TrialCasesRepository::DECISION_DECLINED_OFFERED_POSITION, $actor, $notes );
            } else {
                // no_response → archive without decision change. Nothing was
                // decided, so nothing is announced.
                $repo->update( $trial_case_id, [
                    'decision_made_at' => current_time( 'mysql', true ),
                    'decision_made_by' => $actor,
                    'decision_notes'   => $notes,
                    'archived_at'      => current_time( 'mysql', true ),
                    'archived_by'      => $actor,
                ] );
            }
        }

        // v3.110.85 — close the docblock gap. Pre-v3.110.85 only the
        // trial-case row was updated; the docblock's promised
        // player.status and prospect-archival side-effects never ran, so
        // declined / no_response prospects stayed visible in the funnel
        // forever. The player-status half of that moved to the decision
        // subscriber in #3138; the prospect archival stays here.
        if ( $outcome === 'declined' && $prospect_id > 0 ) {
            ( new ProspectsRepository() )->archive(
                $prospect_id,
                ProspectsRepository::ARCHIVE_REASON_PARENT_WITHDREW,
                $actor
            );
        } elseif ( $outcome === 'no_response' && $prospect_id > 0 ) {
            // Operator's UX label is "No response — close the case for
            // now". Mirror ConfirmTestTrainingTemplate::onComplete's
            // no_response treatment: archive the prospect with NO_SHOW
            // so it drops off the funnel like other no-show prospects.
            ( new ProspectsRepository() )->archive(
                $prospect_id,
                ProspectsRepository::ARCHIVE_REASON_NO_SHOW,
                $actor
            );
        }

        return [
            'outcome'       => $outcome,
            'notes'         => $notes,
            'trial_case_id' => $trial_case_id,
        ];
    }

    /** @param array<string,mixed> $task */
    private static function decodeResponse( array $task ): array {
        $raw = (string) ( $task['response_json'] ?? '' );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /** @param array<string,mixed> $task */
    private static function completedAttr( array $task ): string {
        return ( (string) ( $task['status'] ?? '' ) ) === 'completed' ? 'disabled' : '';
    }
}
