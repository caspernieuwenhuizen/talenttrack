<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FormSaveButton — submit button with idle/saving/saved/error states,
 * plus an optional sibling Cancel link.
 *
 * Introduced in #0019 Sprint 1 session 3. The existing `.tt-btn-primary`
 * buttons in CoachForms just flip between "Saving..." and the original
 * label; this component formalizes the state machine so every future
 * form gets the same visual feedback for free. The REST fetch handler
 * in public.js looks for `.tt-save-btn` inside the submitting form and
 * drives its `data-state` attribute.
 *
 * v3.110.58 — formalises the Save + Cancel pair required by
 * `CLAUDE.md` § 6 (Save + Cancel on every record-mutating form). Pass a
 * `cancel_url` and the helper renders both buttons inside a
 * `.tt-form-actions` wrapper, with Cancel as the secondary action so the
 * user always has an obvious way out without saving. Without the cancel
 * URL it renders the bare submit button as before (back-compat for forms
 * that don't mutate a single record — e.g. settings-page sub-forms).
 *
 * Usage:
 *
 *     FormSaveButton::render([
 *         'label'        => __( 'Save changes', 'talenttrack' ),
 *         'label_saving' => __( 'Saving...', 'talenttrack' ),
 *         'label_saved'  => __( 'Saved', 'talenttrack' ),
 *         'variant'      => 'primary', // primary | secondary | danger
 *         'cancel_url'   => $cancel_url,
 *         'cancel_label' => __( 'Cancel', 'talenttrack' ),
 *     ]);
 *
 * All label variants are localized on render (no JS-side translation
 * needed). Passing them explicitly lets a form override default copy
 * per context (e.g. "Add Goal" vs "Save").
 */
class FormSaveButton {

    /**
     * @param array{label?:string, label_saving?:string, label_saved?:string, label_error?:string, variant?:string, block?:bool, id?:string, cancel_url?:string, cancel_label?:string} $args
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
        $cancel_url   = isset( $args['cancel_url'] ) ? (string) $args['cancel_url'] : '';
        $cancel_label = (string) ( $args['cancel_label'] ?? __( 'Cancel', 'talenttrack' ) );

        $class = 'tt-btn tt-btn-' . $variant . ' tt-save-btn' . ( $block ? ' tt-btn-block' : '' );

        $save_html = sprintf(
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

        if ( $cancel_url === '' ) {
            return $save_html;
        }

        // Cancel renders BEFORE Save in DOM order so the destructive /
        // commit action is on the right where the thumb expects it on
        // mobile, and Tab order leads from Cancel → Save (least-
        // committal first). The .tt-form-actions wrapper handles the
        // gap + alignment via existing CSS.
        $cancel_html = sprintf(
            '<a class="tt-btn tt-btn-secondary tt-form-cancel" href="%s">%s</a>',
            esc_url( $cancel_url ),
            esc_html( $cancel_label )
        );

        return '<div class="tt-form-actions">' . $cancel_html . $save_html . '</div>';
    }
}
