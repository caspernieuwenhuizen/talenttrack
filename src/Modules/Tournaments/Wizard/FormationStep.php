<?php
namespace TT\Modules\Tournaments\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — Default formation. Radio buttons sourced from the
 * `tournament_formation` lookup. Per-match override is possible
 * later (matches step + post-creation edit on the planner detail).
 */
final class FormationStep implements WizardStepInterface {

    public function slug(): string { return 'formation'; }
    public function label(): string { return __( 'Formation', 'talenttrack' ); }

    public function render( array $state ): void {
        $current = (string) ( $state['default_formation'] ?? '' );
        $formations = QueryHelpers::get_lookup_names( 'tournament_formation' );

        echo '<p>' . esc_html__( 'Pick the default formation for the tournament. You can override it per match later.', 'talenttrack' ) . '</p>';
        echo '<div class="tt-wizard-radio-group">';
        echo '<label class="tt-wizard-radio"><input type="radio" name="default_formation" value="" ' . checked( $current, '', false ) . '> <span>' . esc_html__( '(no default — pick per match)', 'talenttrack' ) . '</span></label>';
        foreach ( $formations as $f ) {
            $label = (string) $f;
            $sel   = checked( $current, $label, false );
            echo '<label class="tt-wizard-radio"><input type="radio" name="default_formation" value="' . esc_attr( $label ) . '" ' . $sel . '> <span>' . esc_html( $label ) . '</span></label>';
        }
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        $f = isset( $post['default_formation'] ) ? sanitize_text_field( wp_unslash( (string) $post['default_formation'] ) ) : '';
        return [ 'default_formation' => $f ];
    }

    public function nextStep( array $state ): ?string { return 'squad'; }
    public function submit( array $state ) { return null; }
}
