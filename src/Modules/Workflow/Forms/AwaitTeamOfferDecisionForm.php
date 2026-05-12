<?php
namespace TT\Modules\Workflow\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * AwaitTeamOfferDecisionForm (#0081 child 4) — HoD records the parent +
 * player's response to a team-offer.
 *
 * Three radio choices: accepted / declined / no-response-mark-withdrawn.
 * On accept, the trial case decision flips to `admit` and the player's
 * status updates to `active`. On decline, the trial case decision flips
 * to `declined_offered_position` (terminal) and the prospect is
 * archived. On no-response, the trial case is archived without a
 * decision change — operator can revisit later.
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
        $player_id     = (int) ( $task['player_id']     ?? 0 );
        $prospect_id   = (int) ( $task['prospect_id']   ?? 0 );
        $actor         = (int) ( $task['assignee_user_id'] ?? get_current_user_id() );

        if ( $trial_case_id > 0 ) {
            $repo = new TrialCasesRepository();
            $patch = [
                'decision_made_at' => current_time( 'mysql', true ),
                'decision_made_by' => $actor,
                'decision_notes'   => $notes,
            ];
            if ( $outcome === 'accepted' ) {
                $patch['decision'] = TrialCasesRepository::DECISION_ADMIT;
                $patch['status']   = TrialCasesRepository::STATUS_DECIDED;
            } elseif ( $outcome === 'declined' ) {
                $patch['decision'] = TrialCasesRepository::DECISION_DECLINED_OFFERED_POSITION;
                $patch['status']   = TrialCasesRepository::STATUS_DECIDED;
            } else {
                // no_response → archive without decision change
                $patch['archived_at'] = current_time( 'mysql', true );
                $patch['archived_by'] = $actor;
            }
            $repo->update( $trial_case_id, $patch );
        }

        // v3.110.85 — close the docblock gap. Pre-v3.110.85 only the
        // trial-case row was updated; the docblock's promised
        // player.status and prospect-archival side-effects never ran.
        // Net effect: accepted prospects stayed at player.status='trial'
        // (so the v3.110.84 classifier left them in Trial group instead
        // of promoting to Joined), and declined / no_response prospects
        // stayed visible in the funnel forever.
        if ( $outcome === 'accepted' && $player_id > 0 ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'tt_players',
                [ 'status' => 'active' ],
                [ 'id' => $player_id, 'club_id' => CurrentClub::id() ]
            );
        } elseif ( $outcome === 'declined' && $prospect_id > 0 ) {
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
