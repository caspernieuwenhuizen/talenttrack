<?php
/**
 * check-search-text.php (#4671)
 *
 * Search matches on folded columns (`search_title`, `search_text`) that the
 * write paths keep up to date by calling `SearchText::refresh()`. A write
 * that changes a name, a title or a location without it leaves search
 * matching the old text until the sweep comes by. That is a missed match,
 * never a disclosure, but it is the kind of drift a gate catches better
 * than review.
 *
 * WHAT IT ASSERTS
 *
 * Every function or method under `src/` that writes `tt_players`,
 * `tt_teams`, `tt_activities` or `tt_people` either calls
 * `SearchText::refresh(` or carries a marker saying why it need not:
 *
 *     /* search-text-ok: no source column *\/
 *
 * for a write that touches none of the folded source columns (an account
 * link, a status, a flag). A write is:
 *
 *   - `$wpdb->insert|update|replace(` whose table argument names one of
 *     the tables;
 *   - an `INSERT [IGNORE] INTO` or `UPDATE` statement on one of them;
 *   - an `insert|update|replace(` on a variable assigned one of the tables
 *     in the same function.
 *
 * `src/Infrastructure/Search/` is the writer of the folded columns and is
 * not scanned.
 *
 * Usage:  php tools/check-search-text.php
 * Exit:   0 clean, 1 a write with neither a refresh nor a marker.
 */

$root  = dirname( __DIR__ );
$src   = $root . '/src';
$skip  = str_replace( '\\', '/', $src . '/Infrastructure/Search/' );
$table = '(?:tt_players|tt_teams|tt_activities|tt_people)\b';

$patterns = [
    '/->(?:insert|update|replace)\(\s*[^,;]*?' . $table . '/s',
    '/(?:INSERT\s+(?:IGNORE\s+)?INTO|UPDATE)\s+[^\s]*?' . $table . '/i',
];

/**
 * Function bodies of a PHP file, as [name, line, body].
 *
 * @return list<array{0:string,1:int,2:string}>
 */
function tt_search_text_functions( string $code ): array {
    $tokens = token_get_all( $code );
    $out    = [];
    $count  = count( $tokens );
    for ( $i = 0; $i < $count; $i++ ) {
        if ( ! is_array( $tokens[ $i ] ) || $tokens[ $i ][0] !== T_FUNCTION ) continue;
        $line = (int) $tokens[ $i ][2];
        $name = '{closure}';
        for ( $j = $i + 1; $j < $count; $j++ ) {
            if ( is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_STRING ) { $name = $tokens[ $j ][1]; break; }
            if ( $tokens[ $j ] === '(' ) break;
        }
        // Find the opening brace of the body (an abstract or interface
        // method ends with ';' first).
        $k = $i + 1;
        for ( ; $k < $count; $k++ ) {
            if ( $tokens[ $k ] === ';' ) { $k = -1; break; }
            if ( $tokens[ $k ] === '{' || ( is_array( $tokens[ $k ] ) && $tokens[ $k ][0] === T_CURLY_OPEN ) ) break;
        }
        if ( $k < 0 || $k >= $count ) continue;
        $depth = 0;
        $body  = '';
        for ( $m = $k; $m < $count; $m++ ) {
            $t = $tokens[ $m ];
            $text = is_array( $t ) ? $t[1] : $t;
            if ( $text === '{' || ( is_array( $t ) && in_array( $t[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) ) $depth++;
            if ( $text === '}' ) $depth--;
            $body .= $text;
            if ( $depth === 0 ) break;
        }
        $out[] = [ $name, $line, $body ];
    }
    return $out;
}

$offenders = [];
$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
foreach ( $rii as $file ) {
    if ( $file->isDir() || $file->getExtension() !== 'php' ) continue;
    $path = str_replace( '\\', '/', $file->getPathname() );
    if ( strpos( $path, $skip ) === 0 ) continue;
    $code = (string) file_get_contents( $path );
    if ( ! preg_match( '/' . $table . '/', $code ) ) continue;

    $seen = [];
    foreach ( tt_search_text_functions( $code ) as [ $name, $line, $body ] ) {
        $writes = false;
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $body ) ) { $writes = true; break; }
        }
        if ( ! $writes && preg_match_all( '/(\$[a-zA-Z_]+)\s*=\s*[^;]*?' . $table . '[\'"]?\s*;/', $body, $vars ) ) {
            foreach ( array_unique( $vars[1] ) as $var ) {
                if ( preg_match( '/->(?:insert|update|replace)\(\s*' . preg_quote( $var, '/' ) . '\b/', $body ) ) { $writes = true; break; }
            }
        }
        if ( ! $writes ) continue;
        if ( strpos( $body, 'SearchText::refresh(' ) !== false || strpos( $body, 'search-text-ok:' ) !== false ) continue;
        // A closure inside a method is reported with the method when both match.
        $key = $line;
        if ( isset( $seen[ $key ] ) ) continue;
        $seen[ $key ] = true;
        $offenders[] = substr( $path, strlen( str_replace( '\\', '/', $root ) ) + 1 ) . ':' . $line . ' ' . $name . '()';
    }
}

if ( $offenders === [] ) {
    fwrite( STDOUT, "check-search-text OK — every write to a searchable table refreshes its search text or says why not.\n" );
    exit( 0 );
}

fwrite( STDOUT, "check-search-text FAILED — these write a searchable table without SearchText::refresh():\n" );
foreach ( $offenders as $offender ) fwrite( STDOUT, "  {$offender}\n" );
fwrite( STDOUT, "\nCall \\TT\\Infrastructure\\Search\\SearchText::refresh( '<kind>', \$ids ) after the write, or mark a write that touches\n" );
fwrite( STDOUT, "no name, title, location or age group with /* search-text-ok: no source column */ inside the function.\n" );
exit( 1 );
