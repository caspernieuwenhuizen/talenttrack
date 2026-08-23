<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\LessonContext;
use TT\Modules\Knowledge\Quiz\QuizPayload;

/**
 * QuizBlock — the lesson's check.
 *
 *     ```tt-quiz
 *     ```
 *
 * Renders the questions and nothing else: no answer key, no explanations.
 * Both arrive only in the response to a submission, from `QuizScorer`
 * (#2647). A reader with devtools open sees the same page a reader
 * without them does.
 *
 * Options are shuffled per render by `QuizPayload`. That matters more than
 * it sounds: every `order` and `match` answer in the shipped corpus is the
 * identity permutation, so the stored order is the correct sequence.
 *
 * Four question types, all keyboard operable and none needing a gesture:
 *
 *   single   — radio group
 *   multiple — checkbox group
 *   order    — a number input per option (1..n). Dragging is nicer with a
 *              mouse and unusable with a keyboard, and §2 requires a
 *              non-gesture path anyway; typing a position is that path
 *              rather than a fallback bolted beside one.
 *   match    — a select per left-hand item
 *
 * The block renders a real `<form>`. Without JavaScript it posts, is
 * scored, and re-renders with feedback; with it, `knowledge-quiz.js`
 * submits in place.
 */
final class QuizBlock implements BlockRenderer {

    public static function name(): string {
        return 'tt-quiz';
    }

    public static function isInteractive(): bool {
        return true;
    }

    public static function render( array $attrs, string $body ): string {
        if ( ! LessonContext::isSet() ) {
            // Rendered outside a lesson — the REST preview, or a future
            // surface that reuses the renderer. Nothing to score against.
            return self::placeholder( __( 'The questions for this module open in the lesson itself.', 'talenttrack' ) );
        }

        $payload = QuizPayload::forLesson( LessonContext::course(), LessonContext::lesson() );

        if ( $payload === null || $payload->count() === 0 ) {
            return self::placeholder( __( 'This module has no check yet.', 'talenttrack' ) );
        }

        $questions = $payload->forDisplay();
        $id        = 'tt-quiz-' . wp_unique_id();

        $html  = '<section class="tt-quiz" data-tt-block="quiz" data-tt-quiz-lesson="'
            . esc_attr( LessonContext::lesson() ) . '">';
        $html .= '<h2 class="tt-quiz__title">' . esc_html__( 'Check your understanding', 'talenttrack' ) . '</h2>';
        $html .= '<p class="tt-quiz__intro">' . esc_html( sprintf(
            /* translators: 1: number of correct answers needed, 2: total questions */
            __( 'Answer %1$d of %2$d correctly to pass. You can retake it as often as you like.', 'talenttrack' ),
            $payload->passMark(),
            $payload->count()
        ) ) . '</p>';

        $html .= '<form class="tt-quiz__form" method="post" data-tt-quiz-form>';
        $html .= wp_nonce_field( 'tt_knowledge_quiz', '_tt_quiz_nonce', true, false );
        $html .= '<input type="hidden" name="tt_knowledge_action" value="tt_knowledge_quiz" />';

        foreach ( $questions as $index => $question ) {
            $html .= self::renderQuestion( $id, $index, $question );
        }

        $html .= '<div class="tt-quiz__actions">';
        $html .= '<button type="submit" class="tt-btn tt-btn-primary" data-tt-quiz-submit>'
            . esc_html__( 'Check my answers', 'talenttrack' ) . '</button>';
        $html .= '</div>';
        $html .= '</form>';

        $html .= '<div class="tt-quiz__result" data-tt-quiz-result role="status"></div>';
        $html .= '</section>';

        return $html;
    }

    /**
     * @param array{id: string, type: string, prompt: string, pairs: list<string>, options: list<string>} $question
     */
    private static function renderQuestion( string $form_id, int $index, array $question ): string {
        $qid    = $question['id'];
        $name   = 'q[' . $qid . ']';
        $legend = sprintf(
            /* translators: 1: question number, 2: the question itself */
            __( '%1$d. %2$s', 'talenttrack' ),
            $index + 1,
            $question['prompt']
        );

        $html  = '<fieldset class="tt-quiz__question" data-tt-quiz-question="' . esc_attr( $qid ) . '">';
        $html .= '<legend class="tt-quiz__prompt">' . esc_html( $legend ) . '</legend>';

        switch ( $question['type'] ) {
            case 'multiple':
                $html .= '<p class="tt-quiz__hint">' . esc_html__( 'Select every answer that applies.', 'talenttrack' ) . '</p>';
                $html .= self::renderChoices( $form_id, $qid, $name . '[]', $question['options'], 'checkbox' );
                break;

            case 'order':
                $html .= '<p class="tt-quiz__hint">' . esc_html__( 'Number them in the right order, starting at 1.', 'talenttrack' ) . '</p>';
                $html .= self::renderOrder( $form_id, $qid, $name, $question['options'] );
                break;

            case 'match':
                $html .= self::renderMatch( $form_id, $qid, $name, $question['pairs'], $question['options'] );
                break;

            case 'single':
            default:
                $html .= self::renderChoices( $form_id, $qid, $name, $question['options'], 'radio' );
                break;
        }

        $html .= '<p class="tt-quiz__feedback" data-tt-quiz-feedback="' . esc_attr( $qid ) . '"></p>';
        $html .= '</fieldset>';

        return $html;
    }

    /**
     * Radio or checkbox group.
     *
     * The value is the option's **label**, not its index: the options were
     * shuffled for this render, so an index would describe a position the
     * server has already forgotten.
     *
     * @param list<string> $options
     */
    private static function renderChoices( string $form_id, string $qid, string $name, array $options, string $type ): string {
        $html = '<ul class="tt-quiz__options">';

        foreach ( $options as $i => $option ) {
            $input_id = $form_id . '-' . $qid . '-' . $i;

            $html .= '<li class="tt-quiz__option">';
            $html .= '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $input_id ) . '"'
                . ' name="' . esc_attr( $name ) . '" value="' . esc_attr( $option ) . '" />';
            $html .= '<label for="' . esc_attr( $input_id ) . '">' . esc_html( $option ) . '</label>';
            $html .= '</li>';
        }

        $html .= '</ul>';

        return $html;
    }

    /**
     * Ordering, as a position box per option.
     *
     * `inputmode="numeric"` so a phone offers digits, and `min`/`max`
     * bound it to the real range (§2).
     *
     * @param list<string> $options
     */
    private static function renderOrder( string $form_id, string $qid, string $name, array $options ): string {
        $total = count( $options );
        $html  = '<ul class="tt-quiz__options tt-quiz__options--order">';

        foreach ( $options as $i => $option ) {
            $input_id = $form_id . '-' . $qid . '-' . $i;

            $html .= '<li class="tt-quiz__option tt-quiz__option--order">';
            $html .= '<label class="tt-quiz__order-label" for="' . esc_attr( $input_id ) . '">'
                . esc_html( $option ) . '</label>';
            $html .= '<input class="tt-input tt-quiz__order-input" type="number" inputmode="numeric"'
                . ' autocomplete="off" min="1" max="' . esc_attr( (string) $total ) . '" step="1"'
                . ' id="' . esc_attr( $input_id ) . '"'
                . ' name="' . esc_attr( $name . '[' . $option . ']' ) . '"'
                . ' aria-label="' . esc_attr( sprintf(
                    /* translators: %s: the option being placed in order */
                    __( 'Position of: %s', 'talenttrack' ),
                    $option
                ) ) . '" />';
            $html .= '</li>';
        }

        $html .= '</ul>';

        return $html;
    }

    /**
     * Matching, as one select per left-hand item.
     *
     * @param list<string> $pairs
     * @param list<string> $options
     */
    private static function renderMatch( string $form_id, string $qid, string $name, array $pairs, array $options ): string {
        $html = '<ul class="tt-quiz__options tt-quiz__options--match">';

        foreach ( $pairs as $i => $pair ) {
            $input_id = $form_id . '-' . $qid . '-' . $i;

            $html .= '<li class="tt-quiz__option tt-quiz__option--match">';
            $html .= '<label class="tt-quiz__match-label" for="' . esc_attr( $input_id ) . '">'
                . esc_html( $pair ) . '</label>';
            $html .= '<select class="tt-input tt-quiz__match-select" id="' . esc_attr( $input_id ) . '"'
                . ' name="' . esc_attr( $name . '[]' ) . '">';
            $html .= '<option value="">' . esc_html__( 'Choose…', 'talenttrack' ) . '</option>';

            foreach ( $options as $option ) {
                $html .= '<option value="' . esc_attr( $option ) . '">' . esc_html( $option ) . '</option>';
            }

            $html .= '</select>';
            $html .= '</li>';
        }

        $html .= '</ul>';

        return $html;
    }

    private static function placeholder( string $message ): string {
        return sprintf(
            '<div class="tt-quiz tt-quiz--pending"><p class="tt-quiz__title">%1$s</p><p class="tt-quiz__note">%2$s</p></div>',
            esc_html__( 'Check your understanding', 'talenttrack' ),
            esc_html( $message )
        );
    }
}
