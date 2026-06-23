<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;

/**
 * BrandStyles — injects CSS variables from branding config into <head>.
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

    public static function init( Container $container ): void {
        add_action( 'wp_head',           [ __CLASS__, 'injectVars' ], 5 );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueueFonts' ] );
    }

    public static function injectVars(): void {
        /** @var \TT\Infrastructure\Config\ConfigService $cfg */
        $cfg = \TT\Core\Kernel::instance()->container()->get( 'config' );

        $primary   = $cfg->get( 'primary_color', '#0b3d2e' );
        $secondary = $cfg->get( 'secondary_color', '#e8b624' );
        $pr = self::hexToRgb( $primary );
        $sr = self::hexToRgb( $secondary );

        // #0023 — semantic + font tokens. Each new token only emits
        // when the operator set a non-empty value, so unset fields
        // fall through to the defaults declared in frontend-admin.css.
        $optional = [
            '--tt-accent'       => $cfg->get( 'color_accent',  '' ),
            '--tt-danger'       => $cfg->get( 'color_danger',  '' ),
            '--tt-warning'      => $cfg->get( 'color_warning', '' ),
            '--tt-success'      => $cfg->get( 'color_success', '' ),
            '--tt-info'         => $cfg->get( 'color_info',    '' ),
            '--tt-focus-ring'   => $cfg->get( 'color_focus',   '' ),
        ];
        $font_display = (string) $cfg->get( 'font_display', '' );
        $font_body    = (string) $cfg->get( 'font_body',    '' );
        $resolved_display = BrandFonts::resolveFamily( $font_display, BrandFonts::displayCatalogue() );
        $resolved_body    = BrandFonts::resolveFamily( $font_body,    BrandFonts::bodyCatalogue() );
        if ( $resolved_display !== '' ) $optional['--tt-font-display'] = $resolved_display;
        if ( $resolved_body    !== '' ) $optional['--tt-font-body']    = $resolved_body;

        $extra = '';
        foreach ( $optional as $token => $value ) {
            if ( $value === '' ) continue;
            $extra .= ';' . $token . ':' . esc_attr( (string) $value );
        }

        echo '<style id="tt-brand-vars">:root{--tt-primary:' . esc_attr( $primary )
            . ';--tt-secondary:' . esc_attr( $secondary )
            . ';--tt-primary-rgb:' . esc_attr( $pr )
            . ';--tt-secondary-rgb:' . esc_attr( $sr )
            . $extra
            . ";}</style>\n";
    }

    /**
     * Register Google Fonts when the operator picked curated families.
     * Skips the request entirely when both fields are System default
     * or Inherit-from-theme.
     */
    public static function enqueueFonts(): void {
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
