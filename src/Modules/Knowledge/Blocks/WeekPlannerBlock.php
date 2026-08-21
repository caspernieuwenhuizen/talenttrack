<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\Periodisation;

/**
 * WeekPlannerBlock — build a week, get told what it breaks.
 *
 *     ```tt-weekplanner
 *     ```
 *
 * Seven days, a session type per day, and a live check against the
 * supercompensation table. Put 4v4 on Thursday with a Saturday match and
 * the block says so, in hours: 48 available, 72 required.
 *
 * This is the block that turns the module from something a coach reads
 * into something they argue with. The recovery rule is easy to nod at and
 * easy to break; a planner that names the violation is worth more than
 * three paragraphs that state the rule.
 *
 * Two rules are checked, both from `Periodisation`:
 *   - no overload session inside its own recovery window before a match;
 *   - no two sessions of the same exercise closer than its recovery time.
 *
 * The check runs in the script for immediacy and lives in `violations()`
 * for correctness, so #2646's REST layer can validate a saved plan with
 * the same code rather than a second implementation that drifts.
 */
final class WeekPlannerBlock implements BlockRenderer {

    public static function name(): string {
        return 'tt-weekplanner';
    }

    public static function isInteractive(): bool {
        return true;
    }

    /** Hours between two consecutive evening sessions. */
    private const HOURS_PER_DAY = 24;

    public static function render( array $attrs, string $body ): string {
        $days    = self::days();
        $types   = Periodisation::sessionTypes();
        $id      = 'tt-weekplanner-' . wp_unique_id();

        $rows = '';
        foreach ( $days as $index => $day ) {
            $options = '';
            foreach ( $types as $key => $type ) {
                $options .= sprintf(
                    '<option value="%1$s">%2$s</option>',
                    esc_attr( $key ),
                    esc_html( $type['label'] )
                );
            }

            $rows .= sprintf(
                '<div class="tt-lesson-week__day">'
                . '<label class="tt-lesson-week__label" for="%1$s-%2$d">%3$s</label>'
                . '<select class="tt-input tt-lesson-week__select" id="%1$s-%2$d" data-tt-week-day="%2$d">%4$s</select>'
                . '</div>',
                esc_attr( $id ),
                $index,
                esc_html( $day ),
                $options
            );
        }

        return sprintf(
            '<div class="tt-lesson-tool tt-lesson-week" data-tt-block="weekplanner" data-tt-persist="weekplan">'
            . '<p class="tt-lesson-tool__title">%1$s</p>'
            . '<p class="tt-lesson-tool__intro">%2$s</p>'
            . '<div class="tt-lesson-week__grid">%3$s</div>'
            . '<div class="tt-lesson-week__verdict" data-tt-week-verdict role="status">%4$s</div>'
            . '</div>',
            esc_html__( 'Week planner', 'talenttrack' ),
            esc_html__( 'Set your match day and your sessions. The plan is checked against the recovery times as you go.', 'talenttrack' ),
            $rows,
            esc_html__( 'Add a match and at least one session to check the week.', 'talenttrack' )
        );
    }

    /**
     * Recovery violations in a week plan.
     *
     * @param list<string> $plan Seven session-type keys, Monday first.
     * @return list<array{kind: string, message: string}>
     */
    public static function violations( array $plan ): array {
        $types    = Periodisation::sessionTypes();
        $recovery = Periodisation::supercompensation();
        $days     = self::days();
        $problems = [];

        $match_days = [];
        foreach ( $plan as $index => $key ) {
            if ( $key === 'match' ) {
                $match_days[] = $index;
            }
        }

        // A session too close to a following match.
        foreach ( $plan as $index => $key ) {
            $exercise = $types[ $key ]['exercise'] ?? null;
            if ( $exercise === null || ! isset( $recovery[ $exercise ] ) ) {
                continue;
            }

            $required = $recovery[ $exercise ]['max'];

            foreach ( $match_days as $match_day ) {
                if ( $match_day <= $index ) {
                    continue;
                }

                $available = ( $match_day - $index ) * self::HOURS_PER_DAY;
                if ( $available < $required ) {
                    $problems[] = [
                        'kind'    => 'before_match',
                        'message' => sprintf(
                            /* translators: 1: session label, 2: day name, 3: match day name, 4: available hours, 5: required hours */
                            __( '%1$s on %2$s, match on %3$s: %4$d hours available, %5$d needed.', 'talenttrack' ),
                            $types[ $key ]['label'],
                            $days[ $index ],
                            $days[ $match_day ],
                            $available,
                            $required
                        ),
                    ];
                }
            }
        }

        // Two sessions of the same exercise inside its recovery window.
        $last_seen = [];
        foreach ( $plan as $index => $key ) {
            $exercise = $types[ $key ]['exercise'] ?? null;
            if ( $exercise === null || ! isset( $recovery[ $exercise ] ) ) {
                continue;
            }

            if ( isset( $last_seen[ $exercise ] ) ) {
                $gap      = ( $index - $last_seen[ $exercise ] ) * self::HOURS_PER_DAY;
                $required = $recovery[ $exercise ]['max'];

                if ( $gap < $required ) {
                    $problems[] = [
                        'kind'    => 'repeat',
                        'message' => sprintf(
                            /* translators: 1: session label, 2: first day, 3: second day, 4: gap in hours, 5: required hours */
                            __( '%1$s on %2$s and again on %3$s: %4$d hours apart, %5$d needed between two of the same stimulus.', 'talenttrack' ),
                            $types[ $key ]['label'],
                            $days[ $last_seen[ $exercise ] ],
                            $days[ $index ],
                            $gap,
                            $required
                        ),
                    ];
                }
            }

            $last_seen[ $exercise ] = $index;
        }

        return $problems;
    }

    /**
     * Weekday names, Monday first.
     *
     * Not `wp_locale`'s list: that starts on the site's configured start
     * of week, which an operator may well have set to Sunday. A training
     * week that starts on a different day than the model describes would
     * make every day index in the course wrong.
     *
     * @return list<string>
     */
    public static function days(): array {
        return [
            __( 'Monday', 'talenttrack' ),
            __( 'Tuesday', 'talenttrack' ),
            __( 'Wednesday', 'talenttrack' ),
            __( 'Thursday', 'talenttrack' ),
            __( 'Friday', 'talenttrack' ),
            __( 'Saturday', 'talenttrack' ),
            __( 'Sunday', 'talenttrack' ),
        ];
    }
}
