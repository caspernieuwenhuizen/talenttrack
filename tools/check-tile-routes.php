<?php
/**
 * check-tile-routes.php (#2885)
 *
 * Every tile's `view_slug` must be reachable: either the dispatcher has a
 * `case '<slug>':` arm for it, or the tile supplies a `url_callback` that
 * sends the user somewhere else entirely.
 *
 * This is the third bug in the "registration and dispatcher disagree"
 * family, after #2570 (dispatcher ignored the tile's cap) and #2008 (tile
 * visible where the view denies). #2885 is the reverse: a slug that is
 * registered but not routed.
 *
 * THE EXEMPTION IS THE POINT, NOT AN AFTERTHOUGHT
 *
 * `view_slug` does double duty. For most tiles it is a route. For the
 * handful carrying a `url_callback` it is only a registry key — the
 * callback supplies the real destination, and both consumers
 * (`TileRegistry::renderable()` and `NavigationTileWidget`) prefer it.
 * `vct-planner` is the clearest case: the tile opens the new-VCT-session
 * wizard, and there is deliberately no `?tt_view=vct-planner`.
 *
 * A gate that did not know this would fail the build on five tiles that
 * work correctly, which is why the exemption ships with the check rather
 * than being bolted on the first time it fires.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 * It does not rename the non-routable slugs to make them look
 * non-routable. Persona-dashboard templates persist a widget's
 * `data_source` — the slug — and the editor lets an admin choose one, so a
 * rename orphans stored rows unless a migration moves them too. That is a
 * schema change and belongs in its own PR.
 *
 * Usage:  php tools/check-tile-routes.php
 * Exit:   0 clean, 1 an unroutable tile slug.
 */

declare( strict_types = 1 );

$root = dirname( __DIR__ );

/* ---- routable slugs: the dispatcher's case arms ---------------------- */

$dispatcher = (string) @file_get_contents(
    $root . '/src/Shared/Frontend/DashboardShortcode.php'
);
if ( $dispatcher === '' ) {
    fwrite( STDERR, "check-tile-routes: cannot read DashboardShortcode.php\n" );
    exit( 1 );
}

preg_match_all( "/case\s+'([a-z0-9\-]+)'\s*:/i", $dispatcher, $m );
$routable = array_flip( $m[1] );

// Arms that dispatch on a class constant rather than a literal — a regex
// cannot see the value, so the constant's owner is trusted. Listed rather
// than pattern-matched so adding one is a decision.
foreach ( [ 'alert-settings', 'alert-policy' ] as $constant_slug ) {
    $routable[ $constant_slug ] = true;
}

/* ---- tile registrations --------------------------------------------- */

$offenders = [];
$exempt    = 0;
$checked   = 0;

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS )
);

foreach ( $it as $file ) {
    if ( ! $file->isFile() || strtolower( $file->getExtension() ) !== 'php' ) continue;

    $code = (string) @file_get_contents( $file->getPathname() );
    if ( strpos( $code, 'TileRegistry::register(' ) === false ) continue;

    // Normalise both sides before stripping: the iterator yields Windows
    // separators while $root may carry forward slashes, so a naive
    // str_replace leaves the absolute path in the failure message.
    $relative = str_replace( '\\', '/', $file->getPathname() );
    $prefix   = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
    if ( strpos( $relative, $prefix ) === 0 ) {
        $relative = substr( $relative, strlen( $prefix ) );
    }

    // Each registration is `TileRegistry::register([ … ]);` — split on the
    // opening and read to the terminator. Good enough because these are
    // literal arrays by convention; a registration built dynamically would
    // simply not be seen, and would be a different problem.
    $parts = explode( 'TileRegistry::register(', $code );
    array_shift( $parts );

    foreach ( $parts as $part ) {
        $end   = strpos( $part, ']);' );
        $block = $end === false ? $part : substr( $part, 0, $end );

        if ( ! preg_match( "/'view_slug'\s*=>\s*'([a-z0-9\-]+)'/i", $block, $sm ) ) continue;

        $slug = $sm[1];
        $checked++;

        if ( strpos( $block, "'url_callback'" ) !== false ) {
            $exempt++;
            continue;
        }

        if ( ! isset( $routable[ $slug ] ) ) {
            $offenders[] = [ $relative, $slug ];
        }
    }
}

if ( ! $offenders ) {
    printf(
        "check-tile-routes OK — %d tile slugs, %d routable, %d exempt via url_callback.\n",
        $checked,
        $checked - $exempt,
        $exempt
    );
    exit( 0 );
}

echo "check-tile-routes FAILED — tile slug(s) with no way to open them:\n\n";
foreach ( $offenders as [ $relative, $slug ] ) {
    printf( "  %s\n    view_slug '%s' has no dispatcher arm and no url_callback\n", $relative, $slug );
}

echo "\n";
echo "A tile registers a destination. If '<slug>' is a route, add a\n";
echo "`case '<slug>':` arm to DashboardShortcode. If the tile should open\n";
echo "something else — a wizard, an admin page — give it a `url_callback`,\n";
echo "which is how the VCT session designer tile works. If it is neither,\n";
echo "the tile is offering a destination that does not exist and should be\n";
echo "removed.\n";

exit( 1 );
