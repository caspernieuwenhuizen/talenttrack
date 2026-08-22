<?php
/**
 * Switchability gate (#2599).
 *
 * The mechanism that lets an academy switch a module or a feature off is
 * mature. What was missing was anything that *fails* when a new module or
 * a new routable surface ships without one — every part of it was
 * convention, and conventions are discovered by a user asking "why can't
 * I turn this off?", which is the worst time to find out.
 *
 * Five assertions:
 *
 *   1. Every module class on disk is declared in `config/modules.php`.
 *      Catches a module that exists but was never wired in.
 *   2. Every declared module has human-facing metadata, so the modules
 *      page shows a label rather than a slugified class name.
 *   3. Every tile's `?tt_view=` slug is either owned by a FeatureRegistry
 *      entry, or listed in `config/always_on_surfaces.php` with a reason.
 *      This is the assertion that actually stops an un-switchable feature
 *      shipping.
 *   4. No matrix entity is claimed by two features. The catalog docblock
 *      has always said this MUST hold; nothing checked it, and a
 *      duplicate silently gates a sibling surface too.
 *   5. Every FeatureRegistry `module_class` resolves to a module that is
 *      actually declared. A feature pointing at a missing class gates
 *      nothing, silently.
 *
 * Assertion 3 is grandfathered: the surfaces that predate this gate are
 * listed as `grandfathered` in the manifest. A slug added afterwards has
 * to make a real decision. Same diff-only spirit as the inline-style
 * gate — the backlog is not this issue's problem, the next addition is.
 *
 * Reads the registries by loading them, not by parsing them, so the gate
 * cannot drift from the code it checks. WordPress is not available here,
 * so the handful of functions the catalogs call are stubbed below.
 *
 * Usage: php tools/check-module-toggles.php
 * Exit:  0 clean, 1 findings, 2 tooling error.
 */

declare(strict_types=1);

$root = dirname( __DIR__ );

// The catalogs are translatable strings and a bail-out guard; neither
// needs WordPress to produce the structural data this gate reads.
define( 'ABSPATH', $root . '/' );

if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = '' ): string { return $text; }
}
if ( ! function_exists( '_n' ) ) {
    function _n( string $single, string $plural, int $number, string $domain = '' ): string {
        return $number === 1 ? $single : $plural;
    }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( string $text, string $domain = '' ): string { return $text; }
}

$errors = [];
$notes  = [];

// ---------------------------------------------------------------
// Sources
// ---------------------------------------------------------------

$modules_file = $root . '/config/modules.php';
if ( ! is_file( $modules_file ) ) {
    fwrite( STDERR, "check-module-toggles: config/modules.php not found\n" );
    exit( 2 );
}

/** @var array<string,bool> $declared class => enabled-by-default */
$declared = require $modules_file;
if ( ! is_array( $declared ) ) {
    fwrite( STDERR, "check-module-toggles: config/modules.php did not return an array\n" );
    exit( 2 );
}
$declared_classes = array_map( static fn( string $c ): string => ltrim( $c, '\\' ), array_keys( $declared ) );

require_once $root . '/src/Core/ModuleRegistry.php';
require_once $root . '/src/Core/FeatureRegistry.php';
require_once $root . '/src/Shared/Modules/ModuleMetadata.php';

try {
    $catalog = ( new ReflectionMethod( 'TT\\Core\\FeatureRegistry', 'catalog' ) );
    $catalog->setAccessible( true );
    /** @var array<string,array<string,mixed>> $features */
    $features = (array) $catalog->invoke( null );

    $map = new ReflectionMethod( 'TT\\Shared\\Modules\\ModuleMetadata', 'map' );
    $map->setAccessible( true );
    /** @var array<string,array<string,string>> $metadata */
    $metadata = (array) $map->invoke( null );

    $always_on = (array) ( new ReflectionClass( 'TT\\Core\\ModuleRegistry' ) )->getConstant( 'ALWAYS_ON_MODULES' );
} catch ( Throwable $e ) {
    fwrite( STDERR, 'check-module-toggles: could not read the registries — ' . $e->getMessage() . "\n" );
    exit( 2 );
}

$always_on = array_map( static fn( string $c ): string => ltrim( $c, '\\' ), $always_on );

$surfaces_file = $root . '/config/always_on_surfaces.php';
/** @var array<string,string> $always_on_surfaces slug => reason */
$always_on_surfaces = is_file( $surfaces_file ) ? (array) require $surfaces_file : [];

// ---------------------------------------------------------------
// 1. Every module class on disk is declared
// ---------------------------------------------------------------

$on_disk = [];
foreach ( (array) glob( $root . '/src/Modules/*/*Module.php' ) as $file ) {
    $class = tt_module_class_in( (string) file_get_contents( $file ) );
    if ( $class !== '' ) $on_disk[] = $class;
}
sort( $on_disk );

foreach ( $on_disk as $class ) {
    if ( in_array( $class, $declared_classes, true ) ) continue;
    $errors[] = "Module `{$class}` exists but is not declared in config/modules.php — it never boots, and no operator can switch it on or off.";
}

foreach ( $declared_classes as $class ) {
    if ( in_array( $class, $on_disk, true ) ) continue;
    $errors[] = "config/modules.php declares `{$class}`, but no file under src/Modules/ defines it as a ModuleInterface.";
}

// ---------------------------------------------------------------
// 2. Every declared module is described for humans
// ---------------------------------------------------------------

foreach ( $declared_classes as $class ) {
    if ( isset( $metadata[ $class ] ) ) continue;
    $errors[] = "Module `{$class}` has no ModuleMetadata entry — the modules page would show a slugified class name where a label belongs.";
}

// ---------------------------------------------------------------
// 3. Every routable tile surface is switchable, or says why not
// ---------------------------------------------------------------

$feature_slugs = [];
foreach ( $features as $key => $entry ) {
    foreach ( (array) ( $entry['view_slugs'] ?? [] ) as $slug ) {
        $feature_slugs[ (string) $slug ][] = (string) $key;
    }
}

[ $tile_slugs, $dynamic ] = tt_tile_slugs( $root );

foreach ( $dynamic as $where ) {
    $notes[] = "Tile slug at {$where} is computed at runtime, so this gate cannot check it. Register it in a feature's view_slugs by hand if it should be switchable.";
}

foreach ( $tile_slugs as $slug => $tile ) {
    [ $where, $owner ] = $tile;

    // A feature claims it outright.
    if ( isset( $feature_slugs[ $slug ] ) ) continue;

    // Or its owning module can be switched off — in which case the
    // surface already has an off-switch and needs no feature of its own.
    // Missing this is what made the first version of this gate demand a
    // feature toggle for surfaces that were switchable all along.
    if ( $owner !== '' && in_array( $owner, $declared_classes, true ) && ! in_array( $owner, $always_on, true ) ) {
        continue;
    }

    // Or somebody wrote down why it must always be on.
    if ( array_key_exists( $slug, $always_on_surfaces ) ) continue;

    $reason = $owner === ''
        ? 'its tile declares no module_class, so nothing owns it'
        : ( in_array( $owner, $always_on, true )
            ? "its owning module `{$owner}` is always-on"
            : "its module_class `{$owner}` is not declared in config/modules.php" );

    $errors[] = "Tile surface `?tt_view={$slug}` ({$where}) has no off-switch — {$reason}, and no FeatureRegistry entry claims it. "
        . 'Add it to a feature\'s `view_slugs`, or list it in config/always_on_surfaces.php with the reason it must always be on.';
}

// A manifest entry that is no longer doing any work is dead weight, and
// makes the next reader trust the file less. Two ways that happens: the
// tile is gone, or the surface became switchable by another route.
foreach ( array_keys( $always_on_surfaces ) as $slug ) {
    if ( ! isset( $tile_slugs[ $slug ] ) ) {
        $notes[] = "config/always_on_surfaces.php lists `{$slug}`, which no tile registers any more. Remove it.";
        continue;
    }

    $owner = $tile_slugs[ $slug ][1];

    if ( isset( $feature_slugs[ $slug ] ) ) {
        $notes[] = "config/always_on_surfaces.php lists `{$slug}`, but a feature now claims it. Remove the manifest entry.";
        continue;
    }

    if ( $owner !== '' && in_array( $owner, $declared_classes, true ) && ! in_array( $owner, $always_on, true ) ) {
        $notes[] = "config/always_on_surfaces.php lists `{$slug}`, but its module `{$owner}` is switchable, so the surface already has an off-switch. Remove the manifest entry.";
    }
}

// ---------------------------------------------------------------
// 4. No matrix entity is claimed twice
// ---------------------------------------------------------------

$entity_owners = [];
foreach ( $features as $key => $entry ) {
    foreach ( (array) ( $entry['entities'] ?? [] ) as $entity ) {
        $entity_owners[ (string) $entity ][] = (string) $key;
    }
}

foreach ( $entity_owners as $entity => $owners ) {
    if ( count( $owners ) < 2 ) continue;
    $errors[] = "Matrix entity `{$entity}` is claimed by more than one feature (" . implode( ', ', $owners ) . "). "
        . 'Switching either one off would gate the other\'s surface too.';
}

// ---------------------------------------------------------------
// 5. Feature module_class references resolve
// ---------------------------------------------------------------

foreach ( $features as $key => $entry ) {
    $owner = ltrim( (string) ( $entry['module_class'] ?? '' ), '\\' );

    if ( $owner === '' ) {
        $errors[] = "Feature `{$key}` names no module_class, so nothing decides whether its parent module is on.";
        continue;
    }
    if ( in_array( $owner, $declared_classes, true ) ) continue;

    $errors[] = "Feature `{$key}` names module_class `{$owner}`, which is not declared in config/modules.php — the feature gates nothing.";
}

// ---------------------------------------------------------------
// Report
// ---------------------------------------------------------------

foreach ( $notes as $note ) {
    echo "note: {$note}\n";
}

if ( $errors !== [] ) {
    fwrite( STDERR, "\ncheck-module-toggles: " . count( $errors ) . " finding(s)\n\n" );
    foreach ( $errors as $error ) {
        fwrite( STDERR, "  - {$error}\n" );
    }
    fwrite( STDERR, "\nSee docs/modules.md § Switchability.\n" );
    exit( 1 );
}

printf(
    "check-module-toggles OK — %d modules (%d always-on), %d features, %d tile surfaces, %d always-on surfaces.\n",
    count( $declared_classes ),
    count( $always_on ),
    count( $features ),
    count( $tile_slugs ),
    count( $always_on_surfaces )
);
exit( 0 );

/**
 * Fully-qualified name of the `ModuleInterface` class in a source file,
 * or '' when the file defines none.
 *
 * Tokenised rather than matched, because a docblock explaining what a
 * module is contains every word a regex looks for — the first version of
 * this reported a class called `didn` out of the word "didn't".
 */
function tt_module_class_in( string $src ): string {
    $tokens = token_get_all( $src );
    $count  = count( $tokens );

    $namespace = '';
    $class     = '';
    $implements = false;

    for ( $i = 0; $i < $count; $i++ ) {
        $token = $tokens[ $i ];
        if ( ! is_array( $token ) ) {
            // `{` ends the class signature; anything after it is the body.
            if ( $token === '{' && $class !== '' ) break;
            continue;
        }

        switch ( $token[0] ) {
            case T_NAMESPACE:
                $namespace = tt_read_name( $tokens, $i, $count );
                break;

            case T_CLASS:
                // `::class` is a T_CLASS too; a declaration is not preceded
                // by a double colon.
                $prev = tt_prev_significant( $tokens, $i );
                if ( is_array( $prev ) && $prev[0] === T_DOUBLE_COLON ) break;
                $class = tt_read_name( $tokens, $i, $count );
                break;

            case T_IMPLEMENTS:
                $implements = true;
                break;

            case T_STRING:
                if ( $implements && $token[1] === 'ModuleInterface' && $class !== '' ) {
                    return ( $namespace !== '' ? $namespace . '\\' : '' ) . $class;
                }
                break;
        }
    }

    return '';
}

/** Next T_STRING-ish name after position $i. */
function tt_read_name( array $tokens, int $i, int $count ): string {
    $name = '';
    for ( $j = $i + 1; $j < $count; $j++ ) {
        $t = $tokens[ $j ];
        if ( is_array( $t ) && ( $t[0] === T_WHITESPACE ) ) continue;
        if ( is_array( $t ) && in_array( $t[0], [ T_STRING, T_NS_SEPARATOR ], true ) ) {
            $name .= $t[1];
            continue;
        }
        if ( is_array( $t ) && defined( 'T_NAME_QUALIFIED' ) && $t[0] === T_NAME_QUALIFIED ) {
            $name .= $t[1];
            continue;
        }
        break;
    }
    return trim( $name, '\\' );
}

/** @return array|string|null */
function tt_prev_significant( array $tokens, int $i ) {
    for ( $j = $i - 1; $j >= 0; $j-- ) {
        $t = $tokens[ $j ];
        if ( is_array( $t ) && in_array( $t[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) continue;
        return $t;
    }
    return null;
}

/**
 * Slugs registered as tiles, with the module that registers each.
 *
 * Tiles are registered at runtime by each module, so there is no list to
 * load — this walks the calls instead. A slug built from a variable is
 * reported separately rather than guessed at: a gate that silently skips
 * what it cannot read is worse than one that says so.
 *
 * The owning module matters as much as the slug: a tile registered by a
 * module an academy can switch off is already switchable.
 *
 * @return array{0: array<string,array{0:string,1:string}>, 1: list<string>}
 *         [ slug => [ where, module class ], dynamic call sites ]
 */
function tt_tile_slugs( string $root ): array {
    $slugs   = [];
    $dynamic = [];

    $files = [];
    $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) );
    foreach ( $it as $entry ) {
        if ( $entry->isFile() && substr( $entry->getFilename(), -4 ) === '.php' ) $files[] = $entry->getPathname();
    }
    sort( $files );

    foreach ( $files as $file ) {
        $src = (string) file_get_contents( $file );
        if ( strpos( $src, 'TileRegistry::register' ) === false ) continue;

        $rel    = ltrim( str_replace( [ $root, '\\' ], [ '', '/' ], $file ), '/' );
        $consts = tt_class_string_consts( $src );

        $offset = 0;
        while ( ( $pos = strpos( $src, 'TileRegistry::register', $offset ) ) !== false ) {
            $offset = $pos + 1;

            // The argument array of one call. Bounded rather than balanced:
            // a tile definition is a flat array of ~12 keys, and reading a
            // fixed window keeps this a lint rather than a parser. Both
            // `] );` and `]);` close one in this codebase.
            $window = substr( $src, $pos, 1600 );
            foreach ( [ '] );', ']);' ] as $close ) {
                $end = strpos( $window, $close );
                if ( $end !== false ) $window = substr( $window, 0, $end );
            }

            if ( preg_match( "/'(?:view_slug|slug)'\s*=>\s*'([a-z0-9_-]+)'/", $window, $m ) ) {
                $slugs[ $m[1] ] = [ $rel, tt_tile_owner( $window, $consts, $file ) ];
                continue;
            }

            if ( preg_match( "/'(?:view_slug|slug)'\s*=>/", $window ) ) {
                $line      = substr_count( substr( $src, 0, $pos ), "\n" ) + 1;
                $dynamic[] = "{$rel}:{$line}";
            }
        }
    }

    ksort( $slugs );
    return [ $slugs, $dynamic ];
}

/**
 * The module class a tile registration names, in whichever of the three
 * shapes this codebase uses: a `self::CONST` holding the FQN, a
 * `Foo::class`, or a quoted literal.
 *
 * @param array<string,string> $consts class constants of the enclosing file
 */
function tt_tile_owner( string $window, array $consts, string $file ): string {
    if ( preg_match( "/'module_class'\s*=>\s*self::([A-Z_][A-Z0-9_]*)/", $window, $m ) ) {
        return $consts[ $m[1] ] ?? '';
    }

    if ( preg_match( "/'module_class'\s*=>\s*self::class/", $window ) ) {
        // A module registering its own tile.
        return tt_module_class_in( (string) file_get_contents( $file ) );
    }

    if ( preg_match( "/'module_class'\s*=>\s*\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)::class/", $window, $m ) ) {
        return ltrim( $m[1], '\\' );
    }

    if ( preg_match( "/'module_class'\s*=>\s*'((?:[^'\\\\]|\\\\.)*)'/", $window, $m ) ) {
        // Source-level escapes: 'TT\\Modules\\X' is the string TT\Modules\X.
        return ltrim( stripcslashes( $m[1] ), '\\' );
    }

    return '';
}

/**
 * `const NAME = 'string';` pairs in a file, so `self::NAME` resolves.
 *
 * @return array<string,string>
 */
function tt_class_string_consts( string $src ): array {
    $out = [];
    if ( preg_match_all( "/const\s+([A-Z_][A-Z0-9_]*)\s*=\s*'((?:[^'\\\\]|\\\\.)*)'\s*;/", $src, $m, PREG_SET_ORDER ) ) {
        foreach ( $m as $set ) {
            $out[ $set[1] ] = ltrim( stripcslashes( $set[2] ), '\\' );
        }
    }
    return $out;
}
