<?php
/**
 * Plugin Name: TalentTrack
 * Plugin URI:  https://github.com/yourusername/talenttrack
 * Description: Professional player development tracking system for soccer academies — evaluations, team management, goals, attendance, and reporting.
 * Version:     1.0.0
 * Author:      Casper Nieuwenhuizen
 * Author URI:  https://github.com/caspernieuwenhuizen
 * License:     GPL-2.0+
 * Text Domain: talenttrack
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package TalentTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ─── Constants ─────────────────────────────────────────── */
define( 'TT_VERSION',     '1.0.0' );
define( 'TT_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'TT_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'TT_PLUGIN_FILE', __FILE__ );
define( 'TT_PLUGIN_SLUG', 'talenttrack' );

/* ─── Autoloader ────────────────────────────────────────── */
spl_autoload_register( function ( $class ) {
    $prefix = 'TT\\';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $file     = TT_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
});

/* ─── GitHub Update Checker ─────────────────────────────── */
if ( file_exists( TT_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
    require_once TT_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
    \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/yourusername/talenttrack/',
        __FILE__,
        TT_PLUGIN_SLUG
    );
}

/* ─── Activation / Deactivation ─────────────────────────── */
register_activation_hook( __FILE__, [ 'TT\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'TT\\Activator', 'deactivate' ] );

/* ─── Boot ──────────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
    TT\Core::instance()->init();
});
