<?php
/**
 * Plugin Name: TalentTrack
 * Plugin URI:  https://github.com/caspernieuwenhuizen/talenttrack
 * Description: Frontend-first, modular youth football talent management system for a single club.
 * Version:     2.17.0
 * Author:      Casper Nieuwenhuizen
 * Author URI:  https://github.com/caspernieuwenhuizen
 * License:     GPL-2.0+
 * Text Domain: talenttrack
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package TalentTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TT_VERSION',     '2.17.0' );
define( 'TT_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'TT_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'TT_PLUGIN_FILE', __FILE__ );
define( 'TT_PLUGIN_SLUG', 'talenttrack' );

if ( file_exists( TT_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once TT_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register( function ( $class ) {
        $prefix = 'TT\\';
        if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
            return;
        }
        $relative = substr( $class, strlen( $prefix ) );
        $file     = TT_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    });
}

if ( file_exists( TT_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
    require_once TT_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
    \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/caspernieuwenhuizen/talenttrack/',
        __FILE__,
        TT_PLUGIN_SLUG
    );
}

register_activation_hook( __FILE__, [ 'TT\\Core\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'TT\\Core\\Activator', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'talenttrack', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 1 );

add_action( 'plugins_loaded', function () {
    TT\Core\Kernel::instance()->boot();
}, 5 );

add_action( 'plugins_loaded', function () {
    if ( is_admin() && class_exists( 'TT\\Shared\\Admin\\MenuExtension' ) ) {
        TT\Shared\Admin\MenuExtension::init();
    }
}, 10 );
