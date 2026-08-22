<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\Periodisation;

/**
 * PitchSizeBlock — outfield players in, pitch dimensions out.
 *
 *     ```tt-pitchsize
 *     ```
 *
 * The rule of thumb is 10 by 6 metres per outfield player, and a coach can
 * do that arithmetic. What the calculator adds is the exception: below 7v7
 * the computed width comes out narrower than a penalty area, and a pitch
 * that narrow quietly turns an intensive endurance session into an
 * extensive interval one. The block shows the computed figure and the
 * practical minimum side by side, and says which to use.
 *
 * Renders the full answer server-side for the default selection, so the
 * block is useful before the script runs and remains useful if it never
 * does.
 */
final class PitchSizeBlock implements BlockRenderer {

    public static function name(): string {
        return 'tt-pitchsize';
    }

    public static function isInteractive(): bool {
        return true;
    }

    /** Format shown before the reader chooses one. */
    private const DEFAULT_FORMAT = '7v7';

    public static function render( array $attrs, string $body ): string {
        $sizes   = Periodisation::pitchSizes();
        $default = $attrs['format'] ?? self::DEFAULT_FORMAT;

        $options = '';
        $chosen  = null;

        foreach ( $sizes as $size ) {
            $selected = $size['format'] === $default;
            if ( $selected ) {
                $chosen = $size;
            }
            $options .= sprintf(
                '<option value="%1$s"%2$s>%1$s</option>',
                esc_attr( $size['format'] ),
                $selected ? ' selected' : ''
            );
        }

        if ( $chosen === null ) {
            $chosen = $sizes[0];
        }

        $per = Periodisation::metresPerOutfieldPlayer();

        return sprintf(
            '<div class="tt-lesson-tool tt-lesson-pitchsize" data-tt-block="pitchsize">'
            . '<p class="tt-lesson-tool__title">%1$s</p>'
            . '<div class="tt-lesson-tool__controls">'
            . '<label class="tt-lesson-tool__label" for="%2$s">%3$s</label>'
            . '<select class="tt-input tt-lesson-tool__select" id="%2$s" data-tt-pitchsize-format>%4$s</select>'
            . '</div>'
            . '<dl class="tt-lesson-tool__result">'
            . '<div><dt>%5$s</dt><dd data-tt-pitchsize-computed>%6$s</dd></div>'
            . '<div><dt>%7$s</dt><dd data-tt-pitchsize-use>%8$s</dd></div>'
            . '</dl>'
            . '<p class="tt-lesson-tool__note" data-tt-pitchsize-note>%9$s</p>'
            . '<p class="tt-lesson-tool__derivation">%10$s</p>'
            . '</div>',
            esc_html__( 'Pitch size calculator', 'talenttrack' ),
            esc_attr( 'tt-pitchsize-' . wp_unique_id() ),
            esc_html__( 'Game format', 'talenttrack' ),
            $options,
            esc_html__( 'Rule of thumb', 'talenttrack' ),
            esc_html( self::dimensions( $chosen['length'], $chosen['width'] ) ),
            esc_html__( 'Use', 'talenttrack' ),
            esc_html( self::dimensions( $chosen['length'], $chosen['min_width'] ) ),
            esc_html( self::note( $chosen ) ),
            esc_html(
                sprintf(
                    /* translators: 1: metres of length, 2: metres of width */
                    __( 'Derived from %1$d by %2$d metres per outfield player.', 'talenttrack' ),
                    $per['length'],
                    $per['width']
                )
            )
        );
    }

    /** "60 × 36 m" */
    private static function dimensions( int $length, int $width ): string {
        return sprintf( '%d × %d m', $length, $width );
    }

    /**
     * Why the usable figure differs from the computed one, or that it
     * does not. Stated either way: silence on the common case would make
     * the reader wonder whether the block had failed.
     *
     * @param array{format: string, length: int, width: int, min_width: int} $size
     */
    private static function note( array $size ): string {
        if ( $size['min_width'] <= $size['width'] ) {
            return __( 'The rule of thumb works at this format.', 'talenttrack' );
        }

        return sprintf(
            /* translators: 1: computed width in metres, 2: minimum usable width in metres */
            __( 'The computed width of %1$d m is narrower than a penalty area. Widen to %2$d m, or the game turns into a more intensive method than you planned.', 'talenttrack' ),
            $size['width'],
            $size['min_width']
        );
    }
}
