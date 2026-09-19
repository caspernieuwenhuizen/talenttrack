<?php
/**
 * The parser behind `tools/check-rest-args.php` (#3689).
 *
 * Split out from the CLI script so it can be tested against snippets rather
 * than against the repository. See `tests/php/RestArgsGateTest.php`.
 *
 * WHAT IT DECIDES
 *
 * Every `register_rest_route()` call, in both shapes core takes:
 *
 *     register_rest_route( NS, '/x', [ 'methods' => 'POST', 'args' => … ] );
 *     register_rest_route( NS, '/x', [ [ 'methods' => 'GET', … ], [ … ] ] );
 *
 * An endpoint is a WRITE when its `methods` names POST, PUT or PATCH, or is
 * `WP_REST_Server::CREATABLE`, `EDITABLE` or `ALLMETHODS`. A write endpoint
 * with no `'args'` key is a violation. `'args' => []` is declared ("takes no
 * body"), and so is any expression (`self::putArgs()`).
 *
 * A route is identified as `file | route path | methods`, never by line, so
 * an edit elsewhere in the file does not churn the baseline.
 *
 * WHAT IT CANNOT READ IS A VIOLATION
 *
 * A path it cannot evaluate statically (a local variable, a function call)
 * or a `methods` / endpoint it cannot read is reported, identified by the raw
 * expression. There is no silent pass: one dynamic path can stand for many
 * routes, so a new route added behind it would otherwise never be seen.
 * String literals, concatenations of them, and `self::` / `static::` string
 * constants declared in the same file are all read.
 */

declare( strict_types = 1 );

const TT_REST_WRITE_METHODS = [ 'POST', 'PUT', 'PATCH' ];

const TT_REST_SERVER_CONSTANTS = [
    'READABLE'   => [ 'GET' ],
    'CREATABLE'  => [ 'POST' ],
    'EDITABLE'   => [ 'POST', 'PUT', 'PATCH' ],
    'DELETABLE'  => [ 'DELETE' ],
    'ALLMETHODS' => [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ],
];

/**
 * The write endpoints in one file that do not declare `args`, as baseline
 * identities (`<file> | <path> | <methods>`), sorted and unique.
 *
 * @return list<string>
 */
function tt_rest_args_violations( string $code, string $file ): array {
    $out = [];
    foreach ( tt_rest_args_endpoints( $code ) as $endpoint ) {
        if ( $endpoint['violation'] ) {
            $out[] = $file . ' | ' . $endpoint['path'] . ' | ' . $endpoint['methods'];
        }
    }
    $out = array_values( array_unique( $out ) );
    sort( $out );
    return $out;
}

/**
 * Every endpoint registered in the code, with what the gate made of it.
 *
 * @return list<array{path:string, methods:string, write:bool, args:bool, violation:bool}>
 */
function tt_rest_args_endpoints( string $code ): array {
    $tokens    = tt_rest_args_tokens( $code );
    $constants = tt_rest_args_constants( $tokens );
    $count     = count( $tokens );
    $out       = [];

    for ( $i = 0; $i < $count; $i++ ) {
        $t = $tokens[ $i ];
        if ( $t['text'] !== 'register_rest_route' && $t['text'] !== '\\register_rest_route' ) continue;
        if ( ! in_array( $t['type'], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true ) ) continue;
        $prev = $tokens[ $i - 1 ] ?? null;
        // A declaration, a method or a static call is not core's function.
        if ( $prev !== null && in_array( $prev['type'], [ T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ], true ) ) continue;
        if ( ( $tokens[ $i + 1 ]['text'] ?? '' ) !== '(' ) continue;

        [ $call_args, $end ] = tt_rest_args_split( $tokens, $i + 1 );
        $i = $end;

        $path_slice = $call_args[1]['value'] ?? [];
        $path       = tt_rest_args_string( $tokens, $path_slice, $constants );
        $path_ok    = $path !== null;
        if ( $path === null ) $path = tt_rest_args_raw( $code, $tokens, $path_slice );

        $options = $call_args[2]['value'] ?? [];
        foreach ( tt_rest_args_endpoint_slices( $tokens, $options ) as $slice ) {
            if ( $slice === null ) {
                $out[] = [ 'path' => $path, 'methods' => '?', 'write' => true, 'args' => false, 'violation' => true ];
                continue;
            }
            $out[] = tt_rest_args_judge( $tokens, $slice, $constants, $path, $path_ok );
        }
    }
    return $out;
}

/**
 * @param list<array{type:int|string, text:string, pos:int}> $tokens
 * @param array{0:int, 1:int}                                $slice
 * @param array<string,string>                               $constants
 * @return array{path:string, methods:string, write:bool, args:bool, violation:bool}
 */
function tt_rest_args_judge( array $tokens, array $slice, array $constants, string $path, bool $path_ok ): array {
    [ $elements ] = tt_rest_args_split( $tokens, $slice[0] );
    $methods_slice = null;
    $has_args      = false;
    foreach ( $elements as $el ) {
        $key = $el['key'] === null ? null : tt_rest_args_string( $tokens, $el['key'], $constants );
        if ( $key === 'methods' ) $methods_slice = $el['value'];
        if ( $key === 'args' ) $has_args = true;
    }

    // Core's default when `methods` is left out is GET.
    $methods = $methods_slice === null ? [ 'GET' ] : tt_rest_args_methods( $tokens, $methods_slice, $constants );
    if ( $methods === null ) {
        return [ 'path' => $path, 'methods' => '?', 'write' => true, 'args' => $has_args, 'violation' => true ];
    }
    $write = array_intersect( $methods, TT_REST_WRITE_METHODS ) !== [];
    return [
        'path'      => $path,
        'methods'   => implode( ',', $methods ),
        'write'     => $write,
        'args'      => $has_args,
        'violation' => $write && ( ! $has_args || ! $path_ok ),
    ];
}

/**
 * The endpoint arrays inside a route's options: the options array itself
 * when it is one endpoint, or each list element when it is several. Null
 * stands for an endpoint the gate cannot read.
 *
 * @param list<array{type:int|string, text:string, pos:int}> $tokens
 * @param array{0:int, 1:int}|array{}                        $options
 * @return list<array{0:int, 1:int}|null>
 */
function tt_rest_args_endpoint_slices( array $tokens, array $options ): array {
    if ( $options === [] || ! tt_rest_args_is_array( $tokens, $options ) ) return [ null ];

    [ $elements ] = tt_rest_args_split( $tokens, $options[0] );
    foreach ( $elements as $el ) {
        if ( $el['key'] === null ) continue;
        $key = tt_rest_args_string( $tokens, $el['key'], [] );
        if ( in_array( $key, [ 'methods', 'callback', 'permission_callback', 'args' ], true ) ) {
            return [ $options ];
        }
    }

    $out = [];
    foreach ( $elements as $el ) {
        if ( $el['key'] !== null ) continue; // `schema`, `allow_batch`.
        $out[] = tt_rest_args_is_array( $tokens, $el['value'] ) ? $el['value'] : null;
    }
    return $out;
}

/**
 * @param list<array{type:int|string, text:string, pos:int}> $tokens
 * @param array{0:int, 1:int}|array{}                        $slice
 */
function tt_rest_args_is_array( array $tokens, array $slice ): bool {
    if ( $slice === [] ) return false;
    $first = $tokens[ $slice[0] ];
    if ( $first['text'] === '[' ) return tt_rest_args_close( $tokens, $slice[0] ) === $slice[1];
    if ( $first['type'] === T_ARRAY && ( $tokens[ $slice[0] + 1 ]['text'] ?? '' ) === '(' ) {
        return tt_rest_args_close( $tokens, $slice[0] + 1 ) === $slice[1];
    }
    return false;
}

/**
 * The HTTP methods an expression names, upper-cased, or null when it cannot
 * be read.
 *
 * @param list<array{type:int|string, text:string, pos:int}> $tokens
 * @param array{0:int, 1:int}                                $slice
 * @param array<string,string>                               $constants
 * @return list<string>|null
 */
function tt_rest_args_methods( array $tokens, array $slice, array $constants ): ?array {
    if ( tt_rest_args_is_array( $tokens, $slice ) ) {
        $start = $tokens[ $slice[0] ]['type'] === T_ARRAY ? $slice[0] + 1 : $slice[0];
        [ $elements ] = tt_rest_args_split( $tokens, $start );
        $all = [];
        foreach ( $elements as $el ) {
            if ( $el['value'] === [] ) continue;
            $m = tt_rest_args_methods( $tokens, $el['value'], $constants );
            if ( $m === null ) return null;
            $all = array_merge( $all, $m );
        }
        return array_values( array_unique( $all ) );
    }

    // WP_REST_Server::CREATABLE and friends.
    $texts = [];
    for ( $k = $slice[0]; $k <= $slice[1]; $k++ ) $texts[] = $tokens[ $k ]['text'];
    if ( count( $texts ) === 3 && $texts[1] === '::' && ltrim( $texts[0], '\\' ) === 'WP_REST_Server' ) {
        return TT_REST_SERVER_CONSTANTS[ $texts[2] ] ?? null;
    }

    $string = tt_rest_args_string( $tokens, $slice, $constants );
    if ( $string === null ) return null;
    $out = [];
    foreach ( explode( ',', $string ) as $m ) {
        $m = strtoupper( trim( $m ) );
        if ( $m !== '' ) $out[] = $m;
    }
    return $out === [] ? null : array_values( array_unique( $out ) );
}

/**
 * The value of a string expression — literals, `.` concatenation, and
 * `self::` / `static::` constants from the same file — or null when it is
 * anything else.
 *
 * @param list<array{type:int|string, text:string, pos:int}> $tokens
 * @param array{0:int, 1:int}|array{}                        $slice
 * @param array<string,string>                               $constants
 */
function tt_rest_args_string( array $tokens, array $slice, array $constants ): ?string {
    if ( $slice === [] ) return null;
    $out    = '';
    $expect = true; // an operand next, as opposed to a `.`
    for ( $k = $slice[0]; $k <= $slice[1]; $k++ ) {
        $t = $tokens[ $k ];
        if ( ! $expect ) {
            if ( $t['text'] !== '.' ) return null;
            $expect = true;
            continue;
        }
        if ( $t['type'] === T_CONSTANT_ENCAPSED_STRING ) {
            $out   .= tt_rest_args_unquote( $t['text'] );
            $expect = false;
            continue;
        }
        if ( in_array( $t['text'], [ 'self', 'static' ], true )
            && ( $tokens[ $k + 1 ]['text'] ?? '' ) === '::'
            && isset( $tokens[ $k + 2 ] ) && $tokens[ $k + 2 ]['type'] === T_STRING
            && isset( $constants[ $tokens[ $k + 2 ]['text'] ] )
        ) {
            $out   .= $constants[ $tokens[ $k + 2 ]['text'] ];
            $k     += 2;
            $expect = false;
            continue;
        }
        return null;
    }
    return $expect ? null : $out;
}

function tt_rest_args_unquote( string $literal ): string {
    $body = substr( $literal, 1, -1 );
    if ( $literal[0] === "'" ) {
        return str_replace( [ "\\\\", "\\'" ], [ "\\", "'" ], $body );
    }
    return $body;
}

/**
 * The source text a slice covers, whitespace collapsed.
 *
 * @param list<array{type:int|string, text:string, pos:int}> $tokens
 * @param array{0:int, 1:int}|array{}                        $slice
 */
function tt_rest_args_raw( string $code, array $tokens, array $slice ): string {
    if ( $slice === [] ) return '?';
    $from   = $tokens[ $slice[0] ]['pos'];
    $last   = $tokens[ $slice[1] ];
    $text   = substr( $code, $from, $last['pos'] + strlen( $last['text'] ) - $from );
    return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
}

/**
 * `const NAME = '<literal>';` declarations in the file.
 *
 * @param list<array{type:int|string, text:string, pos:int}> $tokens
 * @return array<string,string>
 */
function tt_rest_args_constants( array $tokens ): array {
    $out   = [];
    $count = count( $tokens );
    for ( $i = 0; $i < $count; $i++ ) {
        if ( $tokens[ $i ]['type'] !== T_CONST ) continue;
        // `const NAME = 'x', OTHER = 'y';` and typed constants (`const string NAME`).
        $k = $i + 1;
        while ( $k < $count && $tokens[ $k ]['text'] !== ';' ) {
            if ( $tokens[ $k ]['type'] === T_STRING
                && ( $tokens[ $k + 1 ]['text'] ?? '' ) === '='
                && ( $tokens[ $k + 2 ]['type'] ?? null ) === T_CONSTANT_ENCAPSED_STRING
                && in_array( $tokens[ $k + 3 ]['text'] ?? '', [ ';', ',' ], true )
            ) {
                $out[ $tokens[ $k ]['text'] ] = tt_rest_args_unquote( $tokens[ $k + 2 ]['text'] );
            }
            $k++;
        }
    }
    return $out;
}

/**
 * Tokens without whitespace and comments, each with its byte offset in the
 * source so a raw expression can be quoted back exactly.
 *
 * @return list<array{type:int|string, text:string, pos:int}>
 */
function tt_rest_args_tokens( string $code ): array {
    $out = [];
    $pos = 0;
    foreach ( token_get_all( $code ) as $t ) {
        $type = is_array( $t ) ? $t[0] : $t;
        $text = is_array( $t ) ? $t[1] : $t;
        if ( ! in_array( $type, [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG ], true ) ) {
            $out[] = [ 'type' => $type, 'text' => $text, 'pos' => $pos ];
        }
        $pos += strlen( $text );
    }
    return $out;
}

/**
 * The index of the bracket that closes the one at $open.
 *
 * @param list<array{type:int|string, text:string, pos:int}> $tokens
 */
function tt_rest_args_close( array $tokens, int $open ): int {
    $depth = 0;
    $count = count( $tokens );
    for ( $k = $open; $k < $count; $k++ ) {
        $text = $tokens[ $k ]['text'];
        $type = $tokens[ $k ]['type'];
        if ( in_array( $text, [ '(', '[', '{' ], true ) || in_array( $type, [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) {
            $depth++;
        } elseif ( in_array( $text, [ ')', ']', '}' ], true ) ) {
            $depth--;
            if ( $depth === 0 ) return $k;
        }
    }
    return $count - 1;
}

/**
 * The top-level elements between the bracket at $open and its match, each
 * split at its first top-level `=>` into key and value slices (inclusive
 * token index pairs, empty when there is nothing). Also returns the index
 * of the closing bracket.
 *
 * @param list<array{type:int|string, text:string, pos:int}> $tokens
 * @return array{0: list<array{key: array{0:int, 1:int}|null, value: array{0:int, 1:int}|array{}}>, 1: int}
 */
function tt_rest_args_split( array $tokens, int $open ): array {
    if ( $tokens[ $open ]['type'] === T_ARRAY ) $open++;
    $close    = tt_rest_args_close( $tokens, $open );
    $elements = [];
    $start    = $open + 1;
    $arrow    = null;
    $depth    = 0;
    for ( $k = $open + 1; $k <= $close; $k++ ) {
        $text = $tokens[ $k ]['text'];
        $type = $tokens[ $k ]['type'];
        if ( $k < $close && ( in_array( $text, [ '(', '[', '{' ], true ) || in_array( $type, [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) ) {
            $depth++;
            continue;
        }
        if ( $k < $close && in_array( $text, [ ')', ']', '}' ], true ) ) {
            $depth--;
            continue;
        }
        if ( $depth === 0 && $type === T_DOUBLE_ARROW && $arrow === null ) {
            $arrow = $k;
            continue;
        }
        if ( $depth === 0 && ( $text === ',' || $k === $close ) ) {
            $last = $k - 1;
            if ( $last >= $start ) {
                $elements[] = $arrow === null
                    ? [ 'key' => null, 'value' => [ $start, $last ] ]
                    : [ 'key' => [ $start, $arrow - 1 ], 'value' => $arrow + 1 <= $last ? [ $arrow + 1, $last ] : [] ];
            }
            $start = $k + 1;
            $arrow = null;
        }
    }
    return [ $elements, $close ];
}

/**
 * The exact line `tools/rest-args-baseline.php` carries for an identity, so
 * a failure message can quote it for deleting or adding verbatim.
 */
function tt_rest_args_baseline_line( string $identity ): string {
    return '    ' . var_export( $identity, true ) . ',';
}
