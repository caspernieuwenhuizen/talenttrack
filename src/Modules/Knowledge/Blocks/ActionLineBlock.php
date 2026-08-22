<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ActionLineBlock — the notation the methodology is written in.
 *
 *     ```tt-actionline
 *     Hoger niveau | 100 | 15
 *     Lager niveau | 70  | 45
 *     ```
 *
 * One row per line: label, action quality as a percentage, seconds between
 * actions. Each row draws a run of football actions — the mark sized by
 * quality, the gap sized by recovery time — so two rows put a good and a
 * poor player side by side and the difference is visible rather than
 * asserted.
 *
 * The source book draws this with crosses and hyphens in a monospace font.
 * That reproduces badly at 360px and not at all for a screen reader, so
 * the marks are spans with a width and the whole figure carries a text
 * alternative that says what it shows.
 */
final class ActionLineBlock implements BlockRenderer {

    public static function name(): string {
        return 'tt-actionline';
    }

    public static function isInteractive(): bool {
        return false;
    }

    /** Actions drawn per row. Enough to read as a run, few enough to fit. */
    private const MARKS = 6;

    /** Gap in seconds that maps to the widest drawn interval. */
    private const MAX_GAP = 60;

    public static function render( array $attrs, string $body ): string {
        $rows = [];

        foreach ( preg_split( '/\R/', trim( $body ) ) ?: [] as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }

            $parts = array_map( 'trim', explode( '|', $line ) );
            if ( count( $parts ) < 3 ) {
                continue;
            }

            $quality = max( 0, min( 100, (int) $parts[1] ) );
            $gap     = max( 0, (int) $parts[2] );

            $rows[] = [
                'label'   => $parts[0],
                'quality' => $quality,
                'gap'     => $gap,
            ];
        }

        if ( $rows === [] ) {
            return '';
        }

        $html = '<figure class="tt-lesson-actionline">';

        foreach ( $rows as $row ) {
            $marks = '';
            for ( $i = 0; $i < self::MARKS; $i++ ) {
                // Quality scales the mark; the gap scales the space after
                // it. Both are computed per row, so they are inline by
                // necessity — a stylesheet cannot know the numbers.
                $marks .= sprintf(
                    '<span class="tt-lesson-actionline__mark" style="--tt-mark-scale:%s"></span>', /* tt-inline-ok */
                    esc_attr( (string) round( 0.4 + ( $row['quality'] / 100 ) * 0.6, 2 ) )
                );
                if ( $i < self::MARKS - 1 ) {
                    $marks .= sprintf(
                        '<span class="tt-lesson-actionline__gap" style="--tt-gap-scale:%s"></span>', /* tt-inline-ok */
                        esc_attr( (string) round( min( $row['gap'], self::MAX_GAP ) / self::MAX_GAP, 3 ) )
                    );
                }
            }

            $summary = sprintf(
                /* translators: 1: quality percentage, 2: seconds between actions */
                __( 'Actions at %1$d%% quality, %2$d seconds apart.', 'talenttrack' ),
                $row['quality'],
                $row['gap']
            );

            $html .= sprintf(
                '<div class="tt-lesson-actionline__row"><span class="tt-lesson-actionline__label">%1$s</span><span class="tt-lesson-actionline__track" role="img" aria-label="%2$s">%3$s</span><span class="tt-lesson-actionline__figures">%4$s</span></div>',
                esc_html( $row['label'] ),
                esc_attr( $summary ),
                $marks,
                esc_html( sprintf( '%d%% · %ds', $row['quality'], $row['gap'] ) )
            );
        }

        $html .= '</figure>';

        return $html;
    }
}
