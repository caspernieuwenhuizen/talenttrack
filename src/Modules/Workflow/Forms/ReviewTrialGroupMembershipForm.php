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
            $patch = [
                'decision_made_at' => current_time( 'mysql', true ),
                'decision_made_by' => (int) ( $task['assignee_user_id'] ?? get_current_user_id() ),
                'decision_notes'   => $rationale,
            ];
            if ( $decision === 'continue_in_trial_group' ) {
                $patch['decision']        = TrialCasesRepository::DECISION_CONTINUE_IN_TRIAL_GROUP;
                $patch['continued_until'] = gmdate( 'Y-m-d', strtotime( '+90 days' ) );
                $patch['status']          = TrialCasesRepository::STATUS_EXTENDED;
            } elseif ( $decision === 'offer_team_position' ) {
                $patch['decision'] = TrialCasesRepository::DECISION_OFFERED_TEAM_POSITION;
                // Status stays open — final disposition lands in
                // AwaitTeamOfferDecisionForm.
            } elseif ( $decision === 'decline_final' ) {
                $patch['decision']    = TrialCasesRepository::DECISION_DENY_FINAL;
                $patch['status']      = TrialCasesRepository::STATUS_DECIDED;
                $patch['archived_at'] = current_time( 'mysql', true );
                $patch['archived_by'] = (int) ( $task['assignee_user_id'] ?? get_current_user_id() );
            }
            $repo->update( $trial_case_id, $patch );
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
