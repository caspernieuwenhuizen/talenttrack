<?php
namespace TT\Modules\Measurements\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — unit, direction, recurrence.
 *
 * The unit is picked from the `measurement_unit` lookup OR typed as a
 * custom value (the custom field wins when filled — no JS reveal needed).
 * Unit only applies to the numeric value type.
 */
final class MeasurementOptionsStep implements WizardStepInterface {

    public function slug(): string  { return 'options'; }
    public function label(): string { return __( 'Unit & recurrence', 'talenttrack' ); }

    public function render( array $state ): void {
        $value_type = (string) ( $state['value_type'] ?? 'numeric' );
        $unit       = (string) ( $state['unit'] ?? '' );
        $direction  = (string) ( $state['direction'] ?? 'higher' );
        $frequency  = (string) ( $state['frequency'] ?? 'adhoc' );

        if ( $value_type === 'numeric' ) {
            $units = QueryHelpers::get_lookups( 'measurement_unit' );
            $unit_names = array_map( static fn( $r ) => (string) $r->name, $units );
            $is_listed = in_array( $unit, $unit_names, true );

            echo '<label><span>' . esc_html__( 'Unit', 'talenttrack' ) . '</span><select name="unit">';
            echo '<option value="">' . esc_html__( '— none —', 'talenttrack' ) . '</option>';
            foreach ( $units as $row ) {
                $name  = (string) $row->name;
                $label = LookupTranslator::name( $row );
                echo '<option value="' . esc_attr( $name ) . '" ' . selected( $is_listed ? $unit : '', $name, false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</select></label>';

            echo '<label><span>' . esc_html__( 'Custom unit (overrides the list)', 'talenttrack' ) . '</span>'
                . '<input type="text" name="unit_custom" maxlength="50" value="' . esc_attr( ! $is_listed ? $unit : '' ) . '" '
                . 'placeholder="' . esc_attr__( 'e.g. watt/kg', 'talenttrack' ) . '" /></label>';

            $dirs = [
                'higher'  => __( 'Higher is better', 'talenttrack' ),
                'lower'   => __( 'Lower is better', 'talenttrack' ),
                'neutral' => __( 'Neither (just track it)', 'talenttrack' ),
            ];
            echo '<label><span>' . esc_html__( 'Direction', 'talenttrack' ) . '</span><select name="direction">';
            foreach ( $dirs as $key => $label ) {
                echo '<option value="' . esc_attr( $key ) . '" ' . selected( $direction, $key, false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</select></label>';
        }

        $freqs = [
            'annual'    => __( 'Once a season', 'talenttrack' ),
            'biannual'  => __( 'Twice a season', 'talenttrack' ),
            'quarterly' => __( 'Four times a season', 'talenttrack' ),
            'monthly'   => __( 'Monthly', 'talenttrack' ),
            'adhoc'     => __( 'No fixed cadence', 'talenttrack' ),
        ];
        echo '<label><span>' . esc_html__( 'Recurrence', 'talenttrack' ) . '</span><select name="frequency">';
        foreach ( $freqs as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $frequency, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';
    }

    public function validate( array $post, array $state ) {
        $value_type = (string) ( $state['value_type'] ?? 'numeric' );

        $unit_listed = isset( $post['unit'] ) ? sanitize_text_field( wp_unslash( (string) $post['unit'] ) ) : '';
        $unit_custom = isset( $post['unit_custom'] ) ? sanitize_text_field( wp_unslash( (string) $post['unit_custom'] ) ) : '';
        $unit = $unit_custom !== '' ? $unit_custom : $unit_listed;

        $direction = isset( $post['direction'] ) ? sanitize_text_field( wp_unslash( (string) $post['direction'] ) ) : 'higher';
        if ( ! in_array( $direction, [ 'higher', 'lower', 'neutral' ], true ) ) $direction = 'higher';

        $frequency = isset( $post['frequency'] ) ? sanitize_text_field( wp_unslash( (string) $post['frequency'] ) ) : 'adhoc';
        if ( ! in_array( $frequency, [ 'annual', 'biannual', 'quarterly', 'monthly', 'adhoc' ], true ) ) $frequency = 'adhoc';

        return [
            'unit'      => $value_type === 'numeric' ? $unit : '',
            'direction' => $value_type === 'numeric' ? $direction : 'neutral',
            'frequency' => $frequency,
        ];
    }

    public function nextStep( array $state ): ?string { return 'targets'; }

    public function submit( array $state ) { return null; }
}
