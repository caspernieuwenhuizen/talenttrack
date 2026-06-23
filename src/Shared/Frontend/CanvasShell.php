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
 * post contains the `[talenttrack_dashboard]` shortcode, the filter
 * returns `templates/canvas.php` instead of the theme's page template.
 * The plugin template still runs the WP lifecycle — `language_attributes()`,
 * `wp_head()`, `body_class()`, `the_content()`, `wp_footer()` — so the WP
 * admin bar and all enqueued plugin assets render, but no theme chrome
 * leaks. The theme's `header.php` / `footer.php` / sidebars never run.
 *
 * Total visual isolation (#1728): `wp_head()` would otherwise print
 * EVERY enqueued stylesheet — including the active theme's `style.css`
 * and other plugins' CSS — and that theme CSS can win specificity
 * battles against TalentTrack tokens. So in canvas mode we dequeue every
 * non-TalentTrack stylesheet before the head prints, leaving only TT's
 * own handles, the WP admin bar, and operator-chosen Google Fonts. There
 * is no opt-out — full independence is the contract.
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

    /**
     * Stylesheet handles that survive the canvas dequeue even though their
     * src is not under TT_PLUGIN_URL. The WP admin bar must still render
     * for logged-in staff; dashicons backs it.
     */
    private const STYLE_ALLOWLIST = [ 'admin-bar', 'dashicons', 'tt-brand-fonts' ];

    public static function init(): void {
        // Late priority so the theme (and other plugins) have already
        // resolved their template; we only override at the very end.
        add_filter( 'template_include', [ __CLASS__, 'maybeCanvasTemplate' ], 99 );
        // Enqueue the structural shell stylesheet during the head pass so
        // it lands in wp_head() — the shortcode (which enqueues the app's
        // own CSS) runs later, inside the_content(), but the canvas shell
        // frame must exist before first paint to avoid layout shift.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueueShell' ] );
        // Total visual isolation (#1728): strip non-TT stylesheets so the
        // theme contributes zero CSS. Priority 9999 runs after every other
        // enqueue; the wp_print_styles pass is belt-and-suspenders for any
        // stylesheet enqueued even later.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'stripForeignStyles' ], 9999 );
        add_action( 'wp_print_styles',    [ __CLASS__, 'stripForeignStyles' ], 9999 );
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
     * Dequeue every enqueued stylesheet whose registered `src` is not
     * under TT_PLUGIN_URL, except the allowlist (admin bar, dashicons,
     * operator Google Fonts, and any `tt-` handle). Runs only in canvas
     * mode. Once the theme contributes zero CSS, nothing in the document
     * can override the TalentTrack palette.
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
        // Iterate a copy — wp_dequeue_style() mutates the queue.
        foreach ( (array) $styles->queue as $handle ) {
            if ( self::isAllowedStyle( (string) $handle, $styles, $plugin_url ) ) {
                continue;
            }
            wp_dequeue_style( $handle );
        }
    }

    /**
     * True when a stylesheet handle should survive the canvas dequeue:
     * any TalentTrack-owned handle (src under TT_PLUGIN_URL, or a `tt-`
     * prefixed handle), the admin bar / dashicons, or an operator Google
     * Fonts request. Resolves src defensively — a missing or false src
     * (handles with no stylesheet, e.g. dependency-only) is treated as
     * non-foreign and kept.
     */
    private static function isAllowedStyle( string $handle, \WP_Styles $styles, string $plugin_url ): bool {
        if ( in_array( $handle, self::STYLE_ALLOWLIST, true ) ) {
            return true;
        }
        if ( strpos( $handle, 'tt-' ) === 0 ) {
            return true;
        }
        $registered = $styles->registered[ $handle ] ?? null;
        $src        = ( $registered && isset( $registered->src ) ) ? (string) $registered->src : '';
        if ( $src === '' ) {
            // No own stylesheet (alias / dependency handle) — nothing to strip.
            return true;
        }
        if ( $plugin_url !== '' && strpos( $src, $plugin_url ) === 0 ) {
            return true;
        }
        if ( strpos( $src, 'fonts.googleapis.com' ) !== false || strpos( $src, 'fonts.gstatic.com' ) !== false ) {
            return true;
        }
        return false;
    }

    /**
     * Return the plugin canvas template when the current main-query page
     * hosts the dashboard shortcode. Otherwise pass the resolved template
     * through untouched.
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
     * shortcode. Canvas mode is mandatory (#1728) — there is no opt-out;
     * the guards here are correctness, not preference.
     */
    private static function shouldTakeOver(): bool {
        if ( is_admin() || is_feed() || is_embed() ) {
            return false;
        }
        if ( ! is_singular() ) {
            return false;
        }
        $post = get_post();
        if ( ! $post instanceof \WP_Post ) {
            return false;
        }
        return has_shortcode( (string) $post->post_content, 'talenttrack_dashboard' );
    }
}
