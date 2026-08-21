<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\Periodisation;

/**
 * ZeroPointBlock — measured minutes in, overload step out.
 *
 *     ```tt-zeropoint method="extensive_endurance"
 *     ```
 *
 * The highest-value tool in the course. A coach runs the measurement on
 * the pitch — blocks of ten minutes until the majority visibly drop their
 * action count — and arrives holding a number of minutes. This turns that
 * number into the step their next twelve weeks start from.
 *
 * Without it, the step is guessed, and a guessed step is the difference
 * between overload and either injury or nothing happening at all.
 *
 * The result is meant to persist onto the reader's enrolment
 * (`tt_course_progress.tool_state`) so the eleventh lesson can read back
 * what the fourth measured. That table arrives in #2644 and the write path
 * in #2646; until then the block computes and displays, and the markup
 * already carries the `data-tt-persist` key those waves will read.
 */
final class ZeroPointBlock implements BlockRenderer {

    public static function name(): string {
        return 'tt-zeropoint';
    }

    public static function isInteractive(): bool {
        return true;
    }

    public static function render( array $attrs, string $body ): string {
        $methods = Periodisation::overloadSteps();
        $default = $attrs['method'] ?? '';

        if ( ! isset( $methods[ $default ] ) ) {
            $default = (string) array_key_first( $methods );
        }

        $options = '';
        foreach ( $methods as $key => $method ) {
            $options .= sprintf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr( $key ),
                $key === $default ? ' selected' : '',
                esc_html( $method['label'] )
            );
        }

        $id = 'tt-zeropoint-' . wp_unique_id();

        return sprintf(
            '<div class="tt-lesson-tool tt-lesson-zeropoint" data-tt-block="zeropoint" data-tt-persist="zeropoint">'
            . '<p class="tt-lesson-tool__title">%1$s</p>'
            . '<p class="tt-lesson-tool__intro">%2$s</p>'
            . '<div class="tt-lesson-tool__controls">'
            . '<div class="tt-field">'
            . '<label class="tt-lesson-tool__label" for="%3$s-method">%4$s</label>'
            . '<select class="tt-input tt-lesson-tool__select" id="%3$s-method" data-tt-zeropoint-method>%5$s</select>'
            . '</div>'
            . '<div class="tt-field">'
            . '<label class="tt-lesson-tool__label" for="%3$s-minutes">%6$s</label>'
            . '<input class="tt-input tt-lesson-tool__number" id="%3$s-minutes" type="number" inputmode="numeric" autocomplete="off" min="0" max="120" step="1" data-tt-zeropoint-minutes>'
            . '</div>'
            . '</div>'
            . '<output class="tt-lesson-tool__output" data-tt-zeropoint-output role="status">%7$s</output>'
            . '</div>',
            esc_html__( 'Zero-point measurement', 'talenttrack' ),
            esc_html__( 'Enter the minutes your team played before the majority visibly dropped their action count.', 'talenttrack' ),
            esc_attr( $id ),
            esc_html__( 'Training method', 'talenttrack' ),
            $options,
            esc_html__( 'Minutes played', 'talenttrack' ),
            esc_html__( 'Enter the minutes to see your starting step.', 'talenttrack' )
        );
    }

    /**
     * Resolve minutes to a step. Shared with the script through the
     * localised `Periodisation::forScript()` payload, and kept here so the
     * REST layer in #2646 has a server-side answer that cannot disagree
     * with what the reader was shown.
     *
     * Returns the highest step whose total does not exceed the measurement
     * — a team that managed 25 minutes has completed step 3 (24 minutes),
     * not step 4. Below the first step, returns step 1: a team that could
     * not finish the lightest load still has to start somewhere, and
     * starting below the table is not a thing the methodology offers.
     *
     * @return array{step: int, games: int, minutes: float, total: float}|null
     */
    public static function resolveStep( string $method, float $measured ): ?array {
        $methods = Periodisation::overloadSteps();
        if ( ! isset( $methods[ $method ] ) || $measured <= 0 ) {
            return null;
        }

        $found = null;
        foreach ( $methods[ $method ]['steps'] as $step ) {
            if ( $step['total'] <= $measured ) {
                $found = $step;
            }
        }

        return $found ?? $methods[ $method ]['steps'][0];
    }
}
