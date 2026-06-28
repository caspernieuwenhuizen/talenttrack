<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\Tt404Page;

/**
 * Tt404Handler (#2035) — branded takeover of the real WordPress 404.
 *
 * When a URL matches no page/post, WordPress would render the active
 * (hostile) theme's `404.php`. This handler intercepts that one
 * `template_include` chokepoint — exactly as CanvasShell does for the
 * dashboard — and substitutes a minimal, theme-free document hosting the
 * branded Tt404Page content. The HTTP `404` status and no-cache headers are
 * preserved so crawlers and proxies still see a proper not-found.
 *
 * Total visual isolation: like CanvasShell, every non-TalentTrack stylesheet
 * is dequeued before the head prints, so no theme CSS leaks into the surface.
 *
 * Operator opt-out: the takeover is gated behind the club-scoped
 * `tt_handle_wp_404` config flag (read via ConfigService — never wp_options),
 * defaulting ON, and a final `tt_handle_wp_404` filter so code can override.
 * An academy running TalentTrack alongside unrelated WordPress content can
 * switch the takeover off and keep its theme's 404.
 *
 * SaaS (§4): this is the single, replaceable WP-coupling point for the 404
 * surface; Tt404Page itself is pure presentation and ports unchanged.
 */
class Tt404Handler {

    private const CONFIG_KEY = 'tt_handle_wp_404';

    public static function init(): void {
        // Late priority so the theme has resolved its 404 template first; we
        // override only at the very end, mirroring CanvasShell.
        add_filter( 'template_include', [ __CLASS__, 'maybe404Template' ], 99 );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueueAssets' ] );
        // Strip foreign stylesheets on the 404 surface for total visual
        // isolation. Priority 9999 runs after every other enqueue.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'stripForeignStyles' ], 9999 );
        add_action( 'wp_print_styles',    [ __CLASS__, 'stripForeignStyles' ], 9999 );
    }

    /**
     * Return the plugin 404 template when this is a real WP 404 and the
     * operator hasn't opted the takeover out. Otherwise pass through.
     */
    public static function maybe404Template( string $template ): string {
        if ( ! self::shouldTakeOver() ) {
            return $template;
        }
        // Preserve the not-found contract for crawlers / proxies.
        status_header( 404 );
        nocache_headers();
        $tpl = TT_PLUGIN_DIR . 'templates/canvas-404.php';
        return is_readable( $tpl ) ? $tpl : $template;
    }

    public static function enqueueAssets(): void {
        if ( ! self::shouldTakeOver() ) {
            return;
        }
        // Tokens first (palette + scale), then public (brand button), then the
        // structural canvas frame, then the 404 layout. Each declares its dep
        // so cascade order is deterministic.
        wp_enqueue_style( 'tt-tokens', TT_PLUGIN_URL . 'assets/css/tokens.css', [], TT_VERSION );
        wp_enqueue_style( 'tt-public', TT_PLUGIN_URL . 'assets/css/public.css', [ 'tt-tokens' ], TT_VERSION );
        wp_enqueue_style( 'tt-frontend-canvas', TT_PLUGIN_URL . 'assets/css/frontend-canvas.css', [ 'tt-public' ], TT_VERSION );
        wp_enqueue_style( 'tt-frontend-404', TT_PLUGIN_URL . 'assets/css/frontend-404.css', [ 'tt-public', 'tt-frontend-canvas' ], TT_VERSION );
    }

    /**
     * Dequeue every enqueued stylesheet whose registered `src` is not under
     * TT_PLUGIN_URL, except the admin bar / dashicons / operator Google Fonts
     * and any `tt-` handle. Mirrors CanvasShell::stripForeignStyles so the 404
     * surface is just as theme-free.
     */
    public static function stripForeignStyles(): void {
        if ( ! self::shouldTakeOver() ) {
            return;
        }
        $styles = wp_styles();
        if ( ! $styles instanceof \WP_Styles ) {
            return;
        }
        $plugin_url = defined( 'TT_PLUGIN_URL' ) ? (string) TT_PLUGIN_URL : '';
        $allowlist  = [ 'admin-bar', 'dashicons', 'tt-brand-fonts' ];
        foreach ( (array) $styles->queue as $handle ) {
            $handle = (string) $handle;
            if ( in_array( $handle, $allowlist, true ) || strpos( $handle, 'tt-' ) === 0 ) {
                continue;
            }
            $registered = $styles->registered[ $handle ] ?? null;
            $src        = ( $registered && isset( $registered->src ) ) ? (string) $registered->src : '';
            if ( $src === '' ) {
                continue;
            }
            if ( $plugin_url !== '' && strpos( $src, $plugin_url ) === 0 ) {
                continue;
            }
            if ( strpos( $src, 'fonts.googleapis.com' ) !== false || strpos( $src, 'fonts.gstatic.com' ) !== false ) {
                continue;
            }
            wp_dequeue_style( $handle );
        }
    }

    /**
     * The branded inner content, with the standalone "Back to dashboard"
     * button. Called by templates/canvas-404.php.
     */
    public static function renderContent(): void {
        echo '<div class="tt-root tt-404 tt-dashboard">';
        echo Tt404Page::innerHtml( true ); // phpcs:ignore — escaped inside Tt404Page.
        echo '</div>';
    }

    /**
     * True when this request is a genuine WP 404 the plugin should brand:
     * a front-end 404, not in wp-admin / feed / embed, with the takeover
     * flag on. The `tt_handle_wp_404` filter has the final say.
     */
    private static function shouldTakeOver(): bool {
        if ( is_admin() || is_feed() || is_embed() ) {
            return false;
        }
        if ( ! is_404() ) {
            return false;
        }
        $enabled = self::flagEnabled();
        /**
         * Filter the branded-404 takeover decision.
         *
         * @param bool $enabled Whether to render the branded TalentTrack 404
         *                      in place of the theme's 404 template.
         */
        return (bool) apply_filters( 'tt_handle_wp_404', $enabled );
    }

    /**
     * Club-scoped config flag, defaulting ON. Read through ConfigService so
     * the value is tenant-scoped (never wp_options).
     */
    private static function flagEnabled(): bool {
        if ( ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) {
            return true;
        }
        $cfg = new \TT\Infrastructure\Config\ConfigService();
        return $cfg->getBool( self::CONFIG_KEY, true );
    }
}
