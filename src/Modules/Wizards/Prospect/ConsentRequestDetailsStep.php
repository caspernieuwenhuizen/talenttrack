<?php
namespace TT\Modules\Wizards\Prospect;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Domain\ConsentRequestDate;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — who was asked, and when.
 *
 * The person asked is the child's own club or its youth coordinator,
 * never the family: the log records the route the request took, not the
 * people at the end of it. The date is today or earlier (#4267).
 */
final class ConsentRequestDetailsStep implements WizardStepInterface {

    public function slug(): string  { return 'details'; }
    public function label(): string { return __( 'Request', 'talenttrack' ); }

    public function render( array $state ): void {
        $today    = current_time( 'Y-m-d' );
        $asked_at = (string) ( $state['asked_at'] ?? $today );
        $asked_of = (string) ( $state['asked_of'] ?? '' );
        $notes    = (string) ( $state['notes'] ?? '' );

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-consent-asked-at">' . esc_html__( 'Date asked', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="tt-consent-asked-at" class="tt-input" name="asked_at" required max="' . esc_attr( $today ) . '" value="' . esc_attr( $asked_at ) . '" />';
        echo '</div>';

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-consent-asked-of">' . esc_html__( 'Club or coordinator asked', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-consent-asked-of" class="tt-input" name="asked_of" required autocomplete="organization" value="' . esc_attr( $asked_of ) . '" />';
        echo '<p class="tt-field-hint">' . esc_html__( 'The club or youth coordinator who passes the request on. Not the family.', 'talenttrack' ) . '</p>';
        echo '</div>';

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-consent-notes">' . esc_html__( 'Notes', 'talenttrack' ) . '</label>';
        echo '<textarea id="tt-consent-notes" class="tt-input" name="notes" rows="3">' . esc_textarea( $notes ) . '</textarea>';
        echo '<p class="tt-field-hint">' . esc_html__( 'Optional. No family contact details here.', 'talenttrack' ) . '</p>';
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        $asked_at = isset( $post['asked_at'] ) ? trim( sanitize_text_field( (string) $post['asked_at'] ) ) : '';
        $error    = ConsentRequestDate::validate( $asked_at );
        if ( $error !== null ) return $error;

        $asked_of = isset( $post['asked_of'] ) ? trim( sanitize_text_field( (string) $post['asked_of'] ) ) : '';
        if ( $asked_of === '' ) {
            return new \WP_Error( 'missing_asked_of', __( 'Name the club or coordinator that was asked.', 'talenttrack' ) );
        }

        return [
            'asked_at' => $asked_at,
            'asked_of' => $asked_of,
            'notes'    => isset( $post['notes'] ) ? sanitize_textarea_field( (string) $post['notes'] ) : '',
        ];
    }

    public function nextStep( array $state ): string { return 'confirm'; }

    /**
     * Unreachable: only the final step submits.
     *
     * @return array<string,mixed>
     */
    public function submit( array $state ): array { return []; }
}
