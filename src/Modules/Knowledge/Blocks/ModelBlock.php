<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\Periodisation;

/**
 * ModelBlock — the six-week model as three phases you can open.
 *
 *     ```tt-model
 *     ```
 *
 * Takes no arguments: there is one model and it comes from
 * `Periodisation::model()`. A course that wanted to teach a different one
 * would be teaching a different methodology, and that is a methodology-set
 * question (#2316), not a block attribute.
 *
 * Each phase shows its exercises closed and its training methods when
 * opened, so the reader meets the shape first and the parameters second.
 * Built on `<details>` for the same reasons as the reveal block: no
 * JavaScript, keyboard operable, and open by default is one attribute away
 * if that turns out to read better.
 */
final class ModelBlock implements BlockRenderer {

    public static function name(): string {
        return 'tt-model';
    }

    public static function isInteractive(): bool {
        return false;
    }

    public static function render( array $attrs, string $body ): string {
        $html = '<div class="tt-lesson-model">';

        foreach ( Periodisation::model() as $phase ) {
            $exercises = '';
            foreach ( $phase['exercises'] as $exercise ) {
                $exercises .= '<li>' . esc_html( $exercise ) . '</li>';
            }

            $methods = '';
            foreach ( $phase['methods'] as $method ) {
                $methods .= '<li>' . esc_html( $method ) . '</li>';
            }

            $html .= sprintf(
                '<details class="tt-lesson-model__phase tt-lesson-model__phase--%1$s">'
                . '<summary class="tt-lesson-model__weeks">%2$s</summary>'
                . '<ul class="tt-lesson-model__exercises">%3$s</ul>'
                . '<p class="tt-lesson-model__methods-label">%4$s</p>'
                . '<ul class="tt-lesson-model__methods">%5$s</ul>'
                . '</details>',
                esc_attr( $phase['accent'] ),
                esc_html( $phase['weeks'] ),
                $exercises,
                esc_html__( 'Training methods', 'talenttrack' ),
                $methods
            );
        }

        $html .= sprintf(
            '<p class="tt-lesson-model__axis"><span>%1$s</span><span aria-hidden="true">→</span><span>%2$s</span></p>',
            esc_html__( 'Volume · quantity', 'talenttrack' ),
            esc_html__( 'Intensity · quality', 'talenttrack' )
        );

        $html .= '</div>';

        return $html;
    }
}
