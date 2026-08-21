<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LoadMatrixBlock — the 100 / 50 / 0 pattern across a season block.
 *
 *     ```tt-loadmatrix cycle="6" cycles="2"
 *     ```
 *
 * Each conditioning game format runs the same three-phase pattern, offset
 * from its neighbours: two weeks of underload as preparation, two of
 * overload, two of taper while the training effect develops. Written out
 * as a table it is a wall of numbers; laid out as a grid the offset is the
 * first thing you see, and the offset is the point.
 *
 * Recomputes for a three-week cycle, which is what a team with regular
 * midweek fixtures actually runs. Being able to flip between the two and
 * watch the taper disappear is a better argument for the six-week model
 * than the paragraph that precedes it.
 */
final class LoadMatrixBlock implements BlockRenderer {

    public static function name(): string {
        return 'tt-loadmatrix';
    }

    public static function isInteractive(): bool {
        return true;
    }

    /** The three game formats, in the order the model introduces them. */
    private const FORMATS = [ 'games_large', 'games_medium', 'games_small' ];

    public static function render( array $attrs, string $body ): string {
        $cycle  = max( 3, min( 6, (int) ( $attrs['cycle'] ?? 6 ) ) );
        $cycles = max( 1, min( 4, (int) ( $attrs['cycles'] ?? 2 ) ) );

        return sprintf(
            '<div class="tt-lesson-tool tt-lesson-matrix" data-tt-block="loadmatrix" data-tt-cycle="%1$d" data-tt-cycles="%2$d">'
            . '<p class="tt-lesson-tool__title">%3$s</p>'
            . '<div class="tt-lesson-tool__controls">'
            . '<fieldset class="tt-lesson-matrix__toggle">'
            . '<legend class="tt-lesson-tool__label">%4$s</legend>'
            . '<label><input type="radio" name="%5$s" value="6" %6$s data-tt-matrix-cycle> %7$s</label>'
            . '<label><input type="radio" name="%5$s" value="3" %8$s data-tt-matrix-cycle> %9$s</label>'
            . '</fieldset>'
            . '</div>'
            . '<div class="tt-lesson-matrix__scroll" data-tt-matrix-target>%10$s</div>'
            . '<p class="tt-lesson-tool__legend">%11$s</p>'
            . '</div>',
            $cycle,
            $cycles,
            esc_html__( 'Load pattern', 'talenttrack' ),
            esc_html__( 'Cycle length', 'talenttrack' ),
            esc_attr( 'tt-matrix-' . wp_unique_id() ),
            $cycle === 6 ? 'checked' : '',
            esc_html__( 'Six weeks', 'talenttrack' ),
            $cycle === 3 ? 'checked' : '',
            esc_html__( 'Three weeks', 'talenttrack' ),
            self::table( $cycle, $cycles ),
            esc_html__( '100 = overload · 50 = underload · 0 = taper', 'talenttrack' )
        );
    }

    /**
     * The matrix as a table.
     *
     * Rendered server-side for the default cycle so the block is readable
     * without the script, and re-rendered client-side on toggle. The two
     * must agree, which is why the load rule below is one expression
     * rather than a hand-written grid.
     */
    public static function table( int $cycle, int $cycles ): string {
        $labels = [
            'games_large'  => __( '11v11 – 8v8', 'talenttrack' ),
            'games_medium' => __( '7v7 – 5v5', 'talenttrack' ),
            'games_small'  => __( '4v4 – 3v3', 'talenttrack' ),
        ];

        $weeks = $cycle * $cycles;

        $head = '<th scope="col">' . esc_html__( 'Exercise', 'talenttrack' ) . '</th>';
        for ( $week = 1; $week <= $weeks; $week++ ) {
            $head .= '<th scope="col">' . (int) $week . '</th>';
        }

        $rows = '';
        foreach ( self::FORMATS as $index => $format ) {
            $cells = '<th scope="row">' . esc_html( $labels[ $format ] ) . '</th>';

            for ( $week = 0; $week < $weeks; $week++ ) {
                $load   = self::loadFor( $index, $week, $cycle );
                $cells .= sprintf(
                    '<td class="tt-lesson-matrix__cell tt-lesson-matrix__cell--%1$d">%1$d</td>',
                    $load
                );
            }

            $rows .= '<tr>' . $cells . '</tr>';
        }

        return sprintf(
            '<table class="tt-lesson-matrix__table"><thead><tr>%s</tr></thead><tbody>%s</tbody></table>',
            $head,
            $rows
        );
    }

    /**
     * Load for one format in one week: 100, 50 or 0.
     *
     * Each format owns a slot of the cycle — the large games first, then
     * the medium, then the small. Inside its own slot it is at overload;
     * in the slot before, it is being prepared at underload; everywhere
     * else it is tapering.
     *
     * Expressed as arithmetic rather than a literal grid so the six-week
     * and three-week variants cannot disagree with each other, and so a
     * four-week variant needs no new code.
     *
     * @param int $index Position of the format in the model, 0-based.
     * @param int $week  Week within the whole span, 0-based.
     * @param int $cycle Cycle length in weeks.
     */
    public static function loadFor( int $index, int $week, int $cycle ): int {
        $slots     = count( self::FORMATS );
        $slot_size = max( 1, (int) round( $cycle / $slots ) );

        $slot_in_cycle = intdiv( $week % $cycle, $slot_size );
        $slot_in_cycle = min( $slot_in_cycle, $slots - 1 );

        if ( $slot_in_cycle === $index ) {
            return 100;
        }

        // The slot immediately before this format's own is its preparation.
        if ( $slot_in_cycle === ( $index - 1 + $slots ) % $slots ) {
            return 50;
        }

        return 0;
    }
}
