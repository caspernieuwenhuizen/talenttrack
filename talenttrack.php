<?php
/**
 * Plugin Name: TalentTrack
 * Plugin URI:  https://github.com/caspernieuwenhuizen/talenttrack
 * Description: Frontend-first, modular youth football talent management system for a single club.
 * Version:     3.110.56
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

define( 'TT_VERSION',     '3.110.56' );
define( 'TT_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'TT_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'TT_PLUGIN_FILE', __FILE__ );
define( 'TT_PLUGIN_SLUG', 'talenttrack' );

// v3.110.54 — Commercial mode toggle.
//
// When TRUE: the License module enforces tiers — `LicenseGate::tier()`
//   resolves through DevOverride → Trial → Freemius → Free, free-tier
//   caps apply, the AccountPage renders the trial / upgrade UI, and
//   non-Pro features are gated behind purchases. Freemius credentials
//   (TT_FREEMIUS_PRODUCT_ID + TT_FREEMIUS_PUBLIC_KEY) need to be wired
//   for actual checkout to work.
//
// When FALSE (default): the install is treated as a non-commercial
//   test instance owned by the developer. Every feature is unlocked,
//   free-tier caps don't apply, the trial / upgrade UI hides, and
//   `LicenseGate::tier()` returns Pro. Trial state on disk is
//   preserved but ignored at runtime.
//
// Flip this to TRUE the day a paying customer goes live (and wire
// Freemius alongside). One-line switch, no other code changes
// required to enter commercial mode.
define( 'TT_COMMERCIAL_MODE', false );

// v2.22.0 / v3.0.0 aliases used by newer classes (HelpTopics, SchemaStatus)
// so they don't need to choose between TT_PLUGIN_DIR / TT_PLUGIN_FILE naming.
define( 'TT_PATH', TT_PLUGIN_DIR );
define( 'TT_FILE', TT_PLUGIN_FILE );

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
    $tt_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/caspernieuwenhuizen/talenttrack/',
        __FILE__,
        TT_PLUGIN_SLUG
    );
    // Repo default branch is `main`; without this PUC falls back to `master` and 404s.
    $tt_update_checker->setBranch( 'main' );
    // Prefer the release asset (talenttrack.zip attached by the GH Action) over the
    // auto-generated source zipball, which has the wrong folder name.
    $tt_update_checker->getVcsApi()->enableReleaseAssets();
    // Authenticated GitHub API calls get 5000/hr instead of 60/hr unauthenticated.
    // Token is read from wp-config.php so it never enters the repo. Define in wp-config:
    //   define( 'TT_GITHUB_PAT', 'ghp_xxxxxx' );
    // For a public repo this token needs ZERO scopes (just signed identity).
    if ( defined( 'TT_GITHUB_PAT' ) && TT_GITHUB_PAT ) {
        $tt_update_checker->setAuthentication( TT_GITHUB_PAT );
    }
}

register_activation_hook( __FILE__, [ 'TT\\Core\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'TT\\Core\\Activator', 'deactivate' ] );

// #0065 Admin Center phone-home — fire on activate (single-shot 30s out)
// + best-effort on deactivate. Failure is silent and doesn't block either
// lifecycle event.
register_activation_hook( __FILE__, [ 'TT\\Modules\\AdminCenterClient\\Hooks\\ActivationHook', 'schedule' ] );
register_deactivation_hook( __FILE__, [ 'TT\\Modules\\AdminCenterClient\\Hooks\\DeactivationHook', 'fire' ] );

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
    // #0077 F4 — module-completeness dev report. Self-gates on WP_DEBUG.
    if ( is_admin() && class_exists( 'TT\\Infrastructure\\Diagnostics\\ModuleCompletenessPage' ) ) {
        TT\Infrastructure\Diagnostics\ModuleCompletenessPage::init();
    }
}, 10 );
