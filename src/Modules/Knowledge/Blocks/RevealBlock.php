<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\LessonMarkdown;

/**
 * RevealBlock — a question the reader answers in their head first.
 *
 *     ```tt-reveal question="Waarom is 'de speler moet fitter' geen doel?"
 *     Omdat het niet zegt wát er moet veranderen …
 *     ```
 *
 * Unscored on purpose. The quiz measures; this one interrupts. A reader
 * who has committed to an answer before seeing it reads the explanation
 * differently from one who has only nodded along, and that is the whole
 * mechanism — nothing is recorded and nothing unlocks.
 *
 * Built on `<details>`, so it works with no JavaScript, is keyboard
 * operable for free, and is searchable by the browser's find-in-page in
 * every engine that expands closed details on match.
 */
final class RevealBlock implements BlockRenderer {

    public static function name(): string {
        return 'tt-reveal';
    }

    public static function isInteractive(): bool {
        return false;
    }

    public static function render( array $attrs, string $body ): string {
        $question = trim( $attrs['question'] ?? '' );

        if ( $question === '' ) {
            $question = __( 'Think it through, then check', 'talenttrack' );
        }

        return sprintf(
            '<details class="tt-lesson-reveal"><summary class="tt-lesson-reveal__question">%1$s</summary><div class="tt-lesson-reveal__answer">%2$s</div></details>',
            esc_html( $question ),
            LessonMarkdown::renderProse( $body )
        );
    }
}
