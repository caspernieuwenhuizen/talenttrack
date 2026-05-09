<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FrontendViewBase — shared render utilities for v3.0.0 focused
 * frontend views.
 *
 * Each sub-view class (FrontendOverviewView, FrontendMyTeamView, …)
 * extends this to get:
 *
 *   - A page header with optional title + breadcrumb chain
 *   - Asset enqueueing (player-card CSS + mobile CSS, once per request)
 *   - Consistent wrap styling
 *
 * Sub-views don't render a tab bar — they're each one focused page.
 * Navigation is the breadcrumb chain (FrontendBreadcrumbs::fromDashboard)
 * plus the tt_back-borne pill the breadcrumb component auto-renders
 * above it. See docs/back-navigation.md.
 */
abstract class FrontendViewBase {

    private static bool $assets_enqueued = false;

    /**
     * Enqueue shared frontend assets. Idempotent across the request.
     */
    protected static function enqueueAssets(): void {
        if ( self::$assets_enqueued ) return;

        \TT\Modules\Stats\Admin\PlayerCardView::enqueueStyles();

        wp_enqueue_style(
            'tt-frontend-mobile',
            TT_PLUGIN_URL . 'assets/css/frontend-mobile.css',
            [],
            TT_VERSION
        );

        // Client-side table sort + search. Safe no-op on views without
        // a .tt-table-sortable element; cheap to load once per request.
        wp_enqueue_script(
            'tt-table-tools',
            TT_PLUGIN_URL . 'assets/js/tt-table-tools.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-table-tools',
            'ttTableToolsStrings',
            [
                'search'            => __( 'Search:', 'talenttrack' ),
                'searchPlaceholder' => __( 'Filter rows…', 'talenttrack' ),
                'rowsTotal'         => __( '{n} row(s)', 'talenttrack' ),
                'rowsFiltered'      => __( '{v} of {n}', 'talenttrack' ),
            ]
        );

        // v3.110.53 — Archive-button handler for detail pages. Listens
        // for clicks on [data-tt-archive-rest-path], runs window.confirm(),
        // POSTs DELETE /wp-json/talenttrack/v1/<path>, redirects on
        // success. No-op on pages without the data attribute, cheap
        // to load once per request alongside table-tools.
        wp_enqueue_script(
            'tt-frontend-archive-button',
            TT_PLUGIN_URL . 'assets/js/frontend-archive-button.js',
            [ 'tt-public' ],
            TT_VERSION,
            true
        );

        self::$assets_enqueued = true;
    }

    /**
     * Render the view header: breadcrumbs (when declared via the static
     * override) and the page title.
     *
     * v3.110.41 — removed the FrontendBackButton fallback. Per
     * docs/back-navigation.md the two canonical nav affordances are the
     * tt_back-borne pill (auto-rendered above the breadcrumb chain by
     * FrontendBreadcrumbs::render()) and the breadcrumb itself. Views
     * that need a dynamic chain call FrontendBreadcrumbs::fromDashboard()
     * directly before renderHeader(); views with a static chain override
     * static::breadcrumbs() and let renderHeader emit it.
     */
    protected static function renderHeader( string $title, string $actions_html = '' ): void {
        $crumbs = static::breadcrumbs();
        if ( ! empty( $crumbs ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::render( $crumbs );
        }
        if ( $actions_html !== '' ) {
            echo '<header class="tt-page-head">';
            echo '<h1 class="tt-fview-title" style="font-size:22px; color:#1a1d21;">'
                . esc_html( $title )
                . '</h1>';
            echo '<div class="tt-page-actions">' . $actions_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pageActionsHtml escapes per-action.
            echo '</header>';
            return;
        }
        echo '<h1 class="tt-fview-title" style="margin:6px 0 18px; font-size:22px; color:#1a1d21;">'
            . esc_html( $title )
            . '</h1>';
    }

    /**
     * v3.110.53 — Build the HTML for a page-actions slot from a structured
     * array. Pair with `renderHeader( $title, self::pageActionsHtml( $actions ) )`
     * on list / detail views, or compose into a manual `<header class="tt-page-head">`
     * on views that bypass renderHeader (e.g. FrontendPlayerDetailView).
     *
     * Each action accepts:
     *   - 'label'      (required): visible button text.
     *   - 'href'       (optional): target URL for `<a>` actions. Omit
     *                   to render `<button type="button">` driven by
     *                   `data_attrs` (e.g. archive REST DELETE).
     *   - 'primary'    (optional, default false): true → main CTA,
     *                   becomes FAB bottom-right on mobile via CSS.
     *   - 'icon'       (optional): glyph (e.g. '+') shown on the FAB.
     *                   Hidden on desktop.
     *   - 'variant'    (optional): 'primary' | 'secondary' | 'danger'.
     *                   Defaults to primary when 'primary' is true,
     *                   else secondary. Use 'danger' for destructive.
     *   - 'cap'        (optional): capability gate. Action is skipped
     *                   if the current user lacks it.
     *   - 'confirm'    (optional): native confirm() text. Cancels the
     *                   navigation / submit if the user dismisses.
     *   - 'data_attrs' (optional): map of `data-*` → value pairs for
     *                   client-side hooks (e.g. archive button).
     *
     * @param array<int,array<string,mixed>> $actions
     */
    public static function pageActionsHtml( array $actions ): string {
        if ( empty( $actions ) ) return '';
        $html = '';
        foreach ( $actions as $a ) {
            if ( ! is_array( $a ) || empty( $a['label'] ) ) continue;
            if ( ! empty( $a['cap'] ) && ! current_user_can( (string) $a['cap'] ) ) continue;
            $is_primary = ! empty( $a['primary'] );
            $href       = (string) ( $a['href'] ?? '' );
            $label      = (string) $a['label'];
            $icon       = (string) ( $a['icon'] ?? '' );
            $confirm    = (string) ( $a['confirm'] ?? '' );
            $variant    = ! empty( $a['variant'] ) ? (string) $a['variant'] : ( $is_primary ? 'primary' : 'secondary' );
            $cls        = 'tt-btn tt-btn-' . sanitize_html_class( $variant );
            $cls       .= $is_primary ? ' tt-page-actions__primary' : ' tt-page-actions__secondary';

            $attr_html = '';
            if ( ! empty( $a['data_attrs'] ) && is_array( $a['data_attrs'] ) ) {
                foreach ( $a['data_attrs'] as $key => $value ) {
                    $attr_html .= ' data-' . esc_attr( (string) $key ) . '="' . esc_attr( (string) $value ) . '"';
                }
            }
            if ( $confirm !== '' ) {
                $attr_html .= ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"';
            }

            $inner = '';
            if ( $is_primary && $icon !== '' ) {
                $inner .= '<span class="tt-page-actions__icon" aria-hidden="true">' . esc_html( $icon ) . '</span>';
            }
            $inner .= '<span class="tt-page-actions__label">' . esc_html( $label ) . '</span>';

            if ( $href !== '' ) {
                $html .= '<a href="' . esc_url( $href ) . '" class="' . esc_attr( $cls ) . '"' . $attr_html . '>' . $inner . '</a>';
            } else {
                $html .= '<button type="button" class="' . esc_attr( $cls ) . '"' . $attr_html . '>' . $inner . '</button>';
            }
        }
        return $html;
    }

    /**
     * Sub-views override this to declare a static breadcrumb chain
     * (#0077 F2). Each item: `[ 'label' => string, 'url' => ?string ]`.
     * The last item is the current page and gets no link. Empty array
     * (default) means renderHeader() emits no breadcrumbs — the view
     * is expected to call FrontendBreadcrumbs::fromDashboard() itself
     * before calling renderHeader() (the dynamic-chain pattern).
     *
     * @return array<int,array{label:string,url?:?string}>
     */
    protected static function breadcrumbs(): array {
        return [];
    }
}
