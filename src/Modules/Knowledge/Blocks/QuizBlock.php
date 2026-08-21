<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * QuizBlock — where the lesson's quiz appears.
 *
 *     ```tt-quiz
 *     ```
 *
 * A placeholder, deliberately. Scoring belongs to #2647, and it belongs
 * server-side: the payload in `quizzes/<lesson>.json` carries the answer
 * key, and a block that rendered the questions client-side would ship that
 * key to the browser. So this renders the promise and the stakes — how
 * many questions, what the pass mark is — and nothing that could be read
 * off the page.
 *
 * Rendering it now rather than leaving a gap means the corpus can be
 * written and reviewed complete, and the lesson reads as finished on the
 * day #2647 fills it in.
 */
final class QuizBlock implements BlockRenderer {

    public static function name(): string {
        return 'tt-quiz';
    }

    public static function isInteractive(): bool {
        return false;
    }

    public static function render( array $attrs, string $body ): string {
        return sprintf(
            '<div class="tt-lesson-quiz tt-lesson-quiz--pending" data-tt-block="quiz">'
            . '<p class="tt-lesson-quiz__title">%1$s</p>'
            . '<p class="tt-lesson-quiz__note">%2$s</p>'
            . '</div>',
            esc_html__( 'Check your understanding', 'talenttrack' ),
            esc_html__( 'The questions for this module open once progress tracking is switched on.', 'talenttrack' )
        );
    }
}
