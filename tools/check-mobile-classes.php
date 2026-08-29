<?php
/**
 * Mobile classification gate (#2812).
 *
 * `config/mobile_surfaces.php` records what a phone gets for every
 * routable `?tt_view=` surface. The previous classification was populated
 * once in #0084 and then went untouched through roughly twenty new
 * modules, which is how 125 of 151 surfaces came to resolve to `viewable`
 * by default rather than by decision. #2807 rebuilt it by hand. Without
 * something that fails the build, it degrades exactly the same way again.
 *
 * Four assertions:
 *
 *   1. Every routable slug has an entry in the manifest.
 *   2. Every entry names one of the four known classes.
 *   3. Every entry carries a reason, because the reason is the decision;
 *      a class with no reason is the default wearing a disguise.
 *   4. No entry names a slug the dispatcher no longer routes.
 *
 * ON READING THE DISPATCHER
 *
 * The slug set is derived from `DashboardShortcode` rather than declared,
 * so the gate cannot drift from what is actually reachable. Deriving it
 * naively is the trap — a `case '<slug>':` grep misses constant arms and
 * the pre-auth routes handled above the dispatch chain — which is why the
 * deriver lives in `tools/lib/routable-slugs.php` and is shared with the
 * docs and tile-route gates (#3022). Missing either class of route would
 * mean this gate passes while the surface it should have caught goes
 * unclassified, which is worse than no gate: it reports a guarantee it is
 * not making.
 *
 * Usage: php tools/check-mobile-classes.php
 * Exit:  0 clean, 1 findings, 2 tooling error.
 */

declare(strict_types=1);

$root       = dirname( __DIR__ );
$dispatcher = $root . '/src/Shared/Frontend/DashboardShortcode.php';
$manifest   = $root . '/config/mobile_surfaces.php';

require_once __DIR__ . '/lib/routable-slugs.php';

foreach ( [ $dispatcher, $manifest ] as $required ) {
    if ( ! is_file( $required ) ) {
        fwrite( STDERR, 'check-mobile-classes: missing ' . substr( $required, strlen( $root ) + 1 ) . "\n" );
        exit( 2 );
    }
}

const TT_MOBILE_CLASSES = [ 'native', 'viewable', 'read_only', 'desktop_only' ];

$errors = [];
$notes  = [];

// ---------------------------------------------------------------
// Sources
// ---------------------------------------------------------------

/** @var array<string,array{0:string,1:string}> $surfaces slug => [class, reason] */
$surfaces = require $manifest;

if ( ! is_array( $surfaces ) ) {
    fwrite( STDERR, "check-mobile-classes: config/mobile_surfaces.php did not return an array\n" );
    exit( 2 );
}

[ $routable, $unresolved ] = tt_routable_slugs( $root, $dispatcher );

if ( $routable === [] ) {
    fwrite( STDERR, "check-mobile-classes: parsed no routable slugs at all — the dispatcher shape has changed and this gate is blind\n" );
    exit( 2 );
}

foreach ( $unresolved as $where ) {
    $notes[] = "Route at {$where} is built from something this gate cannot resolve statically. Classify it by hand if it is reachable.";
}

// ---------------------------------------------------------------
// 1. Every routable slug is classified
// ---------------------------------------------------------------

foreach ( $routable as $slug => $where ) {
    if ( ! array_key_exists( $slug, $surfaces ) ) {
        $errors[] = "`?tt_view={$slug}` ({$where}) has no entry in config/mobile_surfaces.php. "
            . 'Add it with the class a phone should get and one sentence saying why.';
    }
}

// ---------------------------------------------------------------
// 2 + 3. Entries name a known class and carry a reason
// ---------------------------------------------------------------

foreach ( $surfaces as $slug => $entry ) {
    $slug = (string) $slug;

    if ( ! is_array( $entry ) || ! isset( $entry[0] ) ) {
        $errors[] = "config/mobile_surfaces.php — `{$slug}` is not a [ class, reason ] pair.";
        continue;
    }

    $class  = (string) $entry[0];
    $reason = trim( (string) ( $entry[1] ?? '' ) );

    if ( ! in_array( $class, TT_MOBILE_CLASSES, true ) ) {
        $errors[] = "config/mobile_surfaces.php — `{$slug}` names class `{$class}`, which is not one of "
            . implode( ', ', TT_MOBILE_CLASSES ) . '. MobileSurfaceRegistry would silently fall back to viewable.';
    }

    if ( $reason === '' ) {
        $errors[] = "config/mobile_surfaces.php — `{$slug}` has no reason text. The reason is the decision; without it the entry records nothing.";
    }
}

// ---------------------------------------------------------------
// 4. No entry for a slug that no longer routes
// ---------------------------------------------------------------

foreach ( array_keys( $surfaces ) as $slug ) {
    if ( ! isset( $routable[ (string) $slug ] ) ) {
        $errors[] = "config/mobile_surfaces.php — `{$slug}` is classified but the dispatcher does not route it. Remove the entry.";
    }
}

// ---------------------------------------------------------------
// Report
// ---------------------------------------------------------------

foreach ( $notes as $note ) {
    echo "note: {$note}\n";
}

if ( $errors !== [] ) {
    echo "\n";
    foreach ( $errors as $error ) {
        echo "FAIL: {$error}\n";
    }
    printf(
        "\ncheck-mobile-classes: %d finding(s) across %d routable surfaces.\n",
        count( $errors ),
        count( $routable )
    );
    exit( 1 );
}

printf(
    "check-mobile-classes: clean — %d routable surfaces, all classified.\n",
    count( $routable )
);
exit( 0 );
