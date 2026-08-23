<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\LessonMarkdown;

/**
 * CheckBlock — a question in the middle of the prose, answered on the spot.
 *
 *     ```tt-check answer="B" prompt="Ajax speelde zaterdag. Kan 4v4 op dinsdag?"
 *     - A. Ja, twee dagen is genoeg
 *     - B. Nee, 4v4 vraagt 72 uur herstel
 *     - C. Alleen als de wedstrijd kort was
 *     > 4v4 is de meest intensieve vorm en vraagt drie dagen. Dinsdag ligt
 *     > 72 uur na zaterdagochtend, niet na een middagwedstrijd.
 *     ```
 *
 * ## Why this exists next to `tt-quiz` and `tt-reveal`
 *
 * The lesson already ends in a quiz, and that is the wrong place to find
 * out you misread paragraph two. `tt-reveal` sits in the right place but
 * asks for nothing: a `<details>` opens whether or not the reader thought
 * about it, and most will just open it. Committing to an answer *before*
 * seeing the right one is the entire mechanism of retrieval practice, and
 * neither of the other two blocks makes the reader commit.
 *
 * So: one question, three or four options, an immediate verdict, and the
 * explanation either way. It interrupts; it does not measure.
 *
 * ## Scored in the browser, deliberately — and this is not a hole in #2647
 *
 * `tt-quiz` keeps its answer key on the server because a *score* is at
 * stake: it gates the next lesson and it lands in a coach's development
 * record. None of that is true here. A check records nothing, unlocks
 * nothing and appears in no report, so there is no result to protect — and
 * what a formative check needs instead is a verdict with no network round
 * trip, on a phone in a changing room with one bar of signal.
 *
 * A reader determined to read the answer out of the DOM has, at worst,
 * skipped a learning exercise they were free to skip anyway by scrolling
 * past it. The graded check three screens later is unaffected.
 *
 * ## No JavaScript, no problem
 *
 * The options are radio inputs inside a `<details>`; with the script blocked
 * the reader picks one, opens the disclosure and reads the answer — the
 * `tt-reveal` behaviour, which is the correct degradation. The script
 * upgrades it to an instant per-option verdict.
 */
final class CheckBlock implements BlockRenderer {

    public static function name(): string {
        return 'tt-check';
    }

    public static function isInteractive(): bool {
        return true;
    }

    public static function render( array $attrs, string $body ): string {
        $prompt = trim( $attrs['prompt'] ?? '' );
        $answer = strtoupper( trim( $attrs['answer'] ?? '' ) );

        [ $options, $explanation ] = self::parse( $body );

        if ( $prompt === '' || count( $options ) < 2 || $answer === '' ) {
            // A malformed check renders as plain prose rather than a broken
            // control. `course-lint` is what stops one reaching a release;
            // this is the runtime half of the same forgiveness the fence
            // resolver applies to an unknown block.
            return '<div class="tt-lesson-check tt-lesson-check--malformed">'
                . LessonMarkdown::renderProse( $body )
                . '</div>';
        }

        $id = 'tt-check-' . wp_unique_id();

        $html  = '<section class="tt-lesson-check" data-tt-block="check"'
            . ' data-tt-answer="' . esc_attr( $answer ) . '">';
        $html .= '<p class="tt-lesson-check__label">'
            . esc_html__( 'Quick check', 'talenttrack' ) . '</p>';
        $html .= '<p class="tt-lesson-check__prompt" id="' . esc_attr( $id ) . '-prompt">'
            . esc_html( $prompt ) . '</p>';

        $html .= '<div class="tt-lesson-check__options" role="radiogroup"'
            . ' aria-labelledby="' . esc_attr( $id ) . '-prompt">';

        foreach ( $options as $key => $label ) {
            $option_id = $id . '-' . strtolower( $key );

            $html .= '<label class="tt-lesson-check__option" for="' . esc_attr( $option_id ) . '"'
                . ' data-tt-option="' . esc_attr( $key ) . '">';
            $html .= '<input type="radio" id="' . esc_attr( $option_id ) . '"'
                . ' name="' . esc_attr( $id ) . '"'
                . ' value="' . esc_attr( $key ) . '" />';
            $html .= '<span class="tt-lesson-check__key">' . esc_html( $key ) . '</span>';
            $html .= '<span class="tt-lesson-check__text">' . esc_html( $label ) . '</span>';
            $html .= '</label>';
        }

        $html .= '</div>';

        // `role="status"` rather than `alert`: the verdict is expected
        // feedback to something the reader just did, not an interruption.
        $html .= '<p class="tt-lesson-check__verdict" data-tt-check-verdict role="status"></p>';

        $html .= '<details class="tt-lesson-check__why" data-tt-check-why>';
        $html .= '<summary>' . esc_html__( 'Why?', 'talenttrack' ) . '</summary>';
        $html .= '<div class="tt-lesson-check__explanation">'
            . LessonMarkdown::renderProse( $explanation ) . '</div>';
        $html .= '</details>';

        $html .= '</section>';

        return $html;
    }

    /**
     * Split the body into lettered options and the explanation.
     *
     * Options are `- A. text` list items; the explanation is the blockquote
     * that follows. Both are ordinary markdown, so a check reads as a
     * question with an answer even to somebody looking at the raw corpus in
     * a diff — which is the point of keeping the source in markdown.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private static function parse( string $body ): array {
        $options     = [];
        $explanation = [];

        foreach ( preg_split( '/\R/', $body ) ?: [] as $line ) {
            if ( preg_match( '/^\s*[-*]\s*([A-Za-z])[.)]\s+(.+)$/', $line, $m ) ) {
                $options[ strtoupper( $m[1] ) ] = trim( $m[2] );
                continue;
            }

            if ( preg_match( '/^\s*>\s?(.*)$/', $line, $m ) ) {
                $explanation[] = $m[1];
                continue;
            }
        }

        return [ $options, trim( implode( "\n", $explanation ) ) ];
    }

    /**
     * The option keys and the declared answer, for `course-lint`.
     *
     * Exposed so the gate can assert the answer names an option that
     * exists, without the lint re-implementing the body grammar and
     * drifting from it.
     *
     * @return array{answer: string, options: list<string>, prompt: string, explanation: string}
     */
    public static function inspect( array $attrs, string $body ): array {
        [ $options, $explanation ] = self::parse( $body );

        return [
            'answer'      => strtoupper( trim( $attrs['answer'] ?? '' ) ),
            'options'     => array_keys( $options ),
            'prompt'      => trim( $attrs['prompt'] ?? '' ),
            'explanation' => $explanation,
        ];
    }
}
