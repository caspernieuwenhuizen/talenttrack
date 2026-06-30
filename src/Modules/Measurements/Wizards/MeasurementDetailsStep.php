<?php
namespace TT\Modules\Measurements\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — what is the test? Category, name, and value type.
 */
final class MeasurementDetailsStep implements WizardStepInterface {

    public function slug(): string  { return 'details'; }
    public function label(): string { return __( 'Test details', 'talenttrack' ); }

    public function render( array $state ): void {
        $category_id = (int) ( $state['category_id'] ?? 0 );
        $name        = (string) ( $state['name'] ?? '' );
        $value_type  = (string) ( $state['value_type'] ?? 'numeric' );

        $categories = QueryHelpers::get_lookups( 'measurement_category' );

        echo '<label><span>' . esc_html__( 'Category', 'talenttrack' ) . ' *</span><select name="category_id" required>';
        echo '<option value="0">' . esc_html__( '— choose —', 'talenttrack' ) . '</option>';
        foreach ( $categories as $row ) {
            $label = LookupTranslator::name( $row );
            echo '<option value="' . (int) $row->id . '" ' . selected( $category_id, (int) $row->id, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';

        echo '<label><span>' . esc_html__( 'Name', 'talenttrack' ) . ' *</span>'
            . '<input type="text" name="name" required maxlength="190" value="' . esc_attr( $name ) . '" '
            . 'placeholder="' . esc_attr__( 'e.g. Sprint 30m', 'talenttrack' ) . '" /></label>';

        $types = [
            'numeric'  => __( 'A number (with a unit)', 'talenttrack' ),
            'scale'    => __( 'A scale score', 'talenttrack' ),
            'passfail' => __( 'Pass / fail', 'talenttrack' ),
            'status'   => __( 'A status (coloured levels)', 'talenttrack' ),
        ];
        echo '<label><span>' . esc_html__( 'Value type', 'talenttrack' ) . '</span><select name="value_type">';
        foreach ( $types as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value_type, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';
    }

    public function validate( array $post, array $state ) {
        $category_id = isset( $post['category_id'] ) ? absint( $post['category_id'] ) : 0;
        $name        = trim( (string) ( $post['name'] ?? '' ) );
        $value_type  = isset( $post['value_type'] ) ? sanitize_text_field( wp_unslash( (string) $post['value_type'] ) ) : 'numeric';

        if ( $category_id <= 0 ) {
            return new \WP_Error( 'category', __( 'Choose a category.', 'talenttrack' ) );
        }
        if ( $name === '' ) {
            return new \WP_Error( 'name', __( 'A test name is required.', 'talenttrack' ) );
        }
        if ( ! in_array( $value_type, [ 'numeric', 'scale', 'passfail', 'status' ], true ) ) {
            $value_type = 'numeric';
        }

        return [
            'category_id' => $category_id,
            'name'        => sanitize_text_field( $name ),
            'value_type'  => $value_type,
        ];
    }

    public function nextStep( array $state ): ?string { return 'options'; }

    public function submit( array $state ) { return null; }
}
