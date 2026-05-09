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
     * v3.92.1 sweep helper. Most views need a "Dashboard / [self]" or
     * "Dashboard / [parent list] / [self]" chain. Constructs the
     * Dashboard crumb (URL via `RecordLink::dashboardUrl()`), appends
     * any caller-supplied intermediate crumbs, then the un-linked
     * current-page crumb.
     *
     * @param string                             $current_label  Current page label (no url, last crumb).
     * @param array<int,array{label:string,url:string}>|null $intermediate Optional crumbs between Dashboard and current.
     */
    public static function fromDashboard( string $current_label, ?array $intermediate = null ): void {
        $items = [
            [
                'label' => __( 'Dashboard', 'talenttrack' ),
                'url'   => RecordLink::dashboardUrl(),
            ],
        ];
        if ( $intermediate !== null ) {
            foreach ( $intermediate as $crumb ) {
                $items[] = $crumb;
            }
        }
        $items[] = [ 'label' => $current_label ];
        self::render( $items );
    }

    /**
     * Convenience constructor for an intermediate crumb pointing at a
     * `?tt_view=<slug>` route (the most common shape inside the
     * dispatcher).
     *
     * @return array{label:string,url:string}
     */
    public static function viewCrumb( string $slug, string $label, array $extra_args = [] ): array {
        $args = array_merge( [ 'tt_view' => $slug ], $extra_args );
        return [
            'label' => $label,
            'url'   => add_query_arg( $args, RecordLink::dashboardUrl() ),
        ];
    }


    /**
     * @param array<int,array{label:string,url?:?string,class?:?string}> $items
     */
    public static function render( array $items ): void {
        // v3.110.0 — auto-render the URL-borne back pill above the
        // breadcrumb chain whenever the current request carries
        // `tt_back`. Empty string when no valid back-target exists, so
        // direct-navigation paths render the breadcrumbs alone.
        $pill = BackLink::renderPill();
        if ( $pill !== '' ) {
            echo $pill; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        if ( empty( $items ) ) return;
        echo '<nav class="tt-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'talenttrack' ) . '"><ol>';
        $last = count( $items ) - 1;
        foreach ( $items as $i => $item ) {
            $label = (string) ( $item['label'] ?? '' );
            $url   = isset( $item['url'] ) ? (string) $item['url'] : '';
            $cls   = isset( $item['class'] ) ? (string) $item['class'] : '';
            $is_current = ( $i === $last );
            echo '<li' . ( $is_current ? ' aria-current="page"' : '' ) . ( $cls !== '' ? ' class="' . esc_attr( $cls ) . '"' : '' ) . '>';
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
