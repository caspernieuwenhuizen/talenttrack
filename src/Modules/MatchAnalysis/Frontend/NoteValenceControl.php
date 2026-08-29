<?php
namespace TT\Modules\MatchAnalysis\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\MatchAnalysisEnums;

/**
 * NoteValenceControl (#3091) — the + / − in front of one note.
 *
 * A phase rated "Wisselend" has four bullets under it and, until now, no
 * way to tell which two were the good half. This is the mark that says so,
 * per sentence rather than per phase.
 *
 * ## A radio group, not a cycling button
 *
 * The obvious build is one button that cycles neutral → plus → minus. It is
 * also the wrong one: it has no honest accessible name (its label changes
 * with its own state), it cannot be operated without JavaScript, and it
 * hides two of its three values from anyone reading the form rather than
 * looking at it.
 *
 * So this is the pattern `SectionRatingControl` already proves: real
 * radios, a **Clear** option CSS reveals only once something is selected,
 * and no script at all. The wizard, the flat form and a browser with JS
 * turned off all post the same field.
 *
 * ## Neutral has no glyph
 *
 * An unmarked note renders nothing — no dash, no empty circle. Most notes
 * are observations rather than verdicts, and a placeholder on every one of
 * them would turn the resting state into visual noise and make the two
 * marks that matter harder to find.
 */
final class NoteValenceControl {

    /**
     * @param string  $name_base form field prefix, e.g. `sections[aanvallen][notes][0]`
     * @param string  $current   stored valence, or ''
     * @param string  $id        unique element id stem
     * @param string  $context   what this note is about, for the group's
     *                           accessible name — a phase and point number,
     *                           or a player and note number
     */
    public static function render( string $name_base, string $current, string $id, string $context ): void {
        $current = MatchAnalysisEnums::isValence( $current ) ? $current : '';
        $glyphs  = MatchAnalysisEnums::valenceGlyphs();

        printf(
            '<span class="tt-ma__valence" role="radiogroup" aria-label="%s">',
            esc_attr( sprintf(
                /* translators: %s: what the note is about, e.g. "Aanvallen — point 2" */
                __( 'Mark this point — %s', 'talenttrack' ),
                $context
            ) )
        );

        foreach ( MatchAnalysisEnums::valences() as $value => $label ) {
            $option_id = $id . '-' . sanitize_key( (string) $value );
            printf(
                '<input type="radio" class="tt-ma__valence-input" id="%1$s" name="%2$s[valence]" value="%3$s"%4$s />'
                . '<label class="tt-ma__valence-opt" for="%1$s" data-valence="%3$s" title="%6$s" aria-label="%6$s">'
                . '<span aria-hidden="true">%5$s</span></label>',
                esc_attr( $option_id ),
                esc_attr( $name_base ),
                esc_attr( (string) $value ),
                checked( $current, (string) $value, false ),
                esc_html( $glyphs[ (string) $value ] ?? '' ),
                esc_attr( sprintf(
                    /* translators: 1: "Good" or "Needs work", 2: what the note is about */
                    _x( '%1$s — %2$s', 'note mark option', 'talenttrack' ),
                    (string) $label,
                    $context
                ) )
            );
        }

        // Rendered last, hidden by CSS while it is the selected option: an
        // offer to clear what is already clear is noise.
        $clear_id = $id . '-none';
        printf(
            '<input type="radio" class="tt-ma__valence-input tt-ma__valence-input--none" id="%1$s" name="%2$s[valence]" value=""%3$s aria-label="%4$s" />'
            . '<label class="tt-ma__valence-clear" for="%1$s" aria-hidden="true">%5$s</label>',
            esc_attr( $clear_id ),
            esc_attr( $name_base ),
            checked( $current, '', false ),
            esc_attr( sprintf(
                /* translators: %s: what the note is about */
                __( 'No mark — %s', 'talenttrack' ),
                $context
            ) ),
            esc_html_x( '×', 'clear the mark on a note', 'talenttrack' )
        );

        echo '</span>';
    }

    /**
     * The key, stated once per group of notes.
     *
     * The controls carry bare signs, so the page has to say somewhere what
     * + and − mean — once, above the phases, in the same place
     * `SectionRatingControl::renderLegend()` states the triangles.
     */
    public static function renderLegend(): void {
        $glyphs = MatchAnalysisEnums::valenceGlyphs();

        echo '<p class="tt-ma__valence-legend">';
        foreach ( MatchAnalysisEnums::valences() as $value => $label ) {
            printf(
                '<span class="tt-ma__valence-legend-item" data-valence="%1$s">'
                . '<span class="tt-ma__valence-glyph" aria-hidden="true">%2$s</span> %3$s</span>',
                esc_attr( (string) $value ),
                esc_html( $glyphs[ (string) $value ] ?? '' ),
                esc_html( (string) $label )
            );
        }
        echo '</p>';
    }
}
