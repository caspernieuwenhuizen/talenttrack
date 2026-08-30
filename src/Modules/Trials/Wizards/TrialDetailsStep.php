<?php
namespace TT\Modules\Trials\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Trials\Repositories\TrialTracksRepository;
use TT\Shared\Frontend\Components\DateInputComponent;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — which track, and for how long.
 *
 * The track carries a default duration, so picking one proposes an end
 * date rather than making the coach count weeks. The proposal is only a
 * default: a trial that has already been agreed for a specific fortnight
 * is typed over it.
 */
final class TrialDetailsStep implements WizardStepInterface {

    public function slug(): string  { return 'details'; }
    public function label(): string { return __( 'Trial', 'talenttrack' ); }

    public function render( array $state ): void {
        // `false` — archived tracks are not offered for a new case, the
        // same call the flat create form makes.
        $tracks = ( new TrialTracksRepository() )->listAll( false );

        if ( ! $tracks ) {
            echo '<p class="tt-notice">' . esc_html__( 'No trial tracks are set up yet. An administrator needs to add at least one before a case can be opened.', 'talenttrack' ) . '</p>';
            return;
        }

        $current_track = (int) ( $state['track_id'] ?? 0 );
        $first_track   = (array) $tracks[0];
        $default_days  = (int) ( $first_track['default_duration_days'] ?? 28 );

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-trialw-track">' . esc_html__( 'Track', 'talenttrack' ) . '</label>';
        echo '<select id="tt-trialw-track" class="tt-input" name="track_id" required>';
        foreach ( $tracks as $t ) {
            // The repository returns plain `object` rows, so read them as
            // an array rather than reaching for properties PHPStan cannot
            // see on that type.
            $row  = (array) $t;
            $tid  = (int) ( $row['id'] ?? 0 );
            $days = (int) ( $row['default_duration_days'] ?? 28 );
            if ( $tid <= 0 ) continue;

            echo '<option value="' . esc_attr( (string) $tid ) . '"'
                . ' data-days="' . esc_attr( (string) $days ) . '"'
                . selected( $current_track, $tid, false ) . '>'
                . esc_html( (string) ( $row['name'] ?? '' ) ) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        $start = (string) ( $state['start_date'] ?? current_time( 'Y-m-d' ) );
        $end   = (string) ( $state['end_date'] ?? gmdate( 'Y-m-d', strtotime( $start ) + $default_days * DAY_IN_SECONDS ) );

        echo DateInputComponent::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            'name'     => 'start_date',
            'label'    => __( 'First day', 'talenttrack' ),
            'required' => true,
            'value'    => $start,
        ] );

        echo DateInputComponent::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            'name'     => 'end_date',
            'label'    => __( 'Last day', 'talenttrack' ),
            'required' => true,
            'value'    => $end,
            'hint'     => __( 'Proposed from the track length. Change it if the trial has been agreed for a different period — it can be extended later either way.', 'talenttrack' ),
        ] );

        $notes = (string) ( $state['notes'] ?? '' );
        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-trialw-notes">' . esc_html__( 'Notes', 'talenttrack' ) . '</label>';
        echo '<textarea id="tt-trialw-notes" class="tt-input" name="notes" rows="3" maxlength="2000">' . esc_textarea( $notes ) . '</textarea>';
        echo '<span class="tt-field-hint">' . esc_html__( 'Where this player came from, who recommended them, what the academy wants to find out. Write it as if the family will read it.', 'talenttrack' ) . '</span>';
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        $track = isset( $post['track_id'] ) ? absint( $post['track_id'] ) : 0;
        $start = isset( $post['start_date'] ) ? sanitize_text_field( wp_unslash( (string) $post['start_date'] ) ) : '';
        $end   = isset( $post['end_date'] )   ? sanitize_text_field( wp_unslash( (string) $post['end_date'] ) )   : '';
        $notes = isset( $post['notes'] )      ? sanitize_textarea_field( wp_unslash( (string) $post['notes'] ) )  : '';

        if ( $track <= 0 ) {
            return new \WP_Error( 'no_track', __( 'Please pick a track.', 'talenttrack' ) );
        }
        if ( $start === '' || $end === '' ) {
            return new \WP_Error( 'no_dates', __( 'A trial needs a first and a last day.', 'talenttrack' ) );
        }
        if ( strtotime( $end ) < strtotime( $start ) ) {
            return new \WP_Error( 'bad_range', __( 'The last day cannot be before the first day.', 'talenttrack' ) );
        }

        return [
            'track_id'   => $track,
            'start_date' => $start,
            'end_date'   => $end,
            'notes'      => $notes,
        ];
    }

    public function nextStep( array $state ): ?string { return 'staff'; }

    /** Not a final step; the framework only calls this when nextStep() is null. */
    public function submit( array $state ) { return []; }
}
