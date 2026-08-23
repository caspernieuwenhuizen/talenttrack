<?php
namespace TT\Modules\Knowledge\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\LessonRenderer;

/**
 * KnowledgeAssets — the stylesheet and reader script for these surfaces.
 *
 * Separate from `LessonRenderer::registerAssets()`, which owns the lesson
 * body's own sheet and the interactive-block script. This is the chrome
 * around it: cards, the lesson list, the progress bar. A view that lists
 * courses needs the chrome and not the block script.
 */
final class KnowledgeAssets {

    public const STYLE_HANDLE  = 'tt-frontend-knowledge';
    public const READER_HANDLE = 'tt-knowledge-reader';
    public const QUIZ_HANDLE   = 'tt-knowledge-quiz';

    /** Chrome only — every knowledge surface. */
    public static function enqueue(): void {
        wp_enqueue_style(
            self::STYLE_HANDLE,
            TT_PLUGIN_URL . 'assets/css/frontend-knowledge.css',
            [ 'tt-tokens' ],
            TT_VERSION
        );
    }

    /**
     * The reader script: persists interactive-block state and marks a
     * lesson read without a page reload.
     *
     * Depends on the block script, because it wires into blocks that
     * script created. Enqueued only on the lesson view.
     *
     * @param array<string, mixed> $tool_state Saved state for this lesson.
     */
    public static function enqueueReader( string $course_slug, string $lesson_slug, array $tool_state ): void {
        self::enqueue();
        LessonRenderer::enqueueStyle();
        LessonRenderer::enqueueScript();

        wp_enqueue_style(
            'tt-knowledge-quiz',
            TT_PLUGIN_URL . 'assets/css/knowledge-quiz.css',
            [ self::STYLE_HANDLE ],
            TT_VERSION
        );

        wp_enqueue_script(
            self::READER_HANDLE,
            TT_PLUGIN_URL . 'assets/js/knowledge-reader.js',
            [ LessonRenderer::SCRIPT_HANDLE ],
            TT_VERSION,
            true
        );

        // #2647 — the quiz submitter. A dependent of the reader script so
        // it can read the same localised config rather than duplicating
        // the REST root and nonce.
        wp_enqueue_script(
            self::QUIZ_HANDLE,
            TT_PLUGIN_URL . 'assets/js/knowledge-quiz.js',
            [ self::READER_HANDLE ],
            TT_VERSION,
            true
        );

        wp_localize_script( self::READER_HANDLE, 'TTKnowledgeReader', [
            'root'    => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'course'  => $course_slug,
            'lesson'  => $lesson_slug,
            // Cast so an empty state serialises as {} rather than [] —
            // the script indexes it by block key.
            'state'   => (object) $tool_state,
            'i18n'    => [
                'saving' => __( 'Saving…', 'talenttrack' ),
                'saved'  => __( 'Saved', 'talenttrack' ),
                'failed' => __( 'Could not save. Your work is still on screen; try again.', 'talenttrack' ),
                /* translators: 1: correct answers, 2: total questions */
                'quizScore'     => __( 'You answered %1$d of %2$d correctly.', 'talenttrack' ),
                'quizPassed'    => __( 'That is a pass — this lesson\'s check is done.', 'talenttrack' ),
                /* translators: %d: the number of correct answers needed */
                'quizFailed'    => __( 'You need %d correct to pass. Read back over the parts below and try again.', 'talenttrack' ),
                'quizCorrect'   => __( 'Correct.', 'talenttrack' ),
                'quizWrong'     => __( 'Not quite.', 'talenttrack' ),
                /* translators: %s: the correct answer */
                'quizAnswer'    => __( 'Answer: %s', 'talenttrack' ),
                'quizReloading' => __( 'Updating your progress…', 'talenttrack' ),
            ],
        ] );
    }
}
