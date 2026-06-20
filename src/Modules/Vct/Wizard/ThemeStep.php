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

        // #1518 — one-line "what does this theme focus on" guidance, so
        // a coach who isn't sure which to pick gets a plain-language cue.
        echo '<ul class="tt-vct-theme-hints" style="margin:12px 0 0;padding-left:18px;font-size:13px;color:#555;line-height:1.5;">';
        foreach ( $themes as $name ) {
            $hint = self::themeHint( (string) $name );
            if ( $hint === '' ) continue;
            $label = LookupTranslator::byTypeAndName( 'vct_tactical_theme', (string) $name );
            echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $hint ) . '</li>';
        }
        echo '</ul>';
    }

    /**
     * One-line coaching focus for a tactical theme, keyed by the seeded
     * `vct_tactical_theme` lookup name. Returns '' for unknown names so
     * a future vocabulary edit degrades quietly (no hint shown).
     */
    private static function themeHint( string $name ): string {
        switch ( $name ) {
            case 'build_up':    return __( 'Playing out from the back with controlled passing.', 'talenttrack' );
            case 'pressing':    return __( 'Winning the ball back quickly and high up the pitch.', 'talenttrack' );
            case 'transition':  return __( 'Fast switches between attack and defence the moment possession changes.', 'talenttrack' );
            case 'counter':     return __( 'Striking quickly into space after a turnover.', 'talenttrack' );
            case 'defending':   return __( 'Organising the press, cover, and one-on-one defending.', 'talenttrack' );
            case 'finishing':   return __( 'Creating and converting chances in front of goal.', 'talenttrack' );
            case 'set_pieces':  return __( 'Corners, free-kicks, and throw-ins — attacking and defending.', 'talenttrack' );
            case '1v1_duels':   return __( 'Beating and stopping an opponent in direct duels.', 'talenttrack' );
            case 'possession':  return __( 'Ball control, short passing, and keeping the ball as a team.', 'talenttrack' );
            case 'mixed':       return __( 'A balanced session touching several themes.', 'talenttrack' );
        }
        return '';
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
