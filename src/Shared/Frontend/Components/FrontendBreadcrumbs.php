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
     * Adds a leading "← Back" crumb to the chain when `wp_get_referer()`
     * returns a same-origin URL distinct from the current page. The
     * back crumb sits before "Dashboard" and points at the referer —
     * the page the user came from. Useful when a deep-link path
     * (e.g. My card → Goal detail) doesn't match the static
     * Dashboard / Goals / Goal-detail chain the breadcrumbs would
     * otherwise show. Caller opt-in.
     *
     * Cheap implementation: no per-user back-stack store. Just the
     * referer header. Multi-step navigation (A → B → C → click back)
     * goes back to B, not A — same as the browser's own Back button.
     * That mirrors the browser, which is the right model.
     */
    public static function fromDashboardWithBack( string $current_label, ?array $intermediate = null, ?string $back_url = null ): void {
        $back = $back_url !== null && $back_url !== '' ? $back_url : self::sameOriginReferer();
        $items = [];
        if ( $back !== '' ) {
            $items[] = [
                'label' => __( '← Back', 'talenttrack' ),
                'url'   => $back,
                'class' => 'tt-breadcrumbs__back',
            ];
        }
        $items[] = [
            'label' => __( 'Dashboard', 'talenttrack' ),
            'url'   => RecordLink::dashboardUrl(),
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
     * Returns `wp_get_referer()` only when it exists, points to the
     * current site origin, and isn't the current request URL. Empty
     * string in any other case — direct navigation, cross-origin
     * entry, refresh on the same page, bot crawls.
     */
    private static function sameOriginReferer(): string {
        $ref = wp_get_referer();
        if ( ! is_string( $ref ) || $ref === '' ) return '';
        $home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $ref_host  = wp_parse_url( $ref, PHP_URL_HOST );
        if ( $home_host !== $ref_host ) return '';
        // Drop self-referer (refresh on the same page).
        $current = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ( $current !== '' ) {
            $ref_path  = (string) wp_parse_url( $ref, PHP_URL_PATH );
            $ref_query = (string) wp_parse_url( $ref, PHP_URL_QUERY );
            $ref_uri   = $ref_path . ( $ref_query !== '' ? '?' . $ref_query : '' );
            if ( $ref_uri === $current ) return '';
        }
        return $ref;
    }

    /**
     * @param array<int,array{label:string,url?:?string,class?:?string}> $items
     */
    public static function render( array $items ): void {
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
