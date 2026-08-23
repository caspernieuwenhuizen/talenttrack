<?php
/**
 * #2720 — a `tt_view` URL must resolve the page hosting the dashboard
 * shortcode, not the site root.
 *
 * `home_url( '/' )` is the front page. The `[talenttrack_dashboard]`
 * shortcode usually lives somewhere else, and `tt_view` is only read where
 * that shortcode runs — so a link built on `home_url()` drops the user on
 * the theme's homepage with no indication anything went wrong. It has been
 * fixed by hand twice now (v3.70.1, then #2716), which is what a gate is
 * for.
 *
 * Use `RecordLink::dashboardUrl()` for a plain view, or
 * `RecordLink::detailUrlFor( $slug, $id )` for a record.
 *
 * Deliberately NOT flagged: a base that only falls back to `home_url()`,
 * such as `remove_query_arg( ... ) ?: home_url( '/' )`. Those rebuild from
 * the current request — already the right page — and reach `home_url()`
 * only when there is no request at all.
 *
 * Escape hatch: put `tt-dashboard-url-ok` in a comment inside the call.
 *
 * usage: php tools/check-dashboard-urls.php
 */

$root = dirname( __DIR__ );
$src  = $root . '/src';

if ( ! is_dir( $src ) ) {
    fwrite( STDERR, "check-dashboard-urls: no src/ directory at {$src}\n" );
    exit( 1 );
}

/**
 * Every `add_query_arg(...)` call in a file, as raw source text.
 *
 * Tokenised rather than pattern-matched: these calls routinely span four
 * or five lines, and a line-based scan is exactly how the original triage
 * for #2720 undercounted the problem by more than half.
 *
 * @return list<array{text:string, line:int}>
 */
function tt_add_query_arg_calls( string $code ): array {
    $tokens = token_get_all( $code );
    $count  = count( $tokens );
    $calls  = [];

    for ( $i = 0; $i < $count; $i++ ) {
        $t = $tokens[ $i ];
        if ( ! is_array( $t ) || $t[0] !== T_STRING || $t[1] !== 'add_query_arg' ) continue;

        // A method or property of the same name is not the function.
        $prev = $i > 0 ? $tokens[ $i - 1 ] : null;
        if ( is_array( $prev ) && in_array( $prev[0], [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ], true ) ) continue;

        $j = $i + 1;
        while ( $j < $count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) $j++;
        if ( $j >= $count || $tokens[ $j ] !== '(' ) continue;

        $depth = 0;
        $text  = '';
        for ( $k = $j; $k < $count; $k++ ) {
            $tk    = $tokens[ $k ];
            $piece = is_array( $tk ) ? $tk[1] : $tk;

            if ( $piece === '(' ) $depth++;
            if ( $piece === ')' ) $depth--;

            $text .= $piece;
            if ( $depth === 0 ) break;
        }

        $calls[] = [ 'text' => $text, 'line' => $t[2] ];
    }

    return $calls;
}

/** Split a balanced "( ... )" string into its top-level arguments. */
function tt_split_args( string $call ): array {
    $inner = substr( $call, 1, -1 );
    $args  = [];
    $depth = 0;
    $buf   = '';
    $len   = strlen( $inner );

    for ( $i = 0; $i < $len; $i++ ) {
        $c = $inner[ $i ];

        if ( $c === '(' || $c === '[' ) $depth++;
        if ( $c === ')' || $c === ']' ) $depth--;

        if ( $c === ',' && $depth === 0 ) {
            $args[] = $buf;
            $buf    = '';
            continue;
        }

        $buf .= $c;
    }

    if ( trim( $buf ) !== '' ) $args[] = $buf;

    return $args;
}

$violations = [];
$scanned    = 0;

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
    if ( $file->getExtension() !== 'php' ) continue;

    $code = (string) file_get_contents( $file->getPathname() );
    $scanned++;

    foreach ( tt_add_query_arg_calls( $code ) as $call ) {
        if ( strpos( $call['text'], 'tt_view' ) === false ) continue;
        if ( strpos( $call['text'], 'tt-dashboard-url-ok' ) !== false ) continue;

        $args = tt_split_args( $call['text'] );
        if ( ! $args ) continue;

        // The base URL is add_query_arg()'s final argument. Only a *bare*
        // home_url() there is wrong; `$something ?: home_url('/')` is fine.
        $base = trim( (string) end( $args ) );
        if ( strpos( $base, 'home_url' ) !== 0 ) continue;

        $violations[] = sprintf(
            '%s:%d  base is home_url() — use RecordLink::dashboardUrl() or ::detailUrlFor()',
            str_replace( $root . DIRECTORY_SEPARATOR, '', $file->getPathname() ),
            $call['line']
        );
    }
}

if ( $violations ) {
    fwrite( STDERR, "check-dashboard-urls FAILED — tt_view URLs built on the site root:\n\n" );
    foreach ( $violations as $v ) fwrite( STDERR, "  {$v}\n" );
    fwrite( STDERR, "\n" . count( $violations ) . " violation(s). See CLAUDE.md and docs/back-navigation.md.\n" );
    exit( 1 );
}

echo "check-dashboard-urls OK — {$scanned} files scanned, no tt_view URL built on home_url().\n";
