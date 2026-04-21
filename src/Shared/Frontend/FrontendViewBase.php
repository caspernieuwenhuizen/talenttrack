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

        self::$assets_enqueued = true;
    }

    /**
     * Render the view header: back button + title. Sub-views call this
     * at the top of their render() methods.
     */
    protected static function renderHeader( string $title ): void {
        FrontendBackButton::render();
        echo '<h1 class="tt-fview-title" style="margin:6px 0 18px; font-size:22px; color:#1a1d21;">'
            . esc_html( $title )
            . '</h1>';
    }
}
