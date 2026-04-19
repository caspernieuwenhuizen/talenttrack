<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;

/**
 * CustomFieldRenderer — renders an <input> / <select> for a custom field.
 *
 * Used from:
 *   - PlayersPage (admin) form
 *   - Frontend player forms (future)
 *   - Any other entity form using custom fields
 *
 * Input naming convention:  custom_fields[field_key]
 * The save handler reads $_POST['custom_fields'] and passes it to
 * CustomFieldValidator.
 *
 * Rendering is **output only** — escape as you emit. Callers are
 * responsible for wrapping the input in their own row / label structure.
 */
class CustomFieldRenderer {

    /**
     * Render the input element for a field, prefilled with the given value.
     *
     * @param object     $field Row from tt_custom_fields
     * @param mixed      $value Current value (can be any type; coerced)
     * @param string     $prefix Form-field name prefix (default: 'custom_fields')
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

            case CustomFieldsRepository::TYPE_NUMBER:
                return sprintf(
                    '<input type="number" name="%s" value="%s" step="any"%s />',
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

            default:
                // Unknown type: render nothing (keeps admin UI safe when a field
                // was created under a newer version that supports more types).
                return '';
        }
    }

    /**
     * Render a display-only representation of a custom field value.
     * Used on the player dashboard (read-only views).
     *
     * @param object $field
     * @param mixed  $value
     */
    public static function display( object $field, $value ): string {
        if ( $value === null || $value === '' ) return '';
        $type = (string) $field->field_type;

        if ( $type === CustomFieldsRepository::TYPE_CHECKBOX ) {
            $truthy = in_array( $value, [ true, 1, '1' ], true );
            return $truthy ? esc_html__( 'Yes', 'talenttrack' ) : esc_html__( 'No', 'talenttrack' );
        }

        if ( $type === CustomFieldsRepository::TYPE_SELECT ) {
            // Resolve stored value back to its label.
            $options = CustomFieldsRepository::decodeOptions( $field->options ?? null );
            foreach ( $options as $opt ) {
                if ( $opt['value'] === (string) $value ) {
                    return esc_html( $opt['label'] );
                }
            }
            return esc_html( (string) $value );
        }

        return esc_html( (string) $value );
    }
}
