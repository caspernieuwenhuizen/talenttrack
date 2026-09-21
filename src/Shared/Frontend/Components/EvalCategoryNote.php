<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoryNotesRepository;

/**
 * The note on one evaluation category (#3949): a 48px toggle and the
 * collapsible panel it opens.
 *
 * Shared by the evaluation form (`CoachForms::renderEvalForm()`) and the
 * evaluation wizard's rating steps, so the control looks and behaves the
 * same wherever a coach rates. The panel is collapsed until opened; a
 * toggle whose note has text carries `.has-note`, so a closed row still
 * shows that something is written there.
 *
 * Behaviour lives in `assets/js/components/eval-category-note.js`, styles
 * in `assets/css/frontend-eval-form.css`.
 */
final class EvalCategoryNote {

    public static function enqueue(): void {
        wp_enqueue_style( 'tt-frontend-eval-form', TT_PLUGIN_URL . 'assets/css/frontend-eval-form.css', [], TT_VERSION );
        wp_enqueue_script( 'tt-eval-category-note', TT_PLUGIN_URL . 'assets/js/components/eval-category-note.js', [], TT_VERSION, true );
    }

    /**
     * The toggle.
     *
     * @param string $panel_id  Id of the panel it controls.
     * @param string $label     The category's name, for the accessible name.
     * @param string $note      The stored note, '' for none.
     * @param bool   $with_text Show the visible "Note" label (from 480px).
     * @param string $extra     Extra classes.
     */
    public static function button( string $panel_id, string $label, string $note, bool $with_text = false, string $extra = '' ): string {
        /* translators: %s: rating category name */
        $add  = sprintf( __( 'Add note to %s', 'talenttrack' ), $label );
        /* translators: %s: rating category name */
        $edit = sprintf( __( 'Edit note on %s', 'talenttrack' ), $label );
        $has  = $note !== '';

        $icon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

        return sprintf(
            '<button type="button" class="%1$s" data-tt-evf-note-toggle aria-expanded="false" aria-controls="%2$s" aria-label="%3$s" data-label-add="%4$s" data-label-edit="%5$s">%6$s%7$s</button>',
            esc_attr( trim( 'tt-evf-note-btn ' . $extra . ( $has ? ' has-note' : '' ) ) ),
            esc_attr( $panel_id ),
            esc_attr( $has ? $edit : $add ),
            esc_attr( $add ),
            esc_attr( $edit ),
            $icon,
            $with_text ? '<span class="tt-evf-note-btn__text">' . esc_html_x( 'Note', 'evaluation category note toggle', 'talenttrack' ) . '</span>' : ''
        );
    }

    /**
     * The collapsible panel: a textarea with a visually hidden label, the
     * audience hint and a live counter.
     *
     * @param string $panel_id Id the toggle points at.
     * @param string $name     The textarea's form name.
     * @param string $label    The category's name.
     * @param string $note     The stored note.
     * @param string $extra    Extra classes.
     */
    public static function panel( string $panel_id, string $name, string $label, string $note, string $extra = '' ): string {
        $max      = EvalCategoryNotesRepository::MAX_LENGTH;
        $field_id = $panel_id . '-text';
        /* translators: %s: rating category name */
        $sr = sprintf( __( 'Note on %s', 'talenttrack' ), $label );

        return sprintf(
            '<div class="%1$s" id="%2$s" data-tt-evf-note hidden>'
            . '<label class="tt-evf-sr" for="%3$s">%4$s</label>'
            . '<textarea id="%3$s" name="%5$s" maxlength="%6$d" rows="2" placeholder="%7$s" data-tt-evf-note-text>%8$s</textarea>'
            . '<div class="tt-evf-note__meta"><span>%9$s</span><span class="tt-evf-note__count" data-tt-evf-note-count>%10$d/%6$d</span></div>'
            . '</div>',
            esc_attr( trim( 'tt-evf-note ' . $extra ) ),
            esc_attr( $panel_id ),
            esc_attr( $field_id ),
            esc_html( $sr ),
            esc_attr( $name ),
            $max,
            esc_attr__( 'What did you see? For example the situation, or one moment.', 'talenttrack' ),
            esc_textarea( $note ),
            esc_html__( 'Visible to everyone who can see this evaluation.', 'talenttrack' ),
            mb_strlen( $note )
        );
    }
}
