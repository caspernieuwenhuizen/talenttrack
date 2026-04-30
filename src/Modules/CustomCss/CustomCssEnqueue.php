<?php
namespace TT\Modules\CustomCss;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\CustomCss\Repositories\CustomCssRepository;

/**
 * CustomCssEnqueue — emits the saved custom-CSS payload into the page,
 * scoped to `.tt-root` per the #0064 isolation strategy (Q1: scoped
 * class).
 *
 * Two surfaces:
 *   - Frontend (`wp_head` / `wp_enqueue_scripts`) — the
 *     [tt_dashboard] shortcode + every TT-rendered surface that lives
 *     under it. Activates when the surface toggle is on.
 *   - wp-admin (`admin_head` / `admin_enqueue_scripts`) — the
 *     wp-admin TalentTrack pages (TalentTrack menu and its
 *     submenus). Activates when the admin toggle is on.
 *
 * Safe-mode escape hatch: any URL with `?tt_safe_css=1` skips both
 * surfaces, regardless of toggle state, so a non-technical operator
 * can recover from a broken save without database access. The check
 * is intentionally cheap (one `isset` per request).
 *
 * Mutex with #0023 theme inheritance is enforced at the UI layer; the
 * runtime simply respects the flags it's given.
 */
final class CustomCssEnqueue {

    public static function init(): void {
        add_filter( 'body_class',         [ __CLASS__, 'addBodyClass' ] );
        add_filter( 'admin_body_class',   [ __CLASS__, 'addAdminBodyClass' ] );
        add_action( 'wp_head',            [ __CLASS__, 'frontendInjectInline' ], 20 );
        add_action( 'admin_head',         [ __CLASS__, 'adminInjectInline' ], 20 );
    }

    /**
     * Append `tt-root` to the frontend body class so the scoped CSS
     * has a global anchor. Always added — the layout cap also benefits
     * from it being the entry point for theme-isolation rules in
     * frontend-admin.css.
     *
     * @param array<int, string> $classes
     * @return array<int, string>
     */
    public static function addBodyClass( array $classes ): array {
        $classes[] = 'tt-root';
        return $classes;
    }

    /**
     * The wp-admin equivalent. `admin_body_class` is a filter that
     * returns a single space-separated string, not an array.
     */
    public static function addAdminBodyClass( string $classes ): string {
        return trim( $classes . ' tt-root' );
    }

    public static function frontendInjectInline(): void {
        if ( self::isSafeMode() ) return;
        self::emit( CustomCssRepository::SURFACE_FRONTEND );
    }

    public static function adminInjectInline(): void {
        if ( self::isSafeMode() ) return;
        // Only emit on TT-owned wp-admin pages so we don't restyle
        // unrelated admin screens (Posts, Plugins, etc.) — there's no
        // .tt-root wrapper there even though the body class is set,
        // because the wrapper convention is per-page render markup.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) return;
        $page = isset( $_GET['page'] ) ? (string) $_GET['page'] : '';
        if ( strpos( $page, 'tt-' ) !== 0 && strpos( $page, 'talenttrack' ) === false ) return;
        self::emit( CustomCssRepository::SURFACE_ADMIN );
    }

    private static function emit( string $surface ): void {
        $repo = new CustomCssRepository();
        $live = $repo->getLive( $surface );
        if ( ! $live['enabled'] || $live['css'] === '' ) return;

        $cache_buster = (int) $live['version'];
        echo "\n<style id=\"tt-custom-css-{$surface}\" data-tt-css-version=\""
            . esc_attr( (string) $cache_buster )
            . '">' . "\n"
            . $live['css'] . "\n" // intentionally not escaped — operator authors CSS
            . "</style>\n";
    }

    public static function isSafeMode(): bool {
        return isset( $_GET['tt_safe_css'] ) && $_GET['tt_safe_css'] === '1';
    }
}
