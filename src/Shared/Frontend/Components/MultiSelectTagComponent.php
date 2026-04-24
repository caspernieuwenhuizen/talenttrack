<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MultiSelectTagComponent — tag-style multi-select over a fixed
 * option set, typically a `tt_lookups` type (positions, attendance
 * statuses, etc.). Renders a hidden `<select multiple>` plus a visible
 * tag strip — the progressive-enhancement JS picker lives in
 * `assets/js/components/multitag.js` and toggles `is-selected` +
 * `selected`.
 *
 * No-JS fallback: the hidden `<select multiple>` shows as a normal
 * native multi-select so the form still works.
 *
 * Usage:
 *
 *     MultiSelectTagComponent::render([
 *         'name'     => 'positions[]',
 *         'label'    => __( 'Positions', 'talenttrack' ),
 *         'options'  => [ 'GK' => 'Keeper', 'DF' => 'Defender', ... ],
 *         'selected' => [ 'GK' ],
 *     ]);
 */
class MultiSelectTagComponent {

    /**
     * @param array{name?:string, label?:string, options?:array<string,string>, selected?:array<int,string>, required?:bool, hint?:string} $args
     */
    public static function render( array $args = [] ): string {
        $name     = (string) ( $args['name'] ?? 'tags[]' );
        $label    = (string) ( $args['label'] ?? __( 'Select', 'talenttrack' ) );
        $options  = is_array( $args['options'] ?? null ) ? $args['options'] : [];
        $selected = is_array( $args['selected'] ?? null ) ? array_map( 'strval', $args['selected'] ) : [];
        $required = ! empty( $args['required'] );
        $hint     = isset( $args['hint'] ) ? (string) $args['hint'] : '';

        $select_name = $name;
        // <select multiple> name must end in [] to come through as array.
        if ( substr( $select_name, -2 ) !== '[]' ) $select_name .= '[]';

        $out  = '<div class="tt-field">';
        $out .= '<span class="tt-field-label' . ( $required ? ' tt-field-required' : '' ) . '">' . esc_html( $label ) . '</span>';
        $out .= '<div class="tt-multitag" data-tt-multitag="1">';

        // Tag strip (populated on page-load by multitag.js for JS users;
        // empty initially). Screen readers read the hidden select.
        $out .= '<div class="tt-multitag-tags" role="list"></div>';

        // Option picker strip — buttons toggle selection.
        $out .= '<div class="tt-multitag-picker" role="listbox" aria-multiselectable="true">';
        foreach ( $options as $value => $text ) {
            $is_sel = in_array( (string) $value, $selected, true );
            $out .= sprintf(
                '<button type="button" class="tt-multitag-option%s" data-value="%s" role="option" aria-selected="%s">%s</button>',
                $is_sel ? ' is-selected' : '',
                esc_attr( (string) $value ),
                $is_sel ? 'true' : 'false',
                esc_html( (string) $text )
            );
        }
        $out .= '</div>';

        // Hidden-ish native select — state of truth. Sized to 0 when JS
        // is on via .tt-multitag select { display:none }.
        $out .= '<select name="' . esc_attr( $select_name ) . '" multiple' . ( $required ? ' required' : '' ) . '>';
        foreach ( $options as $value => $text ) {
            $is_sel = in_array( (string) $value, $selected, true );
            $out .= '<option value="' . esc_attr( (string) $value ) . '"' . ( $is_sel ? ' selected' : '' ) . '>';
            $out .= esc_html( (string) $text );
            $out .= '</option>';
        }
        $out .= '</select>';
        $out .= '</div>';

        if ( $hint !== '' ) {
            $out .= '<span class="tt-field-hint">' . esc_html( $hint ) . '</span>';
        }
        $out .= '</div>';
        return $out;
    }
}
