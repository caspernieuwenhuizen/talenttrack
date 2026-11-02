<?php
namespace TT\Modules\ReportStudio\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\ReportStudio\Domain\ReportStartRules;
use TT\Modules\ReportStudio\Domain\StudioError;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — when and how: the period, and rows or a grid.
 *
 * A rolling period names which one; fixed dates take a from and a to. Both
 * inputs are on the page and only the chosen mode's is read, so the step
 * works without script.
 */
final class WhenStep implements WizardStepInterface {

    public function slug(): string  { return 'when'; }
    public function label(): string { return __( 'When and how', 'talenttrack' ); }

    public function render( array $state ): void {
        StartChoices::fieldset( 'period_mode', __( 'Which period does it cover?', 'talenttrack' ), $state );

        $rolling = (string) ( $state['period_rolling'] ?? 'last_month' );
        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-rsw-rolling">' . esc_html__( 'For a rolling period', 'talenttrack' ) . '</label>';
        echo '<select id="tt-rsw-rolling" class="tt-input" name="period_rolling">';
        foreach ( ReportFilters::periodLabels() as $key => $label ) {
            if ( ! in_array( (string) $key, ReportFilters::PERIODS, true ) ) continue;
            echo '<option value="' . esc_attr( (string) $key ) . '"' . selected( $rolling, (string) $key, false ) . '>' . esc_html( (string) $label ) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="tt-rsw-dates">';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-rsw-from">' . esc_html__( 'Fixed dates: from', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="tt-rsw-from" class="tt-input" name="period_from" value="' . esc_attr( (string) ( $state['period_from'] ?? '' ) ) . '" /></div>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-rsw-to">' . esc_html_x( 'to', 'the end of a date range', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="tt-rsw-to" class="tt-input" name="period_to" value="' . esc_attr( (string) ( $state['period_to'] ?? '' ) ) . '" /></div>';
        echo '</div>';

        StartChoices::fieldset( 'layout', __( 'How is it laid out?', 'talenttrack' ), $state );
    }

    public function validate( array $post, array $state ) {
        $answers = StartChoices::answers( [ 'period_mode', 'layout' ], $post, $state );
        if ( is_wp_error( $answers ) ) return $answers;

        $extra = [];
        if ( $answers['period_mode'] === ReportStartRules::PERIOD_ROLLING ) {
            $rolling = isset( $post['period_rolling'] ) ? sanitize_key( (string) $post['period_rolling'] ) : '';
            if ( ! in_array( $rolling, ReportFilters::PERIODS, true ) ) {
                return new \WP_Error( 'tt_rsw_rolling', __( 'Choose which period the report rolls with.', 'talenttrack' ) );
            }
            $extra['period_rolling'] = $rolling;
        }
        if ( $answers['period_mode'] === ReportStartRules::PERIOD_FIXED ) {
            $from = isset( $post['period_from'] ) ? sanitize_text_field( (string) $post['period_from'] ) : '';
            $to   = isset( $post['period_to'] ) ? sanitize_text_field( (string) $post['period_to'] ) : '';
            $extra['period_from'] = $from;
            $extra['period_to']   = $to;
            // The rules check the dates themselves, so the wizard and REST
            // refuse the same ones with the same words.
            $check = ReportStartRules::periodValue( ReportStartRules::PERIOD_FIXED, $from . '/' . $to );
            if ( $check instanceof StudioError ) return new \WP_Error( 'tt_rsw_dates', $check->message );
        }
        return StartChoices::settle( $state, $answers ) + $extra;
    }

    public function nextStep( array $state ): ?string { return 'sharing'; }

    /** @return array<string,mixed> */
    public function submit( array $state ): array { return []; }
}
