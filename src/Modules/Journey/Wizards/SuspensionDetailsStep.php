<?php
namespace TT\Modules\Journey\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\SuspensionRepository;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\DateInputComponent;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — the suspension: why, how many matches, from when, and a note.
 *
 * A suspension lasts a number of matches, not until a date (decision 1 on
 * #4103), so the date here is where counting starts: every match of the
 * team on or after it counts, league, cup and friendly alike.
 *
 * The fields are rendered by `renderFields()`, which the flat form on
 * `?tt_view=suspensions` calls too, so the two ways in ask the same
 * questions in the same words.
 */
final class SuspensionDetailsStep implements WizardStepInterface {

    public function slug(): string  { return 'details'; }
    public function label(): string { return __( 'Suspension', 'talenttrack' ); }

    public function render( array $state ): void {
        self::renderFields( [
            'reason_lookup_id' => (int) ( $state['reason_lookup_id'] ?? 0 ),
            'match_count'      => (int) ( $state['match_count'] ?? 1 ),
            'started_on'       => (string) ( $state['started_on'] ?? current_time( 'Y-m-d' ) ),
            'notes'            => (string) ( $state['notes'] ?? '' ),
        ] );
    }

    /**
     * The four fields, shared by the wizard step and the flat form.
     *
     * @param array{reason_lookup_id:int, match_count:int, started_on:string, notes:string} $values
     */
    public static function renderFields( array $values, bool $locked = false ): void {
        $reasons = QueryHelpers::get_lookups( 'suspension_reason' );

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-suspension-reason">' . esc_html__( 'Reason', 'talenttrack' ) . '</label>';
        echo '<select id="tt-suspension-reason" class="tt-input" name="reason_lookup_id">';
        echo '<option value="0">' . esc_html__( '— Not recorded —', 'talenttrack' ) . '</option>';
        foreach ( $reasons as $row ) {
            $rid = (int) ( ( (array) $row )['id'] ?? 0 );
            echo '<option value="' . esc_attr( (string) $rid ) . '"' . selected( $values['reason_lookup_id'], $rid, false ) . '>'
                . esc_html( LookupTranslator::name( $row ) ) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label tt-field-required" for="tt-suspension-matches">' . esc_html__( 'Number of matches', 'talenttrack' ) . '</label>';
        echo '<input type="number" id="tt-suspension-matches" class="tt-input tt-suspension-matches" name="match_count" inputmode="numeric" min="1" max="' . esc_attr( (string) SuspensionRepository::MAX_MATCHES ) . '" step="1" required value="' . esc_attr( (string) max( 1, $values['match_count'] ) ) . '"' . ( $locked ? ' readonly' : '' ) . ' />';
        echo '<span class="tt-field-hint">' . esc_html__( 'Every match of the team counts: league, cup and friendly. Trainings never do.', 'talenttrack' ) . '</span>';
        echo '</div>';

        // A served suspension keeps its start and length: they are history.
        if ( $locked ) {
            echo '<input type="hidden" name="started_on" value="' . esc_attr( $values['started_on'] ) . '" />';
            echo '<p class="tt-field-hint">' . esc_html__( 'This suspension has been served, so its start date and number of matches are fixed.', 'talenttrack' ) . '</p>';
        } else {
            echo DateInputComponent::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes
                'name'     => 'started_on',
                'label'    => __( 'Counts from', 'talenttrack' ),
                'required' => true,
                'value'    => $values['started_on'],
                'hint'     => __( 'The first day a match counts towards the suspension.', 'talenttrack' ),
            ] );
        }

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-suspension-notes">' . esc_html__( 'Note', 'talenttrack' ) . '</label>';
        echo '<textarea id="tt-suspension-notes" class="tt-input" name="notes" rows="3" maxlength="1000">' . esc_textarea( $values['notes'] ) . '</textarea>';
        echo '<span class="tt-field-hint">' . esc_html__( 'The player and their parents can read this, along with the reason.', 'talenttrack' ) . '</span>';
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        $started = isset( $post['started_on'] ) ? SuspensionRepository::cleanDate( sanitize_text_field( wp_unslash( (string) $post['started_on'] ) ) ) : '';
        $count   = isset( $post['match_count'] ) ? absint( $post['match_count'] ) : 0;
        $reason  = isset( $post['reason_lookup_id'] ) ? absint( $post['reason_lookup_id'] ) : 0;
        $notes   = isset( $post['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $post['notes'] ) ) : '';

        if ( $started === '' ) {
            return new \WP_Error( 'started_on', __( 'Enter the day the suspension starts counting from.', 'talenttrack' ) );
        }
        if ( $count < 1 || $count > SuspensionRepository::MAX_MATCHES ) {
            return new \WP_Error( 'match_count', sprintf(
                /* translators: %d: the highest number of matches allowed */
                __( 'A suspension lasts between 1 and %d matches.', 'talenttrack' ),
                SuspensionRepository::MAX_MATCHES
            ) );
        }

        return [
            'started_on'       => $started,
            'match_count'      => $count,
            'reason_lookup_id' => $reason,
            'notes'            => $notes,
        ];
    }

    public function nextStep( array $state ): ?string { return 'confirm'; }

    public function submit( array $state ) { return null; }
}
