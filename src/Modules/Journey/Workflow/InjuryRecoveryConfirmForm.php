<?php
namespace TT\Modules\Journey\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * InjuryRecoveryConfirmForm — three-option confirmation. The coach
 * picks one of:
 *
 *   on_track        — player will return as expected (no further action)
 *   extend_recovery — push the expected_return out by N days
 *   unsure          — flag for follow-up; medical team will weigh in
 *
 * On submit, an `extend_recovery` option also writes the new
 * expected_return through the InjuryRepository, but the form itself
 * doesn't know about that wiring — the workflow engine consumes the
 * response and the repository is updated by the form's serialize hook
 * via the journey module's domain code.
 */
class InjuryRecoveryConfirmForm implements FormInterface {

    public const OPTION_ON_TRACK = 'on_track';
    public const OPTION_EXTEND   = 'extend_recovery';
    public const OPTION_UNSURE   = 'unsure';

    public function render( array $task ): string {
        $existing = self::decodeResponse( $task );
        $disabled = self::completedAttr( $task );

        ob_start();
        ?>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">
            <p style="margin: 0 0 14px; color:#5b6e75;">
                <?php esc_html_e( 'Confirm whether this player is on track to return from injury.', 'talenttrack' ); ?>
            </p>

            <fieldset style="border:0; padding:0; margin:0;">
                <legend style="font-weight:600; margin-bottom:8px;"><?php esc_html_e( 'Status', 'talenttrack' ); ?></legend>
                <label style="display:block; padding:6px 0; min-height:32px;">
                    <input type="radio" name="recovery_status" value="<?php echo esc_attr( self::OPTION_ON_TRACK ); ?>"
                           <?php checked( ( $existing['recovery_status'] ?? '' ) === self::OPTION_ON_TRACK ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'On track — back as expected', 'talenttrack' ); ?>
                </label>
                <label style="display:block; padding:6px 0; min-height:32px;">
                    <input type="radio" name="recovery_status" value="<?php echo esc_attr( self::OPTION_EXTEND ); ?>"
                           <?php checked( ( $existing['recovery_status'] ?? '' ) === self::OPTION_EXTEND ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'Extend — needs more time', 'talenttrack' ); ?>
                </label>
                <label style="display:block; padding:6px 0; min-height:32px;">
                    <input type="radio" name="recovery_status" value="<?php echo esc_attr( self::OPTION_UNSURE ); ?>"
                           <?php checked( ( $existing['recovery_status'] ?? '' ) === self::OPTION_UNSURE ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'Unsure — flag for medical review', 'talenttrack' ); ?>
                </label>
            </fieldset>

            <p style="margin: 14px 0 6px;">
                <label for="tt-recovery-notes" style="font-weight: 600;"><?php esc_html_e( 'Notes (optional)', 'talenttrack' ); ?></label>
            </p>
            <p>
                <textarea id="tt-recovery-notes" name="notes" rows="3" style="width: 100%;"
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
        $allowed = [ self::OPTION_ON_TRACK, self::OPTION_EXTEND, self::OPTION_UNSURE ];
        $status  = (string) ( $raw['recovery_status'] ?? '' );
        if ( ! in_array( $status, $allowed, true ) ) {
            $errors['recovery_status'] = __( 'Pick one of: on track / extend / unsure.', 'talenttrack' );
        }
        return $errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        return [
            'recovery_status' => sanitize_key( (string) ( $raw['recovery_status'] ?? '' ) ),
            'notes'           => sanitize_textarea_field( (string) ( $raw['notes'] ?? '' ) ),
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
