<?php
/**
 * The one place a CI gate learns which `?tt_view=` slugs the dashboard routes (#3022).
 *
 * Three gates need this set — the docs gate (#2551), the mobile-class gate
 * (#2812) and the tile-route gate (#2885) — and until this file existed each
 * derived it separately. They disagreed by eight live routes, so the docs gate
 * reported "every routable view declares a help topic" about the subset it
 * happened to be able to parse, and a dispatcher arm written with a class
 * constant bought itself an exemption from the help-topic requirement. That is
 * the wrong incentive: the better-factored arm was the invisible one.
 *
 * ON READING THE DISPATCHER
 *
 * The slug set is derived rather than declared, so it cannot drift from what
 * is actually reachable. Deriving it naively is the trap: a `case '<slug>':`
 * grep misses live routes in two ways.
 *
 *   - Constant arms. `case FrontendAlertSettingsView::SLUG:` routes
 *     `alert-settings`, and the literal lives in the view class, not in the
 *     dispatcher. The constant is resolved by reading the class it names.
 *   - Pre-auth routes. `accept-invite`, the two share links, `lost-password`
 *     and `reset-password` are handled by `$tt_view_param === ...`
 *     comparisons *above* the dispatch chain, because they must work for a
 *     logged-out visitor. They are as routable as anything in a switch.
 *
 * Anything that looks like a route but cannot be followed statically is
 * reported through the second return value rather than dropped, so a gate can
 * say "classify this by hand" instead of passing in silence.
 */

declare(strict_types=1);

if ( ! function_exists( 'tt_routable_slugs' ) ) {

    /**
     * Every `?tt_view=` slug the dashboard can route.
     *
     * Reads three shapes out of the dispatcher: literal `case` arms inside a
     * `dispatch*View()` method, constant `case` arms whose value lives in the
     * named class, and the `$tt_view_param === ...` comparisons that handle
     * pre-auth routes above the dispatch chain.
     *
     * @param string $root       Repository root.
     * @param string $dispatcher Absolute path to DashboardShortcode.php.
     * @return array{0:array<string,string>,1:list<string>} [ slug => where, unresolvable call sites ]
     */
    function tt_routable_slugs( string $root, string $dispatcher ): array {
        $src    = (string) file_get_contents( $dispatcher );
        $tokens = token_get_all( $src );
        $rel    = str_replace( '\\', '/', substr( $dispatcher, strlen( $root ) + 1 ) );

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
     * `null` when it looks like a route this deriver could not follow — which
     * the caller reports rather than silently dropping.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
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
     * constant cannot be found, so the caller can report it rather than assume
     * the route does not exist.
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
}
