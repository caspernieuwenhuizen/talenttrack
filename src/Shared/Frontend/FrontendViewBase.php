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
 *   - A page header with optional title + back button
 *   - Asset enqueueing (player-card CSS + mobile CSS, once per request)
 *   - Consistent wrap styling
 *
 * Sub-views don't render a tab bar — they're each one focused page.
 * The back button at the top returns to the tile landing page via
 * FrontendBackButton.
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

        self::$assets_enqueued = true;
    }

    /**
     * Render the view header: breadcrumbs (if declared) or back button +
     * title. Sub-views call this at the top of their render() methods.
     *
     * Breadcrumbs override the back button — when a sub-view declares a
     * breadcrumb chain via static::breadcrumbs(), the chain implicitly
     * conveys "back" so the standalone back link is suppressed (#0077 F2).
     */
    protected static function renderHeader( string $title ): void {
        $crumbs = static::breadcrumbs();
        if ( ! empty( $crumbs ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::render( $crumbs );
        } else {
            FrontendBackButton::render();
        }
        echo '<h1 class="tt-fview-title" style="margin:6px 0 18px; font-size:22px; color:#1a1d21;">'
            . esc_html( $title )
            . '</h1>';
    }

    /**
     * Sub-views override this to declare a breadcrumb chain (#0077 F2).
     * Each item: `[ 'label' => string, 'url' => ?string ]`. The last
     * item is the current page and gets no link. List views return `[]`
     * (default) so they keep using the standalone back button.
     *
     * @return array<int,array{label:string,url?:?string}>
     */
    protected static function breadcrumbs(): array {
        return [];
    }
}
