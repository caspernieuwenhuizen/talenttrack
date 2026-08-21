<?php
namespace TT\Modules\Training\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — Theme (#2497).
 *
 * What the session works on. Each option carries how many exercises the
 * library can offer for it, because a theme with nothing behind it
 * produces a thin session and a coach deserves to know that before
 * picking rather than after.
 */
final class ThemeStep implements WizardStepInterface {

    public function slug(): string { return 'theme'; }

    public function label(): string { return __( 'Theme', 'talenttrack' ); }

    public function render( array $state ): void {
        $selected = (string) ( $state['tactical_theme'] ?? '' );
        $counts   = WizardContext::candidateCounts();

        echo '<p>' . esc_html__( 'What is this training about?', 'talenttrack' ) . '</p>';

        if ( ! WizardContext::hasMacroBlocks() ) {
            echo '<p class="description">'
                . esc_html__( 'Your academy has no periodisation calendar set up, so there is no suggested theme for this week. Pick one yourself — or set the calendar up under Configuration to get a suggestion here.', 'talenttrack' )
                . '</p>';
        }

        echo '<label><span>' . esc_html__( 'Theme', 'talenttrack' ) . '</span><select name="tactical_theme">';
        echo '<option value="">' . esc_html__( '— no particular theme —', 'talenttrack' ) . '</option>';

        foreach ( WizardContext::themeOptions() as $key => $label ) {
            $available = (int) ( $counts[ $key ] ?? 0 );

            $suffix = $available > 0
                ? sprintf(
                    /* translators: %d is how many exercises the library has for this theme. */
                    _n( '%d exercise', '%d exercises', $available, 'talenttrack' ),
                    $available
                )
                : __( 'nothing in the library yet', 'talenttrack' );

            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr( $key ),
                selected( $selected, $key, false ),
                esc_html( $label . ' — ' . $suffix )
            );
        }
        echo '</select></label>';

        echo '<p class="description">'
            . esc_html__( 'The theme decides which exercises are considered. Leave it open and the generator draws from the whole library.', 'talenttrack' )
            . '</p>';
    }

    public function validate( array $post, array $state ) {
        $theme = isset( $post['tactical_theme'] ) ? trim( (string) $post['tactical_theme'] ) : '';

        if ( $theme !== '' && ! array_key_exists( $theme, WizardContext::themeOptions() ) ) {
            return new \WP_Error( 'bad_theme', __( 'That is not a theme we know.', 'talenttrack' ) );
        }

        return [ 'tactical_theme' => $theme ];
    }

    public function nextStep( array $state ): ?string { return 'shape'; }

    public function submit( array $state ) { return null; }
}
