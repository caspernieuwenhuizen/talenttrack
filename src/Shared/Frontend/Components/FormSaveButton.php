<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FormSaveButton — submit button with idle/saving/saved/error states.
 *
 * Introduced in #0019 Sprint 1 session 3. The existing `.tt-btn-primary`
 * buttons in CoachForms just flip between "Saving..." and the original
 * label; this component formalizes the state machine so every future
 * form gets the same visual feedback for free. The REST fetch handler
 * in public.js looks for `.tt-save-btn` inside the submitting form and
 * drives its `data-state` attribute.
 *
 * Usage:
 *
 *     FormSaveButton::render([
 *         'label'        => __( 'Save Evaluation', 'talenttrack' ),
 *         'label_saving' => __( 'Saving...', 'talenttrack' ),
 *         'label_saved'  => __( 'Saved', 'talenttrack' ),
 *         'variant'      => 'primary', // primary | secondary | danger
 *     ]);
 *
 * All label variants are localized on render (no JS-side translation
 * needed). Passing them explicitly lets a form override default copy
 * per context (e.g. "Add Goal" vs "Save").
 */
class FormSaveButton {

    /**
     * @param array{label?:string, label_saving?:string, label_saved?:string, label_error?:string, variant?:string, block?:bool, id?:string} $args
     */
    public static function render( array $args = [] ): string {
        $label        = (string) ( $args['label']        ?? __( 'Save', 'talenttrack' ) );
        $label_saving = (string) ( $args['label_saving'] ?? __( 'Saving...', 'talenttrack' ) );
        $label_saved  = (string) ( $args['label_saved']  ?? __( 'Saved', 'talenttrack' ) );
        $label_error  = (string) ( $args['label_error']  ?? __( 'Retry', 'talenttrack' ) );
        $variant      = in_array( ( $args['variant'] ?? 'primary' ), [ 'primary', 'secondary', 'danger' ], true )
            ? $args['variant'] : 'primary';
        $block        = ! empty( $args['block'] );
        $id           = isset( $args['id'] ) ? (string) $args['id'] : '';

        $class = 'tt-btn tt-btn-' . $variant . ' tt-save-btn' . ( $block ? ' tt-btn-block' : '' );

        return sprintf(
            '<button type="submit"%s class="%s" data-state="idle"'
            . ' data-label-idle="%s" data-label-saving="%s" data-label-saved="%s" data-label-error="%s">'
            . '<span class="tt-save-btn-label">%s</span>'
            . '</button>',
            $id !== '' ? ' id="' . esc_attr( $id ) . '"' : '',
            esc_attr( $class ),
            esc_attr( $label ),
            esc_attr( $label_saving ),
            esc_attr( $label_saved ),
            esc_attr( $label_error ),
            esc_html( $label )
        );
    }
}
