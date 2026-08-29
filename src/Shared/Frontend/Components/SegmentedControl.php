<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SegmentedControl (#2822) — a mode switcher, not a tab strip.
 *
 * `RecordSpine` renders the tabs that move between facets of **one record**
 * (CLAUDE.md §5c). A mode switcher is a different thing: it changes what the
 * screen is showing rather than which part of a record you are looking at,
 * and on the surfaces that need one there is no record at all. Putting those
 * on `RecordSpine` would stretch §5c to cover something it was not written
 * for, and would leave the next reader believing those surfaces are
 * record-scoped when they are not.
 *
 * So they get this instead: one shared control, meeting the same 48px floor
 * and the same keyboard behaviour as the spine, so two surfaces stop
 * hand-rolling the same switcher.
 *
 * Deliberately **not** `role="tablist"`. The options navigate to different
 * URLs; a screen reader should hear a list of links, and the arrow-key
 * contract a tablist promises does not hold across a page load.
 *
 *     SegmentedControl::render( [
 *         'label'   => __( 'Grid', 'talenttrack' ),
 *         'options' => [
 *             [ 'label' => __( 'Attendance', 'talenttrack' ), 'current' => true ],
 *             [ 'label' => __( 'Minutes', 'talenttrack' ), 'url' => $minutes_url ],
 *         ],
 *     ] );
 */
final class SegmentedControl {

    /**
     * @param array{
     *   label?: string,
     *   options?: list<array{label?: string, url?: string, current?: bool}>
     * } $config
     */
    public static function render( array $config ): void {
        $options = is_array( $config['options'] ?? null ) ? $config['options'] : [];
        if ( $options === [] ) {
            return;
        }

        $label = trim( (string) ( $config['label'] ?? '' ) );

        echo '<nav class="tt-segmented"'
            . ( $label !== '' ? ' aria-label="' . esc_attr( $label ) . '"' : '' ) . '>';

        foreach ( $options as $option ) {
            $text = trim( (string) ( $option['label'] ?? '' ) );
            if ( $text === '' ) continue;

            $url = trim( (string) ( $option['url'] ?? '' ) );
            if ( ! empty( $option['current'] ) || $url === '' ) {
                // The current option is not a link — there is nowhere to go.
                echo '<span class="tt-segmented__opt is-on" aria-current="page">' . esc_html( $text ) . '</span>';
                continue;
            }
            echo '<a class="tt-segmented__opt" href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
        }

        echo '</nav>';
    }
}
