<?php
namespace TT\Modules\Holidays\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Holidays\Repositories\HolidaysRepository;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * The single step of the new-holiday wizard — collects the name, the
 * date range and an optional note, then creates the record.
 */
final class HolidayDetailsStep implements WizardStepInterface {

    public function slug(): string  { return 'details'; }
    public function label(): string { return __( 'Holiday details', 'talenttrack' ); }

    public function render( array $state ): void {
        $name  = (string) ( $state['name'] ?? '' );
        $from  = (string) ( $state['start_date'] ?? '' );
        $to    = (string) ( $state['end_date'] ?? '' );
        $note  = (string) ( $state['note'] ?? '' );

        echo '<div class="tt-form-row">';
        echo '<label for="tt-holiday-name">' . esc_html__( 'Name', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-holiday-name" name="name" value="' . esc_attr( $name ) . '" required '
            . 'placeholder="' . esc_attr__( 'e.g. Christmas break', 'talenttrack' ) . '" />';
        echo '</div>';

        echo '<div class="tt-form-row">';
        echo '<label for="tt-holiday-from">' . esc_html__( 'Start date', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="tt-holiday-from" name="start_date" value="' . esc_attr( $from ) . '" required />';
        echo '</div>';

        echo '<div class="tt-form-row">';
        echo '<label for="tt-holiday-to">' . esc_html__( 'End date', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="tt-holiday-to" name="end_date" value="' . esc_attr( $to ) . '" required />';
        echo '</div>';

        echo '<div class="tt-form-row">';
        echo '<label for="tt-holiday-note">' . esc_html__( 'Note (optional)', 'talenttrack' ) . '</label>';
        echo '<textarea id="tt-holiday-note" name="note" rows="2">' . esc_textarea( $note ) . '</textarea>';
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        $name = trim( (string) ( $post['name'] ?? '' ) );
        $from = trim( (string) ( $post['start_date'] ?? '' ) );
        $to   = trim( (string) ( $post['end_date'] ?? '' ) );

        if ( $name === '' ) {
            return new \WP_Error( 'name', __( 'A name is required.', 'talenttrack' ) );
        }
        $date_re = '/^\d{4}-\d{2}-\d{2}$/';
        if ( ! preg_match( $date_re, $from ) || ! preg_match( $date_re, $to ) ) {
            return new \WP_Error( 'dates', __( 'Both a start and end date are required.', 'talenttrack' ) );
        }
        if ( $from > $to ) {
            return new \WP_Error( 'range', __( 'The start date must be on or before the end date.', 'talenttrack' ) );
        }

        return [
            'name'       => sanitize_text_field( $name ),
            'start_date' => $from,
            'end_date'   => $to,
            'note'       => sanitize_textarea_field( (string) ( $post['note'] ?? '' ) ),
        ];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $id = ( new HolidaysRepository() )->create( [
            'name'       => (string) ( $state['name'] ?? '' ),
            'start_date' => (string) ( $state['start_date'] ?? '' ),
            'end_date'   => (string) ( $state['end_date'] ?? '' ),
            'note'       => (string) ( $state['note'] ?? '' ),
        ] );
        if ( $id <= 0 ) {
            return new \WP_Error( 'create_failed', __( 'Could not create the holiday.', 'talenttrack' ) );
        }
        return [ 'redirect_url' => add_query_arg(
            [ 'tt_view' => 'holidays' ],
            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
        ) ];
    }
}
