<?php
/**
 * Self-check for the switchability gate (#2599).
 *
 * A gate that passes is not evidence that it works — it might pass on
 * everything. This runs `check-module-toggles.php` against deliberately
 * broken copies of the tree and asserts it fails on each, and only for
 * the right reason.
 *
 * Mirrors `bin/demo-coverage-selfcheck.php`, which exists for the same
 * reason: the demo-coverage gate is only trustworthy because something
 * proves it still bites.
 *
 * Usage: php bin/module-toggle-selfcheck.php
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$tool = $root . '/tools/check-module-toggles.php';

$failures = 0;

/**
 * Copy the tree's relevant files into a scratch root, apply a mutation,
 * and run the gate against it.
 *
 * @param callable(string):void $mutate
 * @return array{0:int, 1:string} exit code, combined output
 */
function tt_run_against( string $root, callable $mutate ): array {
    $scratch = sys_get_temp_dir() . '/tt-toggle-selfcheck-' . bin2hex( random_bytes( 6 ) );

    foreach ( [ '/config', '/src/Core', '/src/Shared/Modules', '/src/Modules', '/src/Shared', '/tools' ] as $dir ) {
        @mkdir( $scratch . $dir, 0777, true );
    }

    tt_copy_tree( $root . '/config', $scratch . '/config' );
    tt_copy_tree( $root . '/src', $scratch . '/src' );
    copy( $root . '/tools/check-module-toggles.php', $scratch . '/tools/check-module-toggles.php' );

    $mutate( $scratch );

    $output = [];
    $code   = 0;
    exec( 'php ' . escapeshellarg( $scratch . '/tools/check-module-toggles.php' ) . ' 2>&1', $output, $code );

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
        $failures++;
        return;
    }

    echo "PASS  {$label}\n";
}

/**
 * The other direction: a mutation the gate should be relaxed about.
 *
 * A gate that fails on everything is as useless as one that passes on
 * everything, and the first version of this one did fail on 47 surfaces
 * that were switchable all along.
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
exec( 'php ' . escapeshellarg( $tool ) . ' 2>&1', $output, $code );

if ( $code === 0 ) {
    echo "PASS  clean on the current tree\n";
} else {
    echo "FAIL  the gate does not pass on the current tree:\n" . implode( "\n", $output ) . "\n";
    $failures++;
}

// ── and it bites ───────────────────────────────────────────────────────

tt_expect_failure(
    $root,
    'an undeclared module is caught',
    'is not declared in config/modules.php',
    static function ( string $scratch ): void {
        $file = $scratch . '/config/modules.php';
        $src  = (string) file_get_contents( $file );
        // Drop one declaration; its file is still on disk.
        $src  = preg_replace( '/^.*TT\\\\Modules\\\\Holidays\\\\HolidaysModule::class.*$/m', '', $src, 1 );
        file_put_contents( $file, (string) $src );
    }
);

tt_expect_failure(
    $root,
    'a module with no human-facing metadata is caught',
    'has no ModuleMetadata entry',
    static function ( string $scratch ): void {
        $file = $scratch . '/src/Shared/Modules/ModuleMetadata.php';
        $src  = (string) file_get_contents( $file );
        $src  = str_replace( "'TT\\\\Modules\\\\Holidays\\\\HolidaysModule' => [", "'TT\\\\Modules\\\\Nope\\\\NopeModule' => [", $src );
        file_put_contents( $file, $src );
    }
);

tt_expect_failure(
    $root,
    'an un-switchable new surface is caught',
    'has no off-switch',
    static function ( string $scratch ): void {
        // A brand-new slug on a tile owned by an ALWAYS-ON module, claimed
        // by no feature and absent from the manifest — the exact shape
        // this gate exists to stop. `functional-roles` belongs to
        // Authorization, which cannot be switched off.
        $file = $scratch . '/src/Shared/CoreSurfaceRegistration.php';
        $src  = (string) file_get_contents( $file );
        $src  = preg_replace(
            "/'view_slug'(\s*)=>(\s*)'functional-roles'/",
            "'view_slug'\$1=>\$2'brand-new-unswitchable-surface'",
            $src,
            1
        );
        file_put_contents( $file, (string) $src );
    }
);

/**
 * The rule the first version of this gate was missing.
 *
 * A tile owned by a module an academy can switch off is already
 * switchable — demanding a feature toggle for it is noise, and 47 of the
 * 54 entries this manifest once held were exactly that noise.
 */
tt_expect_success(
    $root,
    'a new surface on a switchable module needs no feature entry',
    static function ( string $scratch ): void {
        $file = $scratch . '/src/Shared/CoreSurfaceRegistration.php';
        $src  = (string) file_get_contents( $file );
        // `teams` belongs to TeamsModule, which is switchable.
        $src  = preg_replace(
            "/'view_slug'(\s*)=>(\s*)'teams'/",
            "'view_slug'\$1=>\$2'brand-new-but-module-owned'",
            $src,
            1
        );
        file_put_contents( $file, (string) $src );
    }
);

/**
 * And the bug the new rule turned up: a tile that names no module has no
 * off-switch at all, however switchable the code behind it happens to be.
 */
tt_expect_failure(
    $root,
    'a tile naming no module is caught',
    'declares no module_class',
    static function ( string $scratch ): void {
        $file = $scratch . '/src/Shared/CoreSurfaceRegistration.php';
        $src  = (string) file_get_contents( $file );
        $src  = preg_replace(
            "/'module_class'(\s*)=>(\s*)self::M_TEAMS,(\s*)'view_slug'(\s*)=>(\s*)'teams'/",
            "'module_class'\$1=>\$2null,\$3'view_slug'\$4=>\$5'teams'",
            $src,
            1
        );
        file_put_contents( $file, (string) $src );
    }
);

tt_expect_failure(
    $root,
    'two features claiming one matrix entity is caught',
    'is claimed by more than one feature',
    static function ( string $scratch ): void {
        $file = $scratch . '/src/Core/FeatureRegistry.php';
        $src  = (string) file_get_contents( $file );
        // `team_chemistry` already owns this entity; hand it to a second.
        $src  = str_replace(
            "'entities'        => [ 'cohort_transitions' ],",
            "'entities'        => [ 'cohort_transitions', 'team_chemistry' ],",
            $src
        );
        file_put_contents( $file, $src );
    }
);

tt_expect_failure(
    $root,
    'a feature pointing at a missing module is caught',
    'which is not declared in config/modules.php',
    static function ( string $scratch ): void {
        $file = $scratch . '/src/Core/FeatureRegistry.php';
        $src  = (string) file_get_contents( $file );
        $src  = str_replace(
            "'module_class'    => 'TT\\\\Modules\\\\Journey\\\\JourneyModule',",
            "'module_class'    => 'TT\\\\Modules\\\\Ghost\\\\GhostModule',",
            $src
        );
        file_put_contents( $file, $src );
    }
);

/**
 * #3254 — the assertion that a module-owned dispatcher slug is owned on
 * the unconditional path. Removing Training's declarations must bite: it
 * is exactly the state the bug shipped in.
 */
tt_expect_failure(
    $root,
    'a dispatcher slug losing its unconditional ownership is caught',
    'nothing declares that ownership on the unconditional path',
    static function ( string $scratch ): void {
        $file = $scratch . '/src/Shared/CoreSurfaceRegistration.php';
        $src  = (string) file_get_contents( $file );
        $src  = preg_replace(
            "/^.*registerSlugOwnership\(\s*'training-run'.*$/m",
            '',
            $src,
            1
        );
        file_put_contents( $file, (string) $src );
    }
);

/**
 * The other direction. A dispatcher arm that reaches a `src/Shared`
 * view is nobody's module surface, and must not be demanded of anyone —
 * a gate that asked for ownership of every arm would be asking for
 * ownership that does not exist.
 */
tt_expect_success(
    $root,
    'a shared-view dispatcher arm needs no ownership',
    static function ( string $scratch ): void {
        $file = $scratch . '/src/Shared/Frontend/DashboardShortcode.php';
        $src  = (string) file_get_contents( $file );
        $src  = preg_replace(
            "/case '(overview)':/",
            "case 'brand-new-shared-surface':\n            case '\$1':",
            $src,
            1
        );
        file_put_contents( $file, (string) $src );
    }
);

echo "\n";
if ( $failures > 0 ) {
    fwrite( STDERR, "module-toggle-selfcheck: {$failures} failure(s)\n" );
    exit( 1 );
}

echo "module-toggle-selfcheck OK\n";
exit( 0 );
