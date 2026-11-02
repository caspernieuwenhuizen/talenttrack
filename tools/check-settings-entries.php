<?php
/**
 * check-settings-entries.php (#4670)
 *
 * Every settings screen must have a door on the Configuration landing.
 * Custom fields, evaluation categories and their weights had frontend
 * screens with no inbound link at all — only the wp-admin page listed them
 * — which is one reason an administrator "could not find some settings
 * anymore". This gate fails a settings route that has no entry in
 * config/settings_entries.php, and an entry declared twice or filed under
 * something that is not a settings section.
 *
 * The routes it knows are described in tools/lib/settings-entries.php. A
 * route that deliberately has no entry goes in `not_entries`, with the
 * reason.
 *
 * Usage:  php tools/check-settings-entries.php
 * Exit:   0 clean, 1 a route without an entry or a malformed entry.
 */

declare( strict_types = 1 );

require __DIR__ . '/lib/settings-entries.php';

$root   = dirname( __DIR__ );
$config = tt_settings_require( $root . '/config/settings_entries.php' );
$routes = tt_settings_routes( $root );

if ( count( $routes ) < 10 || ( $config['entries'] ?? [] ) === [] ) {
    fwrite( STDERR, "check-settings-entries: found almost no settings routes or entries — the source shape has changed and this gate is blind\n" );
    exit( 1 );
}

$sections = [];
foreach ( tt_settings_require( $root . '/config/navigation_groups.php' ) as $group ) {
    if ( is_array( $group ) && ( $group['surface'] ?? '' ) === 'settings' ) $sections[] = (string) $group['key'];
}

$missing  = tt_settings_missing( $routes, $config );
$problems = tt_settings_problems( $config, $sections );

if ( $missing === [] && $problems === [] ) {
    printf( "check-settings-entries OK — %d settings routes, %d entries, each route with a door.\n", count( $routes ), count( (array) $config['entries'] ) );
    exit( 0 );
}

echo "check-settings-entries FAILED\n\n";
foreach ( $missing as $route ) {
    printf( "  %s has no entry in config/settings_entries.php\n", $route );
}
foreach ( $problems as $problem ) {
    printf( "  %s\n", $problem );
}
echo "\n";
echo "Add an entry to config/settings_entries.php under the section an admin\n";
echo "would look in, or list the route in `not_entries` with the reason it has\n";
echo "no entry. See docs/configuration-branding.md.\n";
exit( 1 );
