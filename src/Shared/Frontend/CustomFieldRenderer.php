<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;

/**
 * CustomFieldRenderer — renders an <input> / <select> for a custom field.
 *
 * v2.11.0 (Sprint 1H): extended from 5 to 10 field types. Added
 * textarea, multi_select, url, email, phone. Added inputRow() for
 * callers that want a full `<tr>` wrapper, and display() updates for
 * the new types.
 *
 * Input naming convention: `custom_fields[field_key]` for scalars,
 * `custom_fields[field_key][]` for multi_select. The save handler
 * reads $_POST['custom_fields'] and passes it to CustomFieldValidator.
 *
 * Rendering is output-only — escape as you emit. Callers using
 * `input()` are responsible for wrapping. `inputRow()` emits the
 * full `<tr><th>label</th><td>input</td></tr>`.
 */
class CustomFieldRenderer {

    /**
     * Render a full <tr> row: label column + input column. Convenient
     * for callers using a WP-admin `.form-table` layout.
     */
    public static function inputRow( object $field, $value = null, string $prefix = 'custom_fields' ): string {
        $id       = 'tt_cf_' . esc_attr( (string) $field->field_key );
        $required = ! empty( $field->is_required ) ? ' <span style="color:#b32d2e;">*</span>' : '';
        return sprintf(
            '<tr><th><label for="%s">%s%s</label></th><td>%s</td></tr>',
            esc_attr( $id ),
            esc_html( (string) $field->label ),
            $required,
            self::input( $field, $value, $prefix )
        );
    }

    /**
     * Render the input element for a field, prefilled with the given value.
     *
     * @param object $field  Row from tt_custom_fields
     * @param mixed  $value  Current value (any type; coerced)
     * @param string $prefix Form-field name prefix (default: 'custom_fields')
     */
    public static function input( object $field, $value = null, string $prefix = 'custom_fields' ): string {
        $key  = (string) $field->field_key;
        $type = (string) $field->field_type;
        $name = $prefix . '[' . $key . ']';
        $req  = ! empty( $field->is_required );
        $attr_required = $req ? ' required' : '';

        switch ( $type ) {
            case CustomFieldsRepository::TYPE_TEXT:
                return sprintf(
                    '<input type="text" name="%s" value="%s" class="regular-text"%s />',
                    esc_attr( $name ),
                    esc_attr( (string) ( $value ?? '' ) ),
                    $attr_required
                );

            case CustomFieldsRepository::TYPE_TEXTAREA:
                return sprintf(
                    '<textarea name="%s" rows="3" class="large-text"%s>%s</textarea>',
                    esc_attr( $name ),
                    $attr_required,
                    esc_textarea( (string) ( $value ?? '' ) )
                );

            case CustomFieldsRepository::TYPE_URL:
                return sprintf(
                    '<input type="url" name="%s" value="%s" class="regular-text"%s />',
                    esc_attr( $name ),
                    esc_attr( (string) ( $value ?? '' ) ),
                    $attr_required
                );

            case CustomFieldsRepository::TYPE_EMAIL:
                return sprintf(
                    '<input type="email" name="%s" value="%s" class="regular-text" inputmode="email" autocomplete="email"%s />',
                    esc_attr( $name ),
                    esc_attr( (string) ( $value ?? '' ) ),
                    $attr_required
                );

            case CustomFieldsRepository::TYPE_PHONE:
                return sprintf(
                    '<input type="tel" name="%s" value="%s" class="regular-text" inputmode="tel" autocomplete="tel"%s />',
                    esc_attr( $name ),
                    esc_attr( (string) ( $value ?? '' ) ),
                    $attr_required
                );

            case CustomFieldsRepository::TYPE_NUMBER:
                return sprintf(
                    '<input type="number" name="%s" value="%s" step="any" inputmode="decimal"%s />',
                    esc_attr( $name ),
                    esc_attr( $value === null || $value === '' ? '' : (string) $value ),
                    $attr_required
                );

            case CustomFieldsRepository::TYPE_DATE:
                return sprintf(
                    '<input type="date" name="%s" value="%s"%s />',
                    esc_attr( $name ),
                    esc_attr( (string) ( $value ?? '' ) ),
                    $attr_required
                );

            case CustomFieldsRepository::TYPE_CHECKBOX:
                $checked = in_array( $value, [ true, 1, '1', 'on', 'true' ], true );
                // Hidden companion input ensures an unchecked box still submits "0"
                // so validation for required-checkbox works cleanly.
                return sprintf(
                    '<input type="hidden" name="%s" value="0" />'
                    . '<label style="font-weight:400;"><input type="checkbox" name="%s" value="1"%s /> %s</label>',
                    esc_attr( $name ),
                    esc_attr( $name ),
                    $checked ? ' checked' : '',
                    esc_html( (string) $field->label )
                );

            case CustomFieldsRepository::TYPE_SELECT:
                $options = CustomFieldsRepository::decodeOptions( $field->options ?? null );
                $current = (string) ( $value ?? '' );
                $html = sprintf( '<select name="%s"%s>', esc_attr( $name ), $attr_required );
                if ( ! $req ) {
                    $html .= '<option value="">' . esc_html__( '— Select —', 'talenttrack' ) . '</option>';
                }
                foreach ( $options as $opt ) {
                    $html .= sprintf(
                        '<option value="%s"%s>%s</option>',
                        esc_attr( $opt['value'] ),
                        selected( $current, $opt['value'], false ),
                        esc_html( $opt['label'] )
                    );
                }
                $html .= '</select>';
                return $html;

            case CustomFieldsRepository::TYPE_MULTI_SELECT:
                $options = CustomFieldsRepository::decodeOptions( $field->options ?? null );
                $multi_name = $prefix . '[' . $key . '][]';
                // Normalize current value to an array of string values.
                if ( is_array( $value ) ) {
                    $selected = array_map( 'strval', $value );
                } else {
                    $selected = array_values( array_filter(
                        array_map( 'trim', explode( ',', (string) ( $value ?? '' ) ) ),
                        function ( $v ) { return $v !== ''; }
                    ) );
                }

                // Marker so the save layer can distinguish "field wasn't on
                // the form" from "all checkboxes unticked".
                $html = sprintf(
                    '<input type="hidden" name="custom_fields_multi_marker[%s]" value="1" />',
                    esc_attr( $key )
                );
                $html .= '<div style="max-height:180px; overflow:auto; border:1px solid #c3c4c7; padding:6px 10px; background:#fff;">';
                foreach ( $options as $opt ) {
                    $v = (string) $opt['value'];
                    $l = (string) $opt['label'];
                    $checked = in_array( $v, $selected, true ) ? ' checked' : '';
                    $html .= sprintf(
                        '<label style="display:block; margin:2px 0;"><input type="checkbox" name="%s" value="%s"%s /> %s</label>',
                        esc_attr( $multi_name ),
                        esc_attr( $v ),
                        $checked,
                        esc_html( $l )
                    );
                }
                $html .= '</div>';
                return $html;

            default:
                return '';
        }
    }

    /**
     * Render a display-only representation of a custom field value.
     * Used on detail pages and player dashboards.
     */
    public static function display( object $field, $value ): string {
        if ( $value === null || $value === '' || $value === [] ) return '';
        $type = (string) $field->field_type;

        if ( $type === CustomFieldsRepository::TYPE_CHECKBOX ) {
            $truthy = in_array( $value, [ true, 1, '1' ], true );
            return $truthy
                ? '<span style="color:#00a32a;">✓</span>'
                : '<span style="color:#888;">—</span>';
        }

        if ( $type === CustomFieldsRepository::TYPE_SELECT ) {
            $options = CustomFieldsRepository::decodeOptions( $field->options ?? null );
            foreach ( $options as $opt ) {
                if ( (string) $opt['value'] === (string) $value ) {
                    return esc_html( (string) $opt['label'] );
                }
            }
            return esc_html( (string) $value );
        }

        if ( $type === CustomFieldsRepository::TYPE_MULTI_SELECT ) {
            $options = CustomFieldsRepository::decodeOptions( $field->options ?? null );
            $label_by_value = [];
            foreach ( $options as $o ) $label_by_value[ (string) $o['value'] ] = (string) $o['label'];

            $values_array = is_array( $value )
                ? $value
                : array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
            $labels = array_map( function ( $v ) use ( $label_by_value ) {
                return isset( $label_by_value[ $v ] ) ? $label_by_value[ $v ] : $v;
            }, $values_array );
            return esc_html( implode( ', ', $labels ) );
        }

        if ( $type === CustomFieldsRepository::TYPE_URL ) {
            $url = esc_url( (string) $value );
            if ( $url === '' ) return esc_html( (string) $value );
            return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) $value ) . '</a>';
        }

        if ( $type === CustomFieldsRepository::TYPE_EMAIL ) {
            $addr = esc_attr( (string) $value );
            return '<a href="mailto:' . $addr . '">' . esc_html( (string) $value ) . '</a>';
        }

        if ( $type === CustomFieldsRepository::TYPE_TEXTAREA ) {
            return nl2br( esc_html( (string) $value ) );
        }

        return esc_html( (string) $value );
    }
}
