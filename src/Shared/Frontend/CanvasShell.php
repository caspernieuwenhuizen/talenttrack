<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CanvasShell (#1590) — full-canvas app shell.
 *
 * Renders the TalentTrack dashboard page without the active theme's
 * header / footer / sidebar / widgets, so only the TalentTrack UI shows.
 * CLAUDE.md treats the active theme as hostile; by default the app
 * renders inside `the_content` at the theme's mercy. This takes over the
 * one template chokepoint and substitutes a minimal plugin document.
 *
 * Mechanism: a single `template_include` filter. When the main-query
 * post contains the `[talenttrack_dashboard]` shortcode AND the academy
 * has the `frontend_canvas_mode` toggle on (default on), the filter
 * returns `templates/canvas.php` instead of the theme's page template.
 * The plugin template still runs the WP lifecycle — `language_attributes()`,
 * `wp_head()`, `body_class()`, `the_content()`, `wp_footer()` — so the WP
 * admin bar and all enqueued plugin assets render, but no theme chrome
 * leaks. The theme's `header.php` / `footer.php` / sidebars never run.
 *
 * Why `template_include` over `template_redirect`: the main query and the
 * full `wp_head` / `wp_footer` lifecycle stay intact, which the print
 * routers (which short-circuit on `template_redirect` and `exit`) do not
 * need. Those routers run earlier than `template_include`, so a print
 * request never reaches this filter.
 *
 * SaaS (§4): the take-over decision lives at this ONE chokepoint —
 * replaceable wholesale for the SaaS migration — not scattered across
 * views. The toggle is club-scoped `tt_config`, read through
 * ConfigService, never `wp_options`.
 */
class CanvasShell {

    /** @var string tt_config key — boolean, default true. */
    public const CONFIG_KEY = 'frontend_canvas_mode';

    public static function init(): void {
        // Late priority so the theme (and other plugins) have already
        // resolved their template; we only override at the very end.
        add_filter( 'template_include', [ __CLASS__, 'maybeCanvasTemplate' ], 99 );
        // Enqueue the structural shell stylesheet during the head pass so
        // it lands in wp_head() — the shortcode (which enqueues the app's
        // own CSS) runs later, inside the_content(), but the canvas shell
        // frame must exist before first paint to avoid layout shift.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueueShell' ] );
    }

    public static function enqueueShell(): void {
        if ( ! self::shouldTakeOver() ) {
            return;
        }
        wp_enqueue_style(
            'tt-frontend-canvas',
            TT_PLUGIN_URL . 'assets/css/frontend-canvas.css',
            [],
            TT_VERSION
        );
    }

    /**
     * Return the plugin canvas template when the current main-query page
     * hosts the dashboard shortcode and the canvas toggle is on. Otherwise
     * pass the resolved template through untouched.
     */
    public static function maybeCanvasTemplate( string $template ): string {
        if ( ! self::shouldTakeOver() ) {
            return $template;
        }
        $canvas = TT_PLUGIN_DIR . 'templates/canvas.php';
        return is_readable( $canvas ) ? $canvas : $template;
    }

    /**
     * True when the singular main-query post embeds the dashboard
     * shortcode and the academy has not opted out of canvas mode.
     */
    private static function shouldTakeOver(): bool {
        if ( is_admin() || is_feed() || is_embed() ) {
            return false;
        }
        if ( ! is_singular() ) {
            return false;
        }
        if ( ! self::isCanvasEnabled() ) {
            return false;
        }
        $post = get_post();
        if ( ! $post instanceof \WP_Post ) {
            return false;
        }
        return has_shortcode( (string) $post->post_content, 'talenttrack_dashboard' );
    }

    /**
     * Read the club-scoped toggle. Default ON (academy opt-out) — a fresh
     * install with no seeded value still renders full-canvas.
     */
    public static function isCanvasEnabled(): bool {
        if ( ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) {
            return true;
        }
        $cfg = \TT\Core\Kernel::instance()->container()->get( 'config' );
        return $cfg->getBool( self::CONFIG_KEY, true );
    }
}
