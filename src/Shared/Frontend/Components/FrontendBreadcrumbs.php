<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Renders a breadcrumb trail above a frontend detail or edit view.
 *
 * Each item is `[ 'label' => string, 'url' => ?string ]`. The final
 * item is the current page (no url). When `[]` is passed the component
 * renders nothing — list views stay clean (#0077 F2).
 */
final class FrontendBreadcrumbs {

    /**
     * @param array<int,array{label:string,url?:?string}> $items
     */
    public static function render( array $items ): void {
        if ( empty( $items ) ) return;
        echo '<nav class="tt-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'talenttrack' ) . '"><ol>';
        $last = count( $items ) - 1;
        foreach ( $items as $i => $item ) {
            $label = (string) ( $item['label'] ?? '' );
            $url   = isset( $item['url'] ) ? (string) $item['url'] : '';
            $is_current = ( $i === $last );
            echo '<li' . ( $is_current ? ' aria-current="page"' : '' ) . '>';
            if ( $url !== '' && ! $is_current ) {
                echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
            } else {
                echo esc_html( $label );
            }
            if ( ! $is_current ) {
                echo '<span class="tt-breadcrumbs__sep" aria-hidden="true"> / </span>';
            }
            echo '</li>';
        }
        echo '</ol></nav>';
    }
}
