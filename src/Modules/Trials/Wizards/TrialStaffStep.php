<?php
namespace TT\Modules\Trials\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Trials\Repositories\TrialTracksRepository;
use TT\Modules\Trials\Services\TrialCaseOpener;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\Components\StaffPickerComponent;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — who is watching, and the commit.
 *
 * Assignment is what `TrialCaseAccessPolicy` later gates staff input and
 * synthesis on, so it is worth asking for up front rather than leaving to
 * a second visit — an unassigned case is one nobody can write about.
 * It stays optional: a case opened in a hurry can have staff added on the
 * detail page afterwards.
 *
 * This step also shows what is about to be created. It is the last thing
 * before a real write, and a trial case flips a child's status, so the
 * coach should see the summary rather than trust three screens of memory.
 *
 * ## The commit
 *
 * Everything is written here and nothing before it (CLAUDE.md §6, model
 * C): a wizard abandoned at step two leaves no player row and no case.
 * The write goes through {@see TrialCaseOpener}, shared with the flat
 * form, so this is not a third write path — see the wizard class for why
 * that mattered enough to refactor first.
 */
final class TrialStaffStep implements WizardStepInterface {

    /** Slots offered, matching the flat form. */
    private const SLOTS = 3;

    public function slug(): string  { return 'staff'; }
    public function label(): string { return __( 'Staff', 'talenttrack' ); }

    public function render( array $state ): void {
        $this->renderSummary( $state );

        $chosen = (array) ( $state['staff'] ?? [] );

        echo '<fieldset class="tt-trial-staff-rows">';
        echo '<legend>' . esc_html__( 'Who is watching this trial?', 'talenttrack' ) . '</legend>';
        echo '<p class="tt-field-hint">' . esc_html__( 'Optional, and you can add more later. Only assigned staff can submit input on the case.', 'talenttrack' ) . '</p>';

        for ( $i = 0; $i < self::SLOTS; $i++ ) {
            $uid   = (int) ( $chosen[ $i ]['user_id'] ?? 0 );
            $label = (string) ( $chosen[ $i ]['role_label'] ?? '' );

            echo '<div class="tt-trial-staff-row">';
            echo StaffPickerComponent::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'name'        => 'staff_user_id[]',
                'label'       => sprintf(
                    /* translators: %d: slot number */
                    __( 'Staff slot %d', 'talenttrack' ),
                    $i + 1
                ),
                'required'    => false,
                'selected'    => $uid,
                'placeholder' => __( 'Type a name to search…', 'talenttrack' ),
            ] );
            echo '<input type="text" name="staff_role_label[]" class="tt-input"'
                . ' value="' . esc_attr( $label ) . '"'
                . ' placeholder="' . esc_attr__( 'Role label (optional)', 'talenttrack' ) . '">';
            echo '</div>';
        }

        echo '</fieldset>';
    }

    /**
     * What the coach is about to create, in the words they typed.
     *
     * @param array<string,mixed> $state
     */
    private function renderSummary( array $state ): void {
        $player_id = (int) ( $state['player_id'] ?? 0 );
        if ( $player_id > 0 ) {
            $row  = QueryHelpers::get_player( $player_id );
            $who  = $row ? QueryHelpers::player_display_name( $row ) : '#' . $player_id;
            $note = '';
        } else {
            $who  = trim( (string) ( $state['new_first'] ?? '' ) . ' ' . (string) ( $state['new_last'] ?? '' ) );
            $note = __( 'will be created as a new player', 'talenttrack' );
        }

        $track_name = '';
        $track_id   = (int) ( $state['track_id'] ?? 0 );
        if ( $track_id > 0 ) {
            $track      = ( new TrialTracksRepository() )->find( $track_id );
            $track_name = $track ? (string) $track->name : '';
        }

        echo '<div class="tt-field">';
        echo '<h3 class="tt-wizard-summary__head">' . esc_html__( 'About to open', 'talenttrack' ) . '</h3>';
        echo '<ul class="tt-wizard-summary">';

        echo '<li><strong>' . esc_html__( 'Player', 'talenttrack' ) . ':</strong> ' . esc_html( $who );
        if ( $note !== '' ) echo ' <em>(' . esc_html( $note ) . ')</em>';
        echo '</li>';

        if ( $track_name !== '' ) {
            echo '<li><strong>' . esc_html__( 'Track', 'talenttrack' ) . ':</strong> ' . esc_html( $track_name ) . '</li>';
        }

        echo '<li><strong>' . esc_html__( 'Dates', 'talenttrack' ) . ':</strong> '
            . esc_html( (string) ( $state['start_date'] ?? '' ) )
            . ' – '
            . esc_html( (string) ( $state['end_date'] ?? '' ) )
            . '</li>';

        echo '</ul>';
        echo '<p class="tt-field-hint">' . esc_html__( "Finishing opens the case and sets the player's status to trial. It also writes the trial to their journey, so the timeline starts on day one.", 'talenttrack' ) . '</p>';
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        $ids    = isset( $post['staff_user_id'] )    ? (array) $post['staff_user_id']    : [];
        $labels = isset( $post['staff_role_label'] ) ? (array) $post['staff_role_label'] : [];

        $staff = [];
        $seen  = [];
        foreach ( $ids as $i => $raw ) {
            $uid = absint( $raw );
            if ( $uid <= 0 ) continue;
            // Two slots naming the same person is a slip, not an intent to
            // assign them twice; the repository would take both rows.
            if ( isset( $seen[ $uid ] ) ) continue;
            $seen[ $uid ] = true;

            $staff[] = [
                'user_id'    => $uid,
                'role_label' => isset( $labels[ $i ] )
                    ? sanitize_text_field( wp_unslash( (string) $labels[ $i ] ) )
                    : '',
            ];
        }

        return [ 'staff' => $staff ];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $opener = new TrialCaseOpener();

        $player_id = (int) ( $state['player_id'] ?? 0 );

        // The player is created here, at the commit, not when the coach
        // typed the name — so backing out of the wizard leaves nothing
        // behind.
        if ( $player_id <= 0 ) {
            $created = $opener->createTrialPlayer(
                (string) ( $state['new_first'] ?? '' ),
                (string) ( $state['new_last'] ?? '' ),
                (string) ( $state['new_dob'] ?? '' )
            );
            if ( $created['id'] <= 0 ) {
                return new \WP_Error( 'player_create_failed', $created['error'] );
            }
            $player_id = $created['id'];
        }

        $case_id = $opener->open( [
            'player_id'  => $player_id,
            'track_id'   => (int) ( $state['track_id'] ?? 0 ),
            'start_date' => (string) ( $state['start_date'] ?? '' ),
            'end_date'   => (string) ( $state['end_date'] ?? '' ),
            'notes'      => (string) ( $state['notes'] ?? '' ),
            'created_by' => get_current_user_id(),
            'staff'      => (array) ( $state['staff'] ?? [] ),
        ] );

        if ( $case_id instanceof \WP_Error ) {
            return $case_id;
        }

        return [ 'redirect_url' => RecordLink::detailUrlFor( 'trial-case', (int) $case_id ) ];
    }
}
