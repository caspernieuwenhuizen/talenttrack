<?php
namespace TT\Modules\Workflow\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * ConfirmTestTrainingForm (#0081 child 2b) — three-button HoD form
 * recording the parent's response.
 *
 * The same response shape can also be written by the public REST
 * endpoint when the parent clicks the "yes I'll come" link in their
 * invitation email — the endpoint completes the task with
 * `outcome=confirmed` directly via `TaskEngine::complete()`,
 * bypassing this form's render() but exercising the same
 * `serializeResponse()` schema.
 */
class ConfirmTestTrainingForm implements FormInterface {

    public function render( array $task ): string {
        $existing = self::decodeResponse( $task );
        $disabled = self::completedAttr( $task );
        $prospect = self::prospectSummary( (int) ( $task['prospect_id'] ?? 0 ) );

        ob_start();
        ?>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">
            <?php if ( $prospect !== '' ) : ?>
                <p style="margin: 0 0 14px; font-weight: 600;">
                    <?php echo esc_html( sprintf( __( 'Prospect: %s', 'talenttrack' ), $prospect ) ); ?>
                </p>
            <?php endif; ?>

            <p style="margin: 0 0 14px;">
                <?php esc_html_e( 'Record the parent response to the invitation.', 'talenttrack' ); ?>
            </p>

            <p>
                <label>
                    <input type="radio" name="outcome" value="confirmed"
                           <?php checked( (string) ( $existing['outcome'] ?? '' ), 'confirmed' ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'Confirmed (parent agreed to attend)', 'talenttrack' ); ?>
                </label>
            </p>
            <p>
                <label>
                    <input type="radio" name="outcome" value="declined"
                           <?php checked( (string) ( $existing['outcome'] ?? '' ), 'declined' ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'Declined (parent withdrew)', 'talenttrack' ); ?>
                </label>
            </p>
            <p>
                <label>
                    <input type="radio" name="outcome" value="no_response"
                           <?php checked( (string) ( $existing['outcome'] ?? '' ), 'no_response' ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'No response — mark no-show', 'talenttrack' ); ?>
                </label>
            </p>

            <p style="margin: 16px 0 6px;">
                <label for="tt-ctt-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
            </p>
            <p>
                <textarea id="tt-ctt-notes" name="notes" rows="3" style="width:100%;"
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
        if ( ! in_array( $outcome, [ 'confirmed', 'declined', 'no_response' ], true ) ) {
            $errors['outcome'] = __( 'Pick one outcome.', 'talenttrack' );
        }
        return $errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        return [
            'outcome' => (string) ( $raw['outcome'] ?? '' ),
            'notes'   => sanitize_textarea_field( (string) ( $raw['notes'] ?? '' ) ),
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

    private static function prospectSummary( int $prospect_id ): string {
        if ( $prospect_id <= 0 ) return '';
        $repo = new ProspectsRepository();
        $row  = $repo->find( $prospect_id );
        if ( ! $row ) return '';
        return trim( ( $row->first_name ?? '' ) . ' ' . ( $row->last_name ?? '' ) );
    }
}
