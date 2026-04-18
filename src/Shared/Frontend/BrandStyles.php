<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;

/**
 * BrandStyles — injects CSS variables from branding config into <head>.
 *
 * Always-on shared frontend concern, initialised by the Kernel.
 */
class BrandStyles {

    public static function init( Container $container ): void {
        add_action( 'wp_head', [ __CLASS__, 'injectVars' ], 5 );
    }

    public static function injectVars(): void {
        /** @var \TT\Infrastructure\Config\ConfigService $cfg */
        $cfg = \TT\Core\Kernel::instance()->container()->get( 'config' );

        $primary   = $cfg->get( 'primary_color', '#0b3d2e' );
        $secondary = $cfg->get( 'secondary_color', '#e8b624' );
        $pr = self::hexToRgb( $primary );
        $sr = self::hexToRgb( $secondary );

        echo '<style id="tt-brand-vars">:root{--tt-primary:' . esc_attr( $primary )
            . ';--tt-secondary:' . esc_attr( $secondary )
            . ';--tt-primary-rgb:' . esc_attr( $pr )
            . ';--tt-secondary-rgb:' . esc_attr( $sr )
            . ";}</style>\n";
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
