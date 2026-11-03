<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;

/**
 * BrandStyles — the academy's Branding colours and fonts, attached to
 * `tokens.css` on `.tt-root` (#4797) on the default design.
 *
 * Layer order: `tokens.css` declares every default; a theme sheet then
 * owns the whole design and Branding is not output (#2515); without a
 * theme, the Branding rule printed right after `tokens.css` overrides the
 * colours and fonts the academy actually chose.
 *
 * Always-on shared frontend concern, initialised by the Kernel.
 *
 * #0023 Sprint 1: extended to also emit semantic-color + font tokens
 * for the new Branding-tab fields and register a Google Fonts request
 * when the operator picked a curated family. (Theme inheritance was
 * removed in #1728 — canvas mode now gives total visual isolation, so
 * deferring styling to the host theme no longer makes sense.)
 */
class BrandStyles {

    /**
     * The values each Branding colour field shows when nothing was chosen
     * (`FrontendConfigurationView` and `ConfigurationPage`). The form
     * submits every colour on every save, so a stored value equal to its
     * form default says nothing about what the academy wanted.
     *
     * Only colours with a `tokens.css` default are listed. The accent and
     * the focus ring have none, so their form default (`#1e88e5`) is what
     * a saved install renders today; treating it as unset would change
     * every such install.
     */
    private const FORM_DEFAULTS = [
        'primary_color'   => '#0b3d2e',
        'secondary_color' => '#e8b624',
        'color_danger'    => '#b32d2e',
        'color_warning'   => '#dba617',
        'color_success'   => '#00a32a',
        'color_info'      => '#2271b1',
    ];

    /** The status colours, which some installs hold as a stored `#000000`. */
    private const STATUS_KEYS = [ 'color_danger', 'color_warning', 'color_success', 'color_info' ];

    /** Config key → token it sets on `.tt-root`. */
    private const COLOR_TOKENS = [
        'primary_color'   => '--tt-primary',
        'secondary_color' => '--tt-secondary',
        'color_accent'    => '--tt-accent',
        'color_danger'    => '--tt-danger',
        'color_warning'   => '--tt-warning',
        'color_success'   => '--tt-success',
        'color_info'      => '--tt-info',
        'color_focus'     => '--tt-focus-ring',
    ];

    public static function init( Container $container ): void {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'attachVars' ], 5 );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueueFonts' ] );
    }

    /**
     * Whether a stored Branding colour counts as chosen (#4797). Empty, the
     * form's own default, or `#000000` for a status colour all count as
     * unset, so that colour falls back to `tokens.css` instead of turning a
     * site's alerts black.
     */
    public static function isChosen( string $key, string $value ): bool {
        $value = strtolower( trim( $value ) );
        if ( $value === '' ) return false;
        if ( isset( self::FORM_DEFAULTS[ $key ] ) && $value === self::FORM_DEFAULTS[ $key ] ) return false;
        if ( in_array( $key, self::STATUS_KEYS, true ) && $value === '#000000' ) return false;
        return true;
    }

    /**
     * The Branding rule for `.tt-root`, or '' when the academy chose
     * nothing. Only the values it chose are written, so every other token
     * keeps its `tokens.css` default.
     */
    public static function css(): string {
        /** @var \TT\Infrastructure\Config\ConfigService $cfg */
        $cfg = \TT\Core\Kernel::instance()->container()->get( 'config' );

        $decls = [];
        foreach ( self::COLOR_TOKENS as $key => $token ) {
            $value = (string) $cfg->get( $key, '' );
            if ( ! self::isChosen( $key, $value ) ) continue;
            $decls[] = $token . ':' . esc_attr( $value );
            if ( $key === 'primary_color' )   $decls[] = '--tt-primary-rgb:' . esc_attr( self::hexToRgb( $value ) );
            if ( $key === 'secondary_color' ) $decls[] = '--tt-secondary-rgb:' . esc_attr( self::hexToRgb( $value ) );
        }

        $resolved_display = BrandFonts::resolveFamily( (string) $cfg->get( 'font_display', '' ), BrandFonts::displayCatalogue() );
        $resolved_body    = BrandFonts::resolveFamily( (string) $cfg->get( 'font_body',    '' ), BrandFonts::bodyCatalogue() );
        if ( $resolved_display !== '' ) $decls[] = '--tt-font-display:' . esc_attr( $resolved_display );
        if ( $resolved_body    !== '' ) $decls[] = '--tt-font-body:' . esc_attr( $resolved_body );

        return $decls ? '.tt-root{' . implode( ';', $decls ) . '}' : '';
    }

    /**
     * #4797 — Branding is attached to `tokens.css` itself, on `.tt-root`,
     * so it prints right after the defaults it overrides and wins by
     * order at the same specificity. It used to be a `:root` block, which
     * the `.tt-root` defaults (a closer ancestor) beat for every token
     * `tokens.css` declares: on the default design only the accent and the
     * focus ring ever followed Branding.
     *
     * Registering the handle here is what lets the rule attach before the
     * shortcode enqueues the sheet; every entry point enqueues the same
     * handle with the same source.
     */
    public static function attachVars(): void {
        // #2515 — a theme owns the complete visual design. The club keeps its
        // identity (logo, name), but not the palette: emitting club colours
        // under a theme put a club-coloured brand header above a themed rail,
        // which is the exact clash the theme exists to prevent.
        if ( ThemePreference::hasThemeSheet() ) {
            return;
        }

        $css = self::css();
        if ( $css === '' ) {
            return;
        }

        if ( ! wp_style_is( 'tt-tokens', 'registered' ) ) {
            wp_register_style( 'tt-tokens', TT_PLUGIN_URL . 'assets/css/tokens.css', [], TT_VERSION );
        }
        wp_add_inline_style( 'tt-tokens', $css );
    }

    /**
     * Register Google Fonts when the operator picked curated families.
     * Skips the request entirely when both fields are System default
     * or Inherit-from-theme.
     */
    public static function enqueueFonts(): void {
        // #2515 — a theme owns the type as well as the colour, so the club's
        // font choice does not apply. Skipping the request outright also
        // spares the page a third-party fetch whose result nothing would use.
        if ( ThemePreference::hasThemeSheet() ) {
            return;
        }

        /** @var \TT\Infrastructure\Config\ConfigService $cfg */
        $cfg = \TT\Core\Kernel::instance()->container()->get( 'config' );
        $url = BrandFonts::googleFontsUrl(
            (string) $cfg->get( 'font_display', '' ),
            (string) $cfg->get( 'font_body',    '' )
        );
        if ( $url === '' ) return;
        wp_enqueue_style( 'tt-brand-fonts', $url, [], null );
    }

    private static function hexToRgb( string $hex ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( strlen( $hex ) !== 6 ) {
            return '11,61,46';
        }
        return hexdec( substr( $hex, 0, 2 ) )
            . ',' . hexdec( substr( $hex, 2, 2 ) )
            . ',' . hexdec( substr( $hex, 4, 2 ) );
    }
}
