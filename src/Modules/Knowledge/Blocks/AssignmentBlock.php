<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\LessonMarkdown;

/**
 * AssignmentBlock — the practical assignment, and later the place to hand
 * it in.
 *
 *     ```tt-assignment id="04-nulpunt"
 *     **Praktijkopdracht 4**
 *
 *     Voer met je eigen team twee nulpuntmetingen uit …
 *     ```
 *
 * The assignment text renders in full today; the submission form and the
 * reviewer's verdict arrive with #2648. The `id` is written into the
 * markup now because it becomes the `lesson_slug`-scoped key a submission
 * is stored against, and changing an id later would orphan work a coach
 * had already handed in.
 *
 * These assignments are the reason the course has a review queue at all.
 * A quiz can establish that a coach knows 4v4 needs seventy-two hours; only
 * a mentor reading a submitted twelve-week plan can establish that they
 * built one.
 */
final class AssignmentBlock implements BlockRenderer {

    public static function name(): string {
        return 'tt-assignment';
    }

    public static function isInteractive(): bool {
        return false;
    }

    public static function render( array $attrs, string $body ): string {
        $id = trim( $attrs['id'] ?? '' );

        return sprintf(
            '<section class="tt-lesson-assignment"%1$s>'
            . '<p class="tt-lesson-assignment__label">%2$s</p>'
            . '<div class="tt-lesson-assignment__body">%3$s</div>'
            . '<p class="tt-lesson-assignment__note">%4$s</p>'
            . '</section>',
            $id !== '' ? ' data-tt-assignment="' . esc_attr( $id ) . '"' : '',
            esc_html__( 'Practical assignment', 'talenttrack' ),
            LessonMarkdown::renderProse( $body ),
            esc_html__( 'Work this through with your own team. Handing it in for review opens once progress tracking is switched on.', 'talenttrack' )
        );
    }
}
