<?php
/**
 * PHPUnit bootstrap for the TalentTrack PHP test floor (#1388).
 *
 * Runs inside wp-env's `tests-cli` container, which provides a real
 * WordPress + MySQL test environment and the WordPress PHPUnit test
 * library. `WP_PHPUNIT__DIR` is set by wp-env to that library's path.
 *
 * Pipeline: composer autoload (plugin deps + polyfills + test classes)
 * → WP test functions → load the plugin on `muplugin_loaded` → WP test
 * bootstrap → run the plugin migrations once so integration tests
 * (authorization matrix, etc.) have the real schema.
 */

$_root = dirname( __DIR__, 2 );

require $_root . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = '/wordpress-phpunit';
}
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    fwrite(
        STDERR,
        "Could not find the WordPress test suite at {$_tests_dir}.\n" .
        "Run the PHP tests inside wp-env, e.g.:\n" .
        "  npm run wp-env:start\n" .
        "  npx wp-env run tests-cli --env-cwd=wp-content/plugins/talenttrack vendor/bin/phpunit\n"
    );
    exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugin_loaded', static function () use ( $_root ) {
    require $_root . '/talenttrack.php';
} );

require $_tests_dir . '/includes/bootstrap.php';

// Materialise the plugin schema in the test DB so integration tests
// (e.g. the authorization-matrix repository) run against real tables.
// Migrations are idempotent; a failed seed migration is recorded but
// doesn't abort the suite (the tables under test still get created).
if ( class_exists( '\\TT\\Infrastructure\\Database\\MigrationRunner' ) ) {
    ( new \TT\Infrastructure\Database\MigrationRunner() )->run();
}
