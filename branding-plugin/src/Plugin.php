<?php
namespace TTB;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Plugin — boot wiring for talenttrack-branding (#0030).
 *
 * Registers the seven shortcodes that render the marketing pages,
 * enqueues the brand stylesheet (only when one of those shortcodes
 * is on the current page so we don't pollute every theme route),
 * and hooks the wp-admin settings page.
 */
final class Plugin {

    /** @var array<string, class-string> */
    private const SHORTCODES = [
        'tt_brand_home'     => Pages\HomePage::class,
        'tt_brand_features' => Pages\FeaturesPage::class,
        'tt_brand_pricing'  => Pages\PricingPage::class,
        'tt_brand_pilot'    => Pages\PilotPage::class,
        'tt_brand_demo'     => Pages\DemoPage::class,
        'tt_brand_about'    => Pages\AboutPage::class,
        'tt_brand_contact'  => Pages\ContactPage::class,
    ];

    public static function boot(): void {
        load_plugin_textdomain( 'talenttrack-branding', false, dirname( plugin_basename( TTB_PLUGIN_FILE ) ) . '/languages' );

        // Each shortcode is wired to the static `render( $atts )` method
        // on its page class. The page handles the full HTML and pulls in
        // the layout wrapper itself.
        foreach ( self::SHORTCODES as $tag => $class ) {
            add_shortcode( $tag, [ $class, 'render' ] );
        }

        add_action( 'wp_enqueue_scripts',   [ self::class, 'enqueueAssets' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueueAdminAssets' ] );

        // Replace the WP-default page-not-found feel for our pages: when
        // a brand shortcode is on the current page, drop the page title
        // (we render our own hero) and remove site-furniture clutter.
        add_filter( 'the_title', [ self::class, 'maybeHidePageTitle' ], 20, 2 );
        add_filter( 'body_class', [ self::class, 'maybeAddBodyClass' ] );

        // Contact form handler.
        add_action( 'admin_post_nopriv_ttb_contact', [ Pages\ContactPage::class, 'handleSubmit' ] );
        add_action( 'admin_post_ttb_contact',         [ Pages\ContactPage::class, 'handleSubmit' ] );

        if ( is_admin() ) {
            Admin\SettingsPage::init();
        }
    }

    public static function enqueueAssets(): void {
        if ( ! self::currentPostHasBrandShortcode() ) return;
        wp_enqueue_style(
            'ttb-brand',
            TTB_PLUGIN_URL . 'assets/css/branding.css',
            [],
            TTB_VERSION
        );
    }

    public static function enqueueAdminAssets( string $hook = '' ): void {
        if ( strpos( $hook, 'ttb-' ) === false ) return;
        wp_enqueue_style(
            'ttb-admin',
            TTB_PLUGIN_URL . 'assets/css/admin.css',
            [],
            TTB_VERSION
        );
    }

    /**
     * Drop the WP-default `<h1 class="page-title">…</h1>` on pages that
     * carry one of our shortcodes — every page renders its own hero.
     */
    public static function maybeHidePageTitle( string $title, int $post_id = 0 ): string {
        if ( ! is_singular() || ! in_the_loop() ) return $title;
        if ( ! self::currentPostHasBrandShortcode( $post_id ) ) return $title;
        return '';
    }

    /**
     * @param array<int, string> $classes
     * @return array<int, string>
     */
    public static function maybeAddBodyClass( array $classes ): array {
        if ( self::currentPostHasBrandShortcode() ) {
            $classes[] = 'ttb-page';
        }
        return $classes;
    }

    private static function currentPostHasBrandShortcode( int $post_id = 0 ): bool {
        if ( ! $post_id ) {
            $post = get_post();
            if ( ! $post ) return false;
            $post_id = (int) $post->ID;
        }
        $content = (string) get_post_field( 'post_content', $post_id );
        if ( $content === '' ) return false;
        foreach ( array_keys( self::SHORTCODES ) as $tag ) {
            if ( has_shortcode( $content, $tag ) ) return true;
        }
        return false;
    }
}
