<?php
namespace TT\Modules\License\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\License\SubscriptionStatus;

/**
 * SubscriptionBanner (#3497) — one app-wide line while an academy's
 * subscription is suspended or has ended.
 *
 * Rendered once above every frontend view for every logged-in persona
 * (`tt_dashboard_before_body`), and as a wp-admin notice, because a
 * frontend-only academy never opens wp-admin. It comes before the locked
 * panels a reader will then meet, so the first thing they learn is that
 * the records are safe.
 *
 * Dismissable for the browser session only, never permanently: the state
 * it describes does not go away when the banner does. The dismissal is
 * kept in `sessionStorage` by `assets/js/subscription-banner.js`; without
 * script the banner simply stays.
 */
final class SubscriptionBanner {

    public static function init(): void {
        // Priority 5: above the flash queue and the alert bar.
        add_action( 'tt_dashboard_before_body', [ self::class, 'renderFrontend' ], 5 );
        add_action( 'admin_notices', [ self::class, 'renderAdmin' ] );
    }

    public static function renderFrontend(): void {
        if ( ! is_user_logged_in() || ! SubscriptionStatus::isInterrupted() ) return;

        self::enqueue();
        echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes.
    }

    public static function renderAdmin(): void {
        if ( ! SubscriptionStatus::isInterrupted() || ! current_user_can( 'read' ) ) return;

        echo '<div class="notice notice-warning"><p><strong>' . esc_html( SubscriptionStatus::headline() ) . '</strong> '
            . esc_html( SubscriptionStatus::detail() ) . '</p></div>';
    }

    public static function html(): string {
        return '<div class="tt-subscription-banner" role="status" data-tt-subscription-banner="' . esc_attr( SubscriptionStatus::current() ) . '">'
            . '<p class="tt-subscription-banner__text"><strong>' . esc_html( SubscriptionStatus::headline() ) . '</strong> '
            . esc_html( SubscriptionStatus::detail() ) . '</p>'
            . '<button type="button" class="tt-subscription-banner__dismiss" data-tt-subscription-dismiss aria-label="'
            . esc_attr__( 'Hide this message for now', 'talenttrack' ) . '">&times;</button>'
            . '</div>';
    }

    private static function enqueue(): void {
        $version = defined( 'TT_VERSION' ) ? TT_VERSION : false;
        wp_enqueue_style( 'tt-tokens', TT_PLUGIN_URL . 'assets/css/tokens.css', [], $version );
        wp_enqueue_style( 'tt-upgrade-panel', TT_PLUGIN_URL . 'assets/css/upgrade-panel.css', [ 'tt-tokens' ], $version );
        wp_enqueue_script( 'tt-subscription-banner', TT_PLUGIN_URL . 'assets/js/subscription-banner.js', [], $version, true );
    }
}
