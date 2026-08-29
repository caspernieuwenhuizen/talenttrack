<?php
/**
 * Self-check for the mobile classification gate (#2812).
 *
 * A gate that passes is not evidence that it works — it might pass on
 * everything. This runs `check-mobile-classes.php` against deliberately
 * broken copies of the tree and asserts it fails on each, and only for the
 * right reason.
 *
 * Mirrors `bin/module-toggle-selfcheck.php`, which exists for the same
 * reason.
 *
 * Two of the cases below matter more than the rest. The gate's whole
 * justification is that a naive `case '<slug>':` grep misses seven live
 * routes — the constant arms and the pre-auth comparisons. If the deriver
 * quietly stopped resolving either, the gate would still pass on the real
 * tree and still report a guarantee it was no longer making. So both are
 * proven by removing a manifest entry for a slug only reachable that way
 * and asserting the gate notices it is gone.
 *
 * Usage: php bin/mobile-class-selfcheck.php
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$tool = $root . '/tools/check-mobile-classes.php';

$failures = 0;

/**
 * Copy the files the gate reads into a scratch root, apply a mutation, and
 * run the gate against it.
 *
 * @param callable(string):void $mutate
 * @return array{0:int, 1:string} exit code, combined output
 */
function tt_run_against( string $root, callable $mutate ): array {
    $scratch = sys_get_temp_dir() . '/tt-mobile-selfcheck-' . bin2hex( random_bytes( 6 ) );

    foreach ( [ '/config', '/tools/lib', '/src/Shared/Frontend' ] as $dir ) {
        @mkdir( $scratch . $dir, 0777, true );
    }

    copy( $root . '/config/mobile_surfaces.php', $scratch . '/config/mobile_surfaces.php' );
    copy( $root . '/tools/check-mobile-classes.php', $scratch . '/tools/check-mobile-classes.php' );
    // #3022 — the routable-slug deriver moved out of this gate and is shared
    // with the docs and tile-route gates. The scratch tree is what the gate
    // actually runs against, so it needs the library too.
    copy( $root . '/tools/lib/routable-slugs.php', $scratch . '/tools/lib/routable-slugs.php' );
    copy(
        $root . '/src/Shared/Frontend/DashboardShortcode.php',
        $scratch . '/src/Shared/Frontend/DashboardShortcode.php'
    );
    // The constant arms resolve by reading the class they name, so the
    // classes carrying a SLUG the dispatcher routes have to come too.
    tt_copy_tree( $root . '/src/Modules', $scratch . '/src/Modules' );

    $mutate( $scratch );

    $output = [];
    $code   = 0;
    exec( 'php ' . escapeshellarg( $scratch . '/tools/check-mobile-classes.php' ), $output, $code );

    tt_rmrf( $scratch );

    return [ $code, implode( "\n", $output ) ];
}

function tt_copy_tree( string $from, string $to ): void {
    if ( ! is_dir( $from ) ) return;
    @mkdir( $to, 0777, true );

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ( $items as $item ) {
        $target = $to . DIRECTORY_SEPARATOR . $items->getSubPathName();
        if ( $item->isDir() ) { @mkdir( $target, 0777, true ); continue; }
        if ( substr( $item->getFilename(), -4 ) !== '.php' ) continue;
        @copy( $item->getPathname(), $target );
    }
}

function tt_rmrf( string $dir ): void {
    if ( ! is_dir( $dir ) ) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $items as $item ) {
        $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
    }
    @rmdir( $dir );
}

/** Drop one slug's entry from the manifest, leaving the route in place. */
function tt_unclassify( string $scratch, string $slug ): void {
    $file = $scratch . '/config/mobile_surfaces.php';
    $src  = (string) file_get_contents( $file );
    $src  = preg_replace( "/^\s*'" . preg_quote( $slug, '/' ) . "'\s*=>.*$\n/m", '', $src, 1 );
    file_put_contents( $file, (string) $src );
}

/** @param callable(string):void $mutate */
function tt_expect_failure( string $root, string $label, string $needle, callable $mutate ): void {
    global $failures;

    [ $code, $output ] = tt_run_against( $root, $mutate );

    if ( $code === 0 ) {
        echo "FAIL  {$label} — the gate passed on a tree that should fail\n";
        $failures++;
        return;
    }
    if ( strpos( $output, $needle ) === false ) {
        echo "FAIL  {$label} — the gate failed, but not for the expected reason\n";
        echo "      wanted a message containing: {$needle}\n";
        echo "      got: " . trim( $output ) . "\n";
        $failures++;
        return;
    }

    echo "PASS  {$label}\n";
}

/**
 * The other direction: a mutation the gate should be relaxed about. A gate
 * that fails on everything is as useless as one that passes on everything.
 *
 * @param callable(string):void $mutate
 */
function tt_expect_success( string $root, string $label, callable $mutate ): void {
    global $failures;

    [ $code, $output ] = tt_run_against( $root, $mutate );

    if ( $code !== 0 ) {
        echo "FAIL  {$label} — the gate failed on a tree that should pass\n";
        echo "      " . trim( $output ) . "\n";
        $failures++;
        return;
    }

    echo "PASS  {$label}\n";
}

// ── the gate is clean on the real tree ─────────────────────────────────

$output = [];
$code   = 0;
exec( 'php ' . escapeshellarg( $tool ), $output, $code );

if ( $code === 0 ) {
    echo "PASS  clean on the current tree\n";
} else {
    echo "FAIL  the gate does not pass on the current tree:\n" . implode( "\n", $output ) . "\n";
    $failures++;
}

// ── and it bites ───────────────────────────────────────────────────────

tt_expect_failure(
    $root,
    'an unclassified surface is caught',
    'has no entry in config/mobile_surfaces.php',
    static fn( string $scratch ) => tt_unclassify( $scratch, 'players' )
);

// The two that justify the token walk. `alert-settings` is routed only by
// `case FrontendAlertSettingsView::SLUG:`, and `accept-invite` only by a
// pre-auth `$tt_view_param === 'accept-invite'` above the dispatch chain.
// A naive grep for `case '<slug>':` sees neither, so if either of these
// stops failing, the deriver has regressed to that grep.

tt_expect_failure(
    $root,
    'a constant-routed surface is still seen (alert-settings)',
    "`?tt_view=alert-settings`",
    static fn( string $scratch ) => tt_unclassify( $scratch, 'alert-settings' )
);

tt_expect_failure(
    $root,
    'a pre-auth surface is still seen (accept-invite)',
    "`?tt_view=accept-invite`",
    static fn( string $scratch ) => tt_unclassify( $scratch, 'accept-invite' )
);

tt_expect_failure(
    $root,
    'an entry with no reason is caught',
    'has no reason text',
    static function ( string $scratch ): void {
        $file = $scratch . '/config/mobile_surfaces.php';
        $src  = (string) file_get_contents( $file );
        $src  = preg_replace(
            "/^(\s*'players'\s*=>\s*\[\s*'[a-z_]+',\s*)'[^']*'/m",
            "$1''",
            $src,
            1
        );
        file_put_contents( $file, (string) $src );
    }
);

tt_expect_failure(
    $root,
    'an unknown class name is caught',
    'is not one of',
    static function ( string $scratch ): void {
        $file = $scratch . '/config/mobile_surfaces.php';
        $src  = (string) file_get_contents( $file );
        $src  = preg_replace( "/^(\s*'players'\s*=>\s*\[\s*)'[a-z_]+'/m", "$1'phone_only'", $src, 1 );
        file_put_contents( $file, (string) $src );
    }
);

tt_expect_failure(
    $root,
    'an entry for a route that no longer exists is caught',
    'the dispatcher does not route it',
    static function ( string $scratch ): void {
        $file = $scratch . '/config/mobile_surfaces.php';
        $src  = (string) file_get_contents( $file );
        $src  = str_replace( "    'players'  ", "    'playerz'  ", $src );
        file_put_contents( $file, $src );
    }
);

// ── and it is not merely failing on everything ─────────────────────────

tt_expect_success(
    $root,
    'rewording a reason is fine',
    static function ( string $scratch ): void {
        $file = $scratch . '/config/mobile_surfaces.php';
        $src  = (string) file_get_contents( $file );
        $src  = preg_replace(
            "/^(\s*'players'\s*=>\s*\[\s*'[a-z_]+',\s*)'[^']*'/m",
            "$1'A different sentence that is still a reason.'",
            $src,
            1
        );
        file_put_contents( $file, (string) $src );
    }
);

tt_expect_success(
    $root,
    'a `!==` guard is not mistaken for a route',
    static function ( string $scratch ): void {
        $file = $scratch . '/src/Shared/Frontend/DashboardShortcode.php';
        $src  = (string) file_get_contents( $file );
        // A guard excluding a slug that is not classified anywhere. If the
        // deriver counted `!==` as a route, this would fail as unclassified.
        $src  = str_replace(
            'public static function renderPreAuthSignOut(): void {',
            "public static function renderPreAuthSignOut(): void {\n        if ( \$view !== 'not-a-real-surface' ) { /* guard */ }",
            $src
        );
        file_put_contents( $file, $src );
    }
);

echo "\n";
if ( $failures > 0 ) {
    echo "mobile-class-selfcheck: {$failures} failure(s) — the gate is not trustworthy as written.\n";
    exit( 1 );
}

echo "mobile-class-selfcheck: the gate bites, and only where it should.\n";
exit( 0 );
