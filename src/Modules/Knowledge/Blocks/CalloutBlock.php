<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\LessonMarkdown;

/**
 * CalloutBlock — an aside that carries a role.
 *
 *     ```tt-callout type="objectives"
 *     Na deze module leg je uit waarom …
 *     ```
 *
 * Four roles, each with its own label and colour: what you will learn,
 * what matters most, what to watch out for, and a note. The type is a
 * semantic claim, not a colour choice — `warning` means the reader can get
 * this wrong in a way that costs a player, and it should stay rare enough
 * that it keeps meaning that.
 */
final class CalloutBlock implements BlockRenderer {

    public static function name(): string {
        return 'tt-callout';
    }

    public static function isInteractive(): bool {
        return false;
    }

    public static function render( array $attrs, string $body ): string {
        $type = strtolower( $attrs['type'] ?? 'note' );

        $labels = [
            'objectives' => __( 'What you will learn', 'talenttrack' ),
            'key'        => __( 'The point', 'talenttrack' ),
            'warning'    => __( 'Watch out', 'talenttrack' ),
            'note'       => __( 'Note', 'talenttrack' ),
        ];

        if ( ! isset( $labels[ $type ] ) ) {
            $type = 'note';
        }

        return sprintf(
            '<aside class="tt-lesson-callout tt-lesson-callout--%1$s"><p class="tt-lesson-callout__label">%2$s</p><div class="tt-lesson-callout__body">%3$s</div></aside>',
            esc_attr( $type ),
            esc_html( $labels[ $type ] ),
            LessonMarkdown::renderProse( $body )
        );
    }
}
