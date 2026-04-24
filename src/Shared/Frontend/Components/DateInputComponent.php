<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DateInputComponent — wrapped native `<input type="date">`.
 *
 * Uses the browser-native date picker (iOS/Android give a wheel; desktop
 * browsers give their native popup). No library dependency. Default
 * value is "today" so the common case of logging a same-day session
 * is zero-click.
 *
 * Usage:
 *
 *     DateInputComponent::render([
 *         'name'     => 'session_date',
 *         'label'    => __( 'Date', 'talenttrack' ),
 *         'required' => true,
 *         'default'  => current_time( 'Y-m-d' ),
 *     ]);
 */
class DateInputComponent {

    /**
     * @param array{name?:string, label?:string, required?:bool, default?:string, min?:string, max?:string, value?:string, hint?:string} $args
     */
    public static function render( array $args = [] ): string {
        $name     = (string) ( $args['name'] ?? 'date' );
        $label    = (string) ( $args['label'] ?? __( 'Date', 'talenttrack' ) );
        $required = ! empty( $args['required'] );
        $value    = (string) ( $args['value'] ?? $args['default'] ?? current_time( 'Y-m-d' ) );
        $min      = isset( $args['min'] ) ? (string) $args['min'] : '';
        $max      = isset( $args['max'] ) ? (string) $args['max'] : '';
        $hint     = isset( $args['hint'] ) ? (string) $args['hint'] : '';

        $out  = '<div class="tt-field">';
        $out .= '<label class="tt-field-label' . ( $required ? ' tt-field-required' : '' ) . '" for="' . esc_attr( $name ) . '">';
        $out .= esc_html( $label );
        $out .= '</label>';
        $out .= '<input type="date" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"';
        $out .= ' class="tt-input" value="' . esc_attr( $value ) . '"';
        if ( $min !== '' ) $out .= ' min="' . esc_attr( $min ) . '"';
        if ( $max !== '' ) $out .= ' max="' . esc_attr( $max ) . '"';
        if ( $required ) $out .= ' required';
        $out .= ' />';
        if ( $hint !== '' ) {
            $out .= '<span class="tt-field-hint">' . esc_html( $hint ) . '</span>';
        }
        $out .= '</div>';
        return $out;
    }
}
