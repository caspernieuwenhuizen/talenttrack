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
 * naively is the trap: a `case '<slug>':` grep misses seven live routes,
 * in two ways.
 *
 *   - Constant arms. `case FrontendAlertSettingsView::SLUG:` routes
 *     `alert-settings`, and the literal appears in the view class, not in
 *     the dispatcher. The constant is resolved by reading the class it
 *     names.
 *   - Pre-auth routes. `accept-invite`, the two share links,
 *     `lost-password` and `reset-password` are handled by
 *     `$tt_view_param === ...` comparisons *above* the dispatch chain,
 *     because they must work for a logged-out visitor. They are as
 *     routable as anything in a switch, and a phone visitor is more
 *     likely to arrive on a share link than on most of the switch.
 *
 * Missing either class of route would mean the gate passes while the
 * surface it should have caught goes unclassified, which is worse than
 * no gate: it reports a guarantee it is not making.
 *
 * Usage: php tools/check-mobile-classes.php
 * Exit:  0 clean, 1 findings, 2 tooling error.
 */

declare(strict_types=1);

$root       = dirname( __DIR__ );
$dispatcher = $root . '/src/Shared/Frontend/DashboardShortcode.php';
$manifest   = $root . '/config/mobile_surfaces.php';

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

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

/**
 * Every `?tt_view=` slug the dashboard can route.
 *
 * Reads three shapes out of the dispatcher: literal `case` arms inside a
 * `dispatch*View()` method, constant `case` arms whose value lives in the
 * named class, and the `$tt_view_param === ...` comparisons that handle
 * pre-auth routes above the dispatch chain.
 *
 * @return array{0:array<string,string>,1:string[]} [ slug => where, unresolvable call sites ]
 */
function tt_routable_slugs( string $root, string $dispatcher ): array {
    $src    = (string) file_get_contents( $dispatcher );
    $tokens = token_get_all( $src );
    $rel    = substr( $dispatcher, strlen( $root ) + 1 );

    $slugs      = [];
    $unresolved = [];
    $inFn       = false;
    $depth      = 0;
    $count      = count( $tokens );

    for ( $i = 0; $i < $count; $i++ ) {
        $t    = $tokens[ $i ];
        $line = is_array( $t ) ? $t[2] : 0;

        // Track whether we are inside a dispatch*View() method.
        if ( is_array( $t ) && $t[0] === T_FUNCTION ) {
            $name = '';
            for ( $j = $i + 1; $j < $count; $j++ ) {
                if ( is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_STRING ) {
                    $name = $tokens[ $j ][1];
                    break;
                }
            }
            $inFn  = (bool) preg_match( '/^dispatch\w*View$/', $name );
            $depth = 0;
            continue;
        }
        if ( $t === '{' ) { $depth++; continue; }
        if ( $t === '}' ) { $depth--; if ( $depth <= 0 ) $inFn = false; continue; }

        // Shape 1 + 2 — case arms inside a dispatcher.
        if ( $inFn && is_array( $t ) && $t[0] === T_CASE ) {
            $resolved = tt_resolve_slug_operand( $root, $tokens, $i + 1, $count );
            if ( $resolved === null ) {
                $unresolved[] = "{$rel}:{$line}";
            } elseif ( $resolved !== '' ) {
                $slugs[ $resolved ] ??= "{$rel}:{$line}";
            }
            continue;
        }

        // Shape 3 — `$tt_view_param === <slug>` / `$view === <slug>`, the
        // comparisons that route outside a switch: pre-auth handling above
        // the dispatch chain, and the MFA prompt, which is routed by an
        // `if` because it has to intercept a half-authenticated session
        // before any dispatcher runs. Only `===` counts — `!==` in the
        // same position is a guard excluding a slug, not a route to it.
        if ( is_array( $t ) && $t[0] === T_VARIABLE
             && in_array( $t[1], [ '$tt_view_param', '$view' ], true ) ) {
            $j = $i + 1;
            while ( $j < $count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) $j++;
            if ( $j < $count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_IS_IDENTICAL ) {
                $resolved = tt_resolve_slug_operand( $root, $tokens, $j + 1, $count );
                if ( $resolved === null ) {
                    $unresolved[] = "{$rel}:{$line}";
                } elseif ( $resolved !== '' ) {
                    $slugs[ $resolved ] ??= "{$rel}:{$line}";
                }
            }
        }
    }

    ksort( $slugs );
    return [ $slugs, array_values( array_unique( $unresolved ) ) ];
}

/**
 * Resolve the slug operand starting at `$i`.
 *
 * Returns the slug for a literal or a resolvable class constant, `''` for
 * an operand that is deliberately not a slug (`default:`, an integer), and
 * `null` when it looks like a route this gate could not follow — which is
 * reported rather than silently dropped.
 */
function tt_resolve_slug_operand( string $root, array $tokens, int $i, int $count ): ?string {
    // Skip whitespace.
    while ( $i < $count && is_array( $tokens[ $i ] ) && $tokens[ $i ][0] === T_WHITESPACE ) $i++;
    if ( $i >= $count ) return '';

    $t = $tokens[ $i ];

    // Literal: case 'players';
    if ( is_array( $t ) && $t[0] === T_CONSTANT_ENCAPSED_STRING ) {
        $v = trim( $t[1], "'\"" );
        return preg_match( '/^[a-z0-9][a-z0-9-]*$/', $v ) ? $v : '';
    }

    // Qualified class constant: case \TT\...\SomeView::SLUG;
    $isName = static fn( $tok ): bool => is_array( $tok ) && in_array(
        $tok[0],
        [ T_STRING, T_NS_SEPARATOR, ...( defined( 'T_NAME_QUALIFIED' ) ? [ T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ] : [] ) ],
        true
    );

    if ( ! $isName( $t ) ) return '';

    $fqn = '';
    while ( $i < $count && $isName( $tokens[ $i ] ) ) {
        $fqn .= $tokens[ $i ][1];
        $i++;
    }
    while ( $i < $count && is_array( $tokens[ $i ] ) && $tokens[ $i ][0] === T_WHITESPACE ) $i++;

    if ( $i >= $count || ! is_array( $tokens[ $i ] ) || $tokens[ $i ][0] !== T_DOUBLE_COLON ) {
        return '';
    }
    $i++;
    while ( $i < $count && is_array( $tokens[ $i ] ) && $tokens[ $i ][0] === T_WHITESPACE ) $i++;
    if ( $i >= $count || ! is_array( $tokens[ $i ] ) || $tokens[ $i ][0] !== T_STRING ) {
        return null;
    }

    return tt_constant_value( $root, $fqn, $tokens[ $i ][1] );
}

/**
 * Read `const <name> = '<slug>';` out of the class file `$fqn` names.
 *
 * The class lives at a path mirroring its namespace under `src/`, which is
 * how the autoloader finds it too. Returns null when the file or the
 * constant cannot be found, so the caller can report it rather than
 * assume the route does not exist.
 */
function tt_constant_value( string $root, string $fqn, string $const ): ?string {
    $relative = preg_replace( '/^\\\\?TT\\\\/', '', trim( $fqn ) );
    $path     = $root . '/src/' . str_replace( '\\', '/', (string) $relative ) . '.php';

    if ( ! is_file( $path ) ) return null;

    $src = (string) file_get_contents( $path );
    if ( ! preg_match( '/\bconst\s+' . preg_quote( $const, '/' ) . "\s*=\s*'([a-z0-9][a-z0-9-]*)'/", $src, $m ) ) {
        return null;
    }

    return $m[1];
}
