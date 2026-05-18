<?php
namespace TT\Modules\Wizards\Player;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — Trial vs roster branching.
 *
 * The roster path collects the standard player fields and creates a
 * regular player record. The trial path collects minimal info and,
 * if the Trials module is active, creates an actual trial case
 * (delegating to TrialsRestController) instead of just setting
 * `status='trial'`.
 */
final class PathStep implements WizardStepInterface {

    public function slug(): string { return 'path'; }
    public function label(): string { return __( 'Type of player', 'talenttrack' ); }

    public function render( array $state ): void {
        $current = (string) ( $state['path'] ?? '' );
        echo '<p>' . esc_html__( 'Are you adding a player who is joining the academy, or someone coming in for a trial period?', 'talenttrack' ) . '</p>';
        echo '<fieldset>';
        echo '<legend>' . esc_html__( 'Choose one', 'talenttrack' ) . '</legend>';
        // v3.110.166 (#482) — radio labels gain `display:flex` +
        // `min-height:48px` so the whole row is a 48px tap target
        // (CLAUDE.md §2 floor). Pre-fix the labels were bare and the
        // tappable area was just the radio dot itself.
        echo '<label style="display:flex; align-items:center; gap:8px; min-height:48px; padding:8px 0;"><input type="radio" name="path" value="roster" ' . checked( $current === 'roster' || $current === '', true, false ) . '> <span><strong>' . esc_html__( 'Roster player', 'talenttrack' ) . '</strong> — ' . esc_html__( 'has been signed and is joining a team.', 'talenttrack' ) . '</span></label>';
        echo '<label style="display:flex; align-items:center; gap:8px; min-height:48px; padding:8px 0;"><input type="radio" name="path" value="trial" ' . checked( $current === 'trial', true, false ) . '> <span><strong>' . esc_html__( 'Trial player', 'talenttrack' ) . '</strong> — ' . esc_html__( 'is coming in for a 2 to 6 week look.', 'talenttrack' ) . '</span></label>';
        echo '</fieldset>';
    }

    public function validate( array $post, array $state ) {
        $path = isset( $post['path'] ) ? sanitize_key( (string) $post['path'] ) : '';
        if ( ! in_array( $path, [ 'roster', 'trial' ], true ) ) {
            return new \WP_Error( 'bad_path', __( 'Please choose roster or trial.', 'talenttrack' ) );
        }
        return [ 'path' => $path ];
    }

    public function nextStep( array $state ): ?string {
        return ( ( $state['path'] ?? '' ) === 'trial' ) ? 'trial-details' : 'roster-details';
    }

    public function submit( array $state ) { return null; }
}
