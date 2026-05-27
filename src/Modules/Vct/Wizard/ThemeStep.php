<?php
namespace TT\Modules\Vct\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — Theme. Optional tactical theme picker.
 *
 * Reads the `vct_tactical_theme` lookup vocabulary (seeded in
 * migration 0124). Skipping = theme stays NULL → engine selects
 * theme-agnostic candidates.
 */
final class ThemeStep implements WizardStepInterface {

    public function slug(): string { return 'theme'; }
    public function label(): string { return __( 'Theme', 'talenttrack' ); }

    public function render( array $state ): void {
        $themes  = QueryHelpers::get_lookup_names( 'vct_tactical_theme' );
        $current = (string) ( $state['tactical_theme'] ?? '' );

        echo '<p>' . esc_html__( 'Pick a tactical theme, or skip for a balanced session.', 'talenttrack' ) . '</p>';
        echo '<label><span>' . esc_html__( 'Tactical theme', 'talenttrack' ) . '</span><select name="tactical_theme">';
        echo '<option value="">' . esc_html__( '— no specific theme —', 'talenttrack' ) . '</option>';
        foreach ( $themes as $name ) {
            $label = LookupTranslator::byTypeAndName( 'vct_tactical_theme', (string) $name );
            echo '<option value="' . esc_attr( (string) $name ) . '" ' . selected( $current, (string) $name, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';
    }

    public function validate( array $post, array $state ) {
        $theme = isset( $post['tactical_theme'] ) ? trim( (string) $post['tactical_theme'] ) : '';
        if ( $theme === '' ) return [ 'tactical_theme' => null ];

        $valid = QueryHelpers::get_lookup_names( 'vct_tactical_theme' );
        if ( ! in_array( $theme, $valid, true ) ) {
            return new \WP_Error( 'bad_theme', __( 'That tactical theme is not in the vocabulary.', 'talenttrack' ) );
        }
        return [ 'tactical_theme' => $theme ];
    }

    public function nextStep( array $state ): ?string { return 'duration'; }
    public function submit( array $state ) { return null; }
}
