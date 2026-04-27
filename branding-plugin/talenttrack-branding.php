<?php
/**
 * Plugin Name: TalentTrack Branding
 * Plugin URI:  https://github.com/caspernieuwenhuizen/talenttrack
 * Description: Marketing site for TalentTrack. Provides the public pages (home, features, pricing, pilot, demo, about, contact) for mediamaniacs.nl as a single-zip plugin. Separate from the TalentTrack plugin itself, which lives at jg4it.mediamaniacs.nl.
 * Version:     0.1.0
 * Author:      Casper Nieuwenhuizen
 * License:     GPL-2.0+
 * Text Domain: talenttrack-branding
 *
 * @package TalentTrackBranding
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TTB_VERSION',     '0.1.0' );
define( 'TTB_PLUGIN_FILE', __FILE__ );
define( 'TTB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'TTB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// PSR-4 autoloader for the plugin's own classes. Composer-free so the
// zip is small and self-contained.
spl_autoload_register( function ( $class ) {
    $prefix = 'TTB\\';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) return;
    $relative = substr( $class, strlen( $prefix ) );
    $file     = TTB_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( file_exists( $file ) ) require_once $file;
} );

register_activation_hook( __FILE__, [ 'TTB\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'TTB\\Activator', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    TTB\Plugin::boot();
}, 5 );
