<?php
namespace TT\Modules\MatchAnalysis\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\MatchAnalysisEnums;

/**
 * SectionRatingControl — how a phase went, as a line on the phase's own
 * heading.
 *
 * ## Why it moved off its own row
 *
 * It shipped as four full-width pills: white fill, thin outline, 48px tall
 * — the styling of the bullet inputs directly beneath them. The eye read a
 * row of empty fields, not a choice. Worse, the selected state was always
 * green, so "Moet beter" lit up in the colour of good news.
 *
 * The rating is a one-word judgement and the bullets are where the coach's
 * thinking actually goes. Giving it an equal-weight row said otherwise. So
 * it sits beside the phase name, sized to its words, and the card reads
 * "Aanvallen — ▲ Ging goed" at a glance, which is how the printed sheet
 * and the share page read it too.
 *
 * ## Not rated is the absence of a choice
 *
 * There is no permanent "Niet beoordeeld" target taking up a quarter of the
 * control. Nothing selected means nothing to say — the resting state of
 * most phases — and a **Clear** option appears only once something is set.
 * It is still a real radio, so this needs no JavaScript and the group keeps
 * its four values; CSS hides it while it is the selected one, because an
 * offer to clear what is already clear is noise.
 *
 * The glyphs (▲ ● ▼) are the roster's, deliberately: one visual language
 * for "how did this go" across the whole page, and shape carrying the
 * meaning where colour cannot.
 */
final class SectionRatingControl {

    /**
     * @param string  $section_key one of `MatchAnalysisEnums::sectionKeys()`
     * @param string  $label       the phase's translated name
     * @param ?string $current     the stored rating, or null
     * @param string  $id_prefix   keeps element ids unique when the wizard
     *                             and the flat surface render on one page
     */
    public static function render( string $section_key, string $label, ?string $current, string $id_prefix = 'ma' ): void {
        $glyphs  = self::glyphs();
        $current = (string) ( $current ?? '' );

        printf(
            '<div class="tt-ma__ratings" role="radiogroup" aria-label="%s">',
            esc_attr( sprintf(
                /* translators: %s: section name, e.g. Aanvallen */
                __( 'Rating — %s', 'talenttrack' ),
                $label
            ) )
        );

        foreach ( MatchAnalysisEnums::ratings() as $value => $option_label ) {
            $id = 'tt-' . $id_prefix . '-' . sanitize_key( $section_key ) . '-' . sanitize_key( (string) $value );
            // #2836 — the glyph alone. With five phases on a step the three
            // words were printed fifteen times, which made the phase name —
            // the only thing that differs between rows — the smallest text on
            // the card. The word moves to the accessible name and to the
            // legend {@see renderLegend()}, so nothing is lost to a screen
            // reader and the vocabulary is still stated on the page.
            printf(
                '<input type="radio" class="tt-ma__rating-input" id="%1$s" name="sections[%2$s][rating]" value="%3$s"%4$s />'
                . '<label class="tt-ma__rating tt-ma__rating--glyph" for="%1$s" data-rating="%3$s" title="%6$s" aria-label="%6$s">'
                . '<span class="tt-ma__glyph" aria-hidden="true">%5$s</span></label>',
                esc_attr( $id ),
                esc_attr( $section_key ),
                esc_attr( (string) $value ),
                checked( $current, (string) $value, false ),
                esc_html( $glyphs[ (string) $value ] ?? '' ),
                esc_attr( (string) $option_label )
            );
        }

        // The unrated option, rendered last and styled as a quiet link.
        // Its accessible name says what it means; the visible word is what
        // the coach is actually doing.
        $clear_id = 'tt-' . $id_prefix . '-' . sanitize_key( $section_key ) . '-none';
        printf(
            '<input type="radio" class="tt-ma__rating-input tt-ma__rating-input--none" id="%1$s" name="sections[%2$s][rating]" value=""%3$s aria-label="%4$s" />'
            . '<label class="tt-ma__rating-clear" for="%1$s">%5$s</label>',
            esc_attr( $clear_id ),
            esc_attr( $section_key ),
            checked( $current, '', false ),
            esc_attr__( 'Clear — not rated', 'talenttrack' ),
            esc_html__( 'Clear', 'talenttrack' )
        );

        echo '</div>';
    }

    /**
     * The vocabulary, stated once for a group of sections (#2836).
     *
     * The pills carry glyphs alone, so the page has to say somewhere what
     * ▲ ● ▼ mean. Once, on the line that introduces the phases — not on
     * every phase, which is the repetition this replaced.
     *
     * Rendered by the caller that owns the group heading: the two wizard
     * steps and the flat surface, each above their own set of phases.
     */
    public static function renderLegend(): void {
        $glyphs = self::glyphs();

        echo '<p class="tt-ma__rating-legend">';
        foreach ( MatchAnalysisEnums::ratings() as $value => $label ) {
            printf(
                '<span class="tt-ma__rating-legend-item" data-rating="%1$s">'
                . '<span class="tt-ma__glyph" aria-hidden="true">%2$s</span> %3$s</span>',
                esc_attr( (string) $value ),
                esc_html( $glyphs[ (string) $value ] ?? '' ),
                esc_html( (string) $label )
            );
        }
        echo '</p>';
    }

    /**
     * Glyph per rating, matching the roster's marker glyphs. Shape as well
     * as colour, so the control still reads for anyone who cannot separate
     * the green fill from the amber one.
     *
     * @return array<string,string>
     */
    public static function glyphs(): array {
        return [
            ''                                    => '—',
            MatchAnalysisEnums::RATING_WENT_WELL   => '▲',
            MatchAnalysisEnums::RATING_MIXED       => '●',
            MatchAnalysisEnums::RATING_NEEDS_WORK  => '▼',
        ];
    }
}
