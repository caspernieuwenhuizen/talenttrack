<?php
namespace TT\Modules\Trials\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\PlayerSearchPickerComponent;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — who is trialling?
 *
 * A search picker rather than the team → player cascade the injury and
 * goal wizards use, because a trialist usually belongs to no team yet.
 * The cascade would show an empty list for exactly the players this
 * wizard exists to record.
 *
 * The inline-create branch is the common case in practice: somebody the
 * academy has never entered before is coming to train on Tuesday. It
 * collects the three fields the canonical player create needs and nothing
 * more — the rest of the profile gets filled in later, by whoever has the
 * paperwork.
 *
 * Nothing is written here. The player is created in the final step along
 * with the case, so abandoning the wizard halfway leaves no orphan player
 * behind (CLAUDE.md §6, model C).
 */
final class TrialPlayerStep implements WizardStepInterface {

    public function slug(): string  { return 'player'; }
    public function label(): string { return __( 'Player', 'talenttrack' ); }

    public function render( array $state ): void {
        $existing = (int) ( $state['player_id'] ?? 0 );
        $first    = (string) ( $state['new_first'] ?? '' );
        $last     = (string) ( $state['new_last'] ?? '' );
        $dob      = (string) ( $state['new_dob'] ?? '' );

        echo '<div class="tt-field">';
        echo PlayerSearchPickerComponent::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            'name'     => 'player_id',
            'label'    => __( 'Existing player', 'talenttrack' ),
            'required' => false,
            'selected' => $existing,
            'user_id'  => get_current_user_id(),
            'is_admin' => current_user_can( 'tt_edit_settings' ),
        ] );
        echo '</div>';

        // Open when the coach has already started typing a new player, so
        // going back a step does not hide what they entered.
        $open = ( $first !== '' || $last !== '' || $dob !== '' ) ? ' open' : '';

        echo '<details class="tt-trial-inline-create"' . esc_attr( $open ) . '>';
        echo '<summary>' . esc_html__( 'Or create a new player here', 'talenttrack' ) . '</summary>';
        echo '<p class="tt-field-hint">' . esc_html__( 'First name, last name and date of birth are enough to start. The player is created with status "trial" when you finish the wizard, not before.', 'talenttrack' ) . '</p>';

        echo '<div class="tt-field"><label class="tt-field-label" for="tt-trialw-first">' . esc_html__( 'First name', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-trialw-first" name="new_first" class="tt-input" autocomplete="given-name" value="' . esc_attr( $first ) . '"></div>';

        echo '<div class="tt-field"><label class="tt-field-label" for="tt-trialw-last">' . esc_html__( 'Last name', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-trialw-last" name="new_last" class="tt-input" autocomplete="family-name" value="' . esc_attr( $last ) . '"></div>';

        echo '<div class="tt-field"><label class="tt-field-label" for="tt-trialw-dob">' . esc_html__( 'Date of birth', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="tt-trialw-dob" name="new_dob" class="tt-input" value="' . esc_attr( $dob ) . '"></div>';

        echo '</details>';
    }

    public function validate( array $post, array $state ) {
        $player_id = isset( $post['player_id'] ) ? absint( $post['player_id'] ) : 0;
        $first     = isset( $post['new_first'] ) ? sanitize_text_field( wp_unslash( (string) $post['new_first'] ) ) : '';
        $last      = isset( $post['new_last'] )  ? sanitize_text_field( wp_unslash( (string) $post['new_last'] ) )  : '';
        $dob       = isset( $post['new_dob'] )   ? sanitize_text_field( wp_unslash( (string) $post['new_dob'] ) )   : '';

        $has_new = ( $first !== '' || $last !== '' || $dob !== '' );

        // Picking somebody AND typing somebody else is ambiguous, and
        // guessing which one they meant is how the wrong child ends up on
        // a trial case.
        if ( $player_id > 0 && $has_new ) {
            return new \WP_Error(
                'ambiguous_player',
                __( 'Pick an existing player or fill in a new one — not both.', 'talenttrack' )
            );
        }

        if ( $player_id > 0 ) {
            // Same club check the opener repeats before writing. Failing
            // here means the coach is told on the step where they chose,
            // rather than three steps later.
            $row = QueryHelpers::get_player( $player_id );
            if ( ! $row ) {
                return new \WP_Error( 'unknown_player', __( 'Player not found in your club.', 'talenttrack' ) );
            }
            return [
                'player_id' => $player_id,
                'new_first' => '',
                'new_last'  => '',
                'new_dob'   => '',
            ];
        }

        if ( $has_new ) {
            if ( $first === '' || $last === '' || $dob === '' ) {
                return new \WP_Error(
                    'incomplete_player',
                    __( 'A new player needs a first name, a last name and a date of birth.', 'talenttrack' )
                );
            }
            return [
                'player_id' => 0,
                'new_first' => $first,
                'new_last'  => $last,
                'new_dob'   => $dob,
            ];
        }

        return new \WP_Error(
            'no_player',
            __( 'Pick an existing player, or fill in a new one.', 'talenttrack' )
        );
    }

    public function nextStep( array $state ): ?string { return 'details'; }

    /** Not a final step; the framework only calls this when nextStep() is null. */
    public function submit( array $state ) { return []; }
}
