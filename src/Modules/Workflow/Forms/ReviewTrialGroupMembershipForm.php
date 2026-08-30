<?php
namespace TT\Modules\Workflow\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * ReviewTrialGroupMembershipForm (#0081 child 2b) — quarterly HoD
 * review of a trial-case in `continue_in_trial_group` state.
 *
 * Captures the HoD's decision in three options + a free-text rationale.
 * Side-effects: trial-case decision update + `continued_until` bump
 * (for `continue_in_trial_group` only). Chain spawns happen via the
 * template's `chainSteps()`.
 */
class ReviewTrialGroupMembershipForm implements FormInterface {

    public function render( array $task ): string {
        $existing = self::decodeResponse( $task );
        $disabled = self::completedAttr( $task );

        ob_start();
        ?>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">
            <p style="margin: 0 0 14px;">
                <?php esc_html_e( 'Review the trial-group player and decide the next step.', 'talenttrack' ); ?>
            </p>

            <p>
                <label>
                    <input type="radio" name="decision" value="offer_team_position"
                           <?php checked( (string) ( $existing['decision'] ?? '' ), 'offer_team_position' ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'Offer a team position', 'talenttrack' ); ?>
                </label>
            </p>
            <p>
                <label>
                    <input type="radio" name="decision" value="continue_in_trial_group"
                           <?php checked( (string) ( $existing['decision'] ?? '' ), 'continue_in_trial_group' ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'Continue in trial group (re-review in 90 days)', 'talenttrack' ); ?>
                </label>
            </p>
            <p>
                <label>
                    <input type="radio" name="decision" value="decline_final"
                           <?php checked( (string) ( $existing['decision'] ?? '' ), 'decline_final' ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'Decline (final)', 'talenttrack' ); ?>
                </label>
            </p>

            <p style="margin: 16px 0 6px;">
                <label for="tt-rtg-rationale"><?php esc_html_e( 'Rationale', 'talenttrack' ); ?></label>
            </p>
            <p>
                <textarea id="tt-rtg-rationale" name="rationale" rows="4" style="width:100%;"
                          <?php echo $disabled; ?>><?php
                    echo esc_textarea( (string) ( $existing['rationale'] ?? '' ) );
                ?></textarea>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function validate( array $raw, array $task ): array {
        $errors = [];
        $decision = (string) ( $raw['decision'] ?? '' );
        if ( ! in_array( $decision, [ 'offer_team_position', 'continue_in_trial_group', 'decline_final' ], true ) ) {
            $errors['decision'] = __( 'Pick one decision.', 'talenttrack' );
        }
        return $errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        $decision  = (string) ( $raw['decision'] ?? '' );
        $rationale = sanitize_textarea_field( (string) ( $raw['rationale'] ?? '' ) );

        $trial_case_id = (int) ( $task['trial_case_id'] ?? 0 );
        if ( $trial_case_id > 0 ) {
            $repo  = new TrialCasesRepository();
            $actor = (int) ( $task['assignee_user_id'] ?? get_current_user_id() );

            // #3138 — all three go through `recordDecision()` rather than
            // `update()`, so the decision is announced once from the one
            // write path. The case status each decision settles on is the
            // repository's business now: `continue_in_trial_group` extends,
            // `offered_team_position` leaves the case open because the
            // final disposition lands in `AwaitTeamOfferDecisionForm`, and
            // a final decline decides it.
            //
            // Only the final decline reaches the timeline. The other two
            // are announced but produce no journey entry, because
            // `continue_in_trial_group` means the trial is still running
            // and `offered_team_position` is mid-conversation — see
            // `TrialCaseDecision::TERMINAL`.
            if ( $decision === 'continue_in_trial_group' ) {
                $repo->recordDecision(
                    $trial_case_id, TrialCasesRepository::DECISION_CONTINUE_IN_TRIAL_GROUP, $actor, $rationale,
                    null, null,
                    [ 'continued_until' => gmdate( 'Y-m-d', strtotime( '+90 days' ) ?: time() ) ]
                );
            } elseif ( $decision === 'offer_team_position' ) {
                $repo->recordDecision(
                    $trial_case_id, TrialCasesRepository::DECISION_OFFERED_TEAM_POSITION, $actor, $rationale
                );
            } elseif ( $decision === 'decline_final' ) {
                $repo->recordDecision(
                    $trial_case_id, TrialCasesRepository::DECISION_DENY_FINAL, $actor, $rationale,
                    null, null,
                    [
                        'archived_at' => current_time( 'mysql', true ),
                        'archived_by' => $actor,
                    ]
                );
            }
        }

        return [
            'decision'      => $decision,
            'rationale'     => $rationale,
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
