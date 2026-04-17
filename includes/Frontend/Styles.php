<?php
namespace TT\Frontend;

use TT\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Styles {
    public static function init() {
        add_action( 'wp_head', [ __CLASS__, 'inject_vars' ], 5 );
    }

    public static function inject_vars() {
        $primary   = Helpers::get_config( 'primary_color', '#0b3d2e' );
        $secondary = Helpers::get_config( 'secondary_color', '#e8b624' );
        $pr = self::hex_rgb( $primary );
        $sr = self::hex_rgb( $secondary );
        echo "<style id=\"tt-brand-vars\">:root{--tt-primary:{$primary};--tt-secondary:{$secondary};--tt-primary-rgb:{$pr};--tt-secondary-rgb:{$sr};}</style>\n";
    }

    private static function hex_rgb( $hex ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        return hexdec( substr( $hex, 0, 2 ) ) . ',' . hexdec( substr( $hex, 2, 2 ) ) . ',' . hexdec( substr( $hex, 4, 2 ) );
    }
}
