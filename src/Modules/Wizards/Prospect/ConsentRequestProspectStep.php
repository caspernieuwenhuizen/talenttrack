<?php
namespace TT\Modules\Wizards\Prospect;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Domain\ProspectRecord;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — which prospect was the request for?
 *
 * Offers only the prospects this user may log a request for. Skipped when
 * the wizard was opened from a prospect's record page.
 */
final class ConsentRequestProspectStep implements WizardStepInterface {

    public function slug(): string  { return 'prospect'; }
    public function label(): string { return __( 'Prospect', 'talenttrack' ); }

    /** @param array<string, mixed> $state */
    public function notApplicableFor( array $state ): bool {
        return (int) ( $state['prospect_id'] ?? 0 ) > 0;
    }

    public function render( array $state ): void {
        $current    = (int) ( $state['prospect_id'] ?? 0 );
        $candidates = ProspectRecord::consentCandidates( get_current_user_id() );

        if ( $candidates === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'There are no prospects you can log a consent request for.', 'talenttrack' ) . '</p>';
            echo '<input type="hidden" name="prospect_id" value="0" required />';
            return;
        }

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-consent-prospect">' . esc_html__( 'Which prospect is the request for?', 'talenttrack' ) . '</label>';
        echo '<select id="tt-consent-prospect" class="tt-input" name="prospect_id" required>';
        echo '<option value="0">' . esc_html__( '— Pick a prospect —', 'talenttrack' ) . '</option>';
        foreach ( $candidates as $id => $name ) {
            echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $current, $id, false ) . '>' . esc_html( $name ) . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        $id = isset( $post['prospect_id'] ) ? absint( $post['prospect_id'] ) : 0;
        if ( $id <= 0 || ! ProspectRecord::canLogConsent( get_current_user_id(), $id ) ) {
            return new \WP_Error( 'no_prospect', __( 'Please pick a prospect.', 'talenttrack' ) );
        }
        return [ 'prospect_id' => $id ];
    }

    /** Always names the next step, so the framework never submits from here. */
    public function nextStep( array $state ): string { return 'details'; }

    /**
     * Unreachable: only the final step submits.
     *
     * @return array<string,mixed>
     */
    public function submit( array $state ): array { return []; }
}
