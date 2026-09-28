<?php
/**
 * check-tile-icons.php (#4168)
 *
 * Every icon a tile registers must exist in BOTH icon sets:
 *
 *   - assets/icons/<name>.svg          the line set. The shell nav (sidebar,
 *                                      rail, bottom bar) renders it through
 *                                      IconRenderer::render(), which returns
 *                                      an empty string when the file is
 *                                      missing — the destination shows up in
 *                                      the nav with no icon at all.
 *   - assets/icons/duotone/<name>.svg  the duotone set. Dashboard tiles render
 *                                      it through renderDuotone(), which falls
 *                                      back to the line icon, so a missing
 *                                      duotone file degrades rather than
 *                                      disappears — but the tile grid then
 *                                      mixes two visual languages.
 *
 * Injuries (`alert`), Team development (`sliders`) and three others shipped
 * with the duotone file only, and nothing noticed until somebody looked at
 * the sidebar. This gate is the thing that notices.
 *
 * Registrations are read as literal arrays, the same way
 * check-tile-routes.php reads them. A tile whose icon is computed at runtime
 * is not seen here; TileIconVariantsTest covers the registered set.
 *
 * Usage:  php tools/check-tile-icons.php
 * Exit:   0 clean, 1 a tile icon missing a variant.
 */

declare( strict_types = 1 );

$root     = dirname( __DIR__ );
$lineDir  = $root . '/assets/icons/';
$duoDir   = $root . '/assets/icons/duotone/';

$offenders = [];
$icons     = [];
$checked   = 0;

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS )
);

foreach ( $it as $file ) {
    if ( ! $file->isFile() || strtolower( $file->getExtension() ) !== 'php' ) continue;

    $code = (string) @file_get_contents( $file->getPathname() );
    if ( strpos( $code, 'TileRegistry::register(' ) === false ) continue;

    $relative = str_replace( '\\', '/', $file->getPathname() );
    $prefix   = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
    if ( strpos( $relative, $prefix ) === 0 ) {
        $relative = substr( $relative, strlen( $prefix ) );
    }

    $parts = explode( 'TileRegistry::register(', $code );
    array_shift( $parts );

    foreach ( $parts as $part ) {
        $end   = strpos( $part, ']);' );
        $block = $end === false ? $part : substr( $part, 0, $end );

        if ( ! preg_match( "/'icon'\s*=.\s*'([^']*)'/", $block, $im ) ) continue;

        $icon = $im[1];
        // An empty icon is the registry's own default (`'icon' => ''` in
        // TileRegistry::register()), not a tile asking for a file.
        if ( $icon === '' ) continue;
        $slug = preg_match( "/'view_slug'\s*=.\s*'([^']+)'/", $block, $sm ) ? $sm[1] : '?';
        $checked++;
        $icons[ $icon ] = true;

        $missing = [];
        if ( ! preg_match( '/^[a-z0-9-]+$/', $icon ) ) {
            $missing[] = 'a valid icon key (lowercase letters, digits, dashes)';
        } else {
            if ( ! is_file( $lineDir . $icon . '.svg' ) ) {
                $missing[] = 'assets/icons/' . $icon . '.svg';
            }
            if ( ! is_file( $duoDir . $icon . '.svg' ) ) {
                $missing[] = 'assets/icons/duotone/' . $icon . '.svg';
            }
        }
        if ( $missing ) {
            $offenders[] = [ $relative, $slug, $icon, $missing ];
        }
    }
}

if ( $checked === 0 ) {
    fwrite( STDERR, "check-tile-icons: found no tile icons at all — the registration shape has changed and this gate is blind\n" );
    exit( 1 );
}

if ( ! $offenders ) {
    printf( "check-tile-icons OK — %d tiles, %d distinct icons, each in both sets.\n", $checked, count( $icons ) );
    exit( 0 );
}

echo "check-tile-icons FAILED — tile icon(s) missing a variant:\n\n";
foreach ( $offenders as [ $relative, $slug, $icon, $missing ] ) {
    printf( "  %s\n    tile '%s' uses icon '%s' but there is no %s\n", $relative, $slug, $icon, implode( ' and no ', $missing ) );
}

echo "\n";
echo "The shell nav draws the line icon and the dashboard tile draws the\n";
echo "duotone one, so a tile icon needs both. Line icons are 24x24 viewBox,\n";
echo "fill none, stroke currentColor, stroke-width 1.5, round caps and joins\n";
echo "(see assets/icons/card.svg). Duotone icons are the Phosphor duotone\n";
echo "variant on a 256 viewBox.\n";

exit( 1 );
