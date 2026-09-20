<?php
/**
 * Pull the translatable strings out of PHP source.
 *
 * Tokenised rather than grepped, for two reasons that both bite in this
 * codebase: a long msgid is regularly written as a concatenation of
 * literals split across lines to keep the line readable, and a regex
 * either misses those or takes the first fragment as the whole string;
 * and `_x()` / `_n()` put the parts that decide the entry's identity in
 * different argument positions, which a pattern cannot tell apart.
 *
 * Only calls whose text domain is literally `talenttrack` come back. A
 * call with a non-literal msgid, context or domain cannot be checked
 * against a catalogue at all, so it is skipped rather than guessed at.
 */

declare( strict_types = 1 );

/**
 * Every checkable gettext call in a PHP file.
 *
 * The msgid is always argument 0; the context and the domain move, so
 * each function declares where its are.
 *
 * @return list<array{msgctxt:string, msgid:string, line:int, function:string}>
 */
function tt_gettext_calls( string $source, string $domain = 'talenttrack' ): array {
    $signatures = [
        '__'         => [ 'ctx' => null, 'domain' => 1 ],
        '_e'         => [ 'ctx' => null, 'domain' => 1 ],
        'esc_html__' => [ 'ctx' => null, 'domain' => 1 ],
        'esc_html_e' => [ 'ctx' => null, 'domain' => 1 ],
        'esc_attr__' => [ 'ctx' => null, 'domain' => 1 ],
        'esc_attr_e' => [ 'ctx' => null, 'domain' => 1 ],
        '_x'         => [ 'ctx' => 1,    'domain' => 2 ],
        '_ex'        => [ 'ctx' => 1,    'domain' => 2 ],
        'esc_html_x' => [ 'ctx' => 1,    'domain' => 2 ],
        'esc_attr_x' => [ 'ctx' => 1,    'domain' => 2 ],
        '_n'         => [ 'ctx' => null, 'domain' => 3 ],
        '_nx'        => [ 'ctx' => 3,    'domain' => 4 ],
        '_n_noop'    => [ 'ctx' => null, 'domain' => 2 ],
        '_nx_noop'   => [ 'ctx' => 2,    'domain' => 3 ],
    ];

    $tokens = token_get_all( $source );
    $count  = count( $tokens );
    $calls  = [];

    for ( $i = 0; $i < $count; $i++ ) {
        $token = $tokens[ $i ];
        if ( ! is_array( $token ) ) {
            continue;
        }

        // `\__( … )` is one fully-qualified name token, not a backslash
        // followed by T_STRING, and plenty of this codebase writes it so.
        $is_name = $token[0] === T_STRING
            || ( defined( 'T_NAME_FULLY_QUALIFIED' ) && $token[0] === T_NAME_FULLY_QUALIFIED );
        if ( ! $is_name ) {
            continue;
        }

        $name = ltrim( $token[1], '\\' );
        if ( ! isset( $signatures[ $name ] ) ) {
            continue;
        }

        $before = tt_gettext_previous( $tokens, $i );
        if ( is_array( $before ) && in_array(
            $before[0],
            [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ],
            true
        ) ) {
            continue;
        }

        $open  = null;
        $after = tt_gettext_next( $tokens, $i, $open );
        if ( $after !== '(' || $open === null ) {
            continue;
        }

        $args = tt_gettext_arguments( $tokens, $open, $count );
        if ( $args === null ) {
            continue;
        }

        $signature = $signatures[ $name ];
        if ( ( $args[ $signature['domain'] ] ?? null ) !== $domain ) {
            continue;
        }

        $msgid = $args[0] ?? null;
        if ( ! is_string( $msgid ) || $msgid === '' ) {
            continue;
        }

        $msgctxt = '';
        if ( $signature['ctx'] !== null ) {
            $value = $args[ $signature['ctx'] ] ?? null;
            if ( ! is_string( $value ) ) {
                continue;
            }
            $msgctxt = $value;
        }

        $calls[] = [
            'msgctxt'  => tt_po_escape( $msgctxt ),
            'msgid'    => tt_po_escape( $msgid ),
            'line'     => (int) $token[2],
            'function' => $name,
        ];
    }

    return $calls;
}

/**
 * The literal value of each argument of a call, or null for an argument
 * that is not a plain string literal.
 *
 * @return array<int, string|null>|null null when the call never closes
 */
function tt_gettext_arguments( array $tokens, int $open, int $count ): ?array {
    $depth   = 0;
    $args    = [];
    $index   = 0;
    $value   = '';
    $literal = true;
    $any     = false;

    for ( $i = $open; $i < $count; $i++ ) {
        $token = $tokens[ $i ];
        $text  = is_array( $token ) ? $token[1] : $token;

        if ( is_array( $token ) && in_array(
            $token[0],
            [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ],
            true
        ) ) {
            continue;
        }

        if ( $text === '(' || $text === '[' ) {
            $depth++;
            if ( $depth === 1 ) {
                continue;
            }
            $literal = false;
            continue;
        }

        if ( $text === ')' || $text === ']' ) {
            $depth--;
            if ( $depth === 0 ) {
                $args[ $index ] = $any && $literal ? $value : null;

                return $args;
            }
            $literal = false;
            continue;
        }

        if ( $depth === 1 && $text === ',' ) {
            $args[ $index ] = $any && $literal ? $value : null;
            $index++;
            $value   = '';
            $literal = true;
            $any     = false;
            continue;
        }

        if ( $depth !== 1 ) {
            continue;
        }

        if ( is_array( $token ) && $token[0] === T_CONSTANT_ENCAPSED_STRING ) {
            $value .= tt_gettext_literal( $token[1] );
            $any    = true;
            continue;
        }

        // A `.` between two literals keeps the argument checkable; anything
        // else appearing in it does not.
        if ( $text === '.' ) {
            continue;
        }

        $literal = false;
    }

    return null;
}

/** The value of a PHP string literal token. */
function tt_gettext_literal( string $token ): string {
    $quote = $token[0];
    $body  = substr( $token, 1, -1 );

    if ( $quote === "'" ) {
        return str_replace( [ "\\'", '\\\\' ], [ "'", '\\' ], $body );
    }

    $decoded = preg_replace_callback(
        '/\\\\(n|t|r|v|f|e|\\\\|"|\$|[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})/',
        static function ( array $m ): string {
            switch ( $m[1] ) {
                case 'n':  return "\n";
                case 't':  return "\t";
                case 'r':  return "\r";
                case 'v':  return "\v";
                case 'f':  return "\f";
                case 'e':  return "\033";
                case '\\': return '\\';
                case '"':  return '"';
                case '$':  return '$';
            }
            if ( $m[1][0] === 'x' ) {
                return chr( (int) hexdec( substr( $m[1], 1 ) ) );
            }
            if ( $m[1][0] === 'u' ) {
                $code = (int) hexdec( trim( substr( $m[1], 1 ), '{}' ) );

                return function_exists( 'mb_chr' ) ? (string) mb_chr( $code, 'UTF-8' ) : $m[0];
            }

            return chr( (int) octdec( $m[1] ) );
        },
        $body
    );

    return $decoded ?? $body;
}

/**
 * A PHP string value as a `.po` file spells it.
 *
 * The catalogue stores escaped text, so the comparison happens in that
 * form — otherwise a msgid containing a newline or a quote never matches
 * its own entry.
 */
function tt_po_escape( string $value ): string {
    return str_replace(
        [ '\\', '"', "\n", "\t", "\r" ],
        [ '\\\\', '\\"', '\\n', '\\t', '\\r' ],
        $value
    );
}

/** @return array|string|null */
function tt_gettext_previous( array $tokens, int $i ) {
    for ( $j = $i - 1; $j >= 0; $j-- ) {
        if ( is_array( $tokens[ $j ] ) && in_array(
            $tokens[ $j ][0],
            [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ],
            true
        ) ) {
            continue;
        }

        return $tokens[ $j ];
    }

    return null;
}

/**
 * @param int|null $position set to the index of the returned token
 * @return array|string|null
 */
function tt_gettext_next( array $tokens, int $i, &$position = null ) {
    $count = count( $tokens );
    for ( $j = $i + 1; $j < $count; $j++ ) {
        if ( is_array( $tokens[ $j ] ) && in_array(
            $tokens[ $j ][0],
            [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ],
            true
        ) ) {
            continue;
        }
        $position = $j;

        return $tokens[ $j ];
    }

    return null;
}
