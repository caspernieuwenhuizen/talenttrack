<?php
/**
 * archive-list-view.php (#5118) — the parser behind
 * `tools/check-archive-list-view.php`, kept apart so it can be tested on
 * snippets (`tests/php/ArchiveListViewGateTest.php`).
 *
 * `ArchiveRepository::filterClause( $view, $alias )` answers every archive
 * state, the recycle bin (`trashed`) included. An ordinary list must never
 * serve the bin, so outside the archive and recycle-bin code each call must
 * be given either a literal state (`'active'`, `'archived'`, `'all'`) or a
 * `listView()` result. A variable, a request value or a `sanitizeView()`
 * result is how `?tt_view=trashed` reached a list. `listViewClause()` is the
 * shorthand for the second shape and is always fine.
 */

declare( strict_types = 1 );

/** Paths (relative to the repository) allowed to pass any state. */
const TT_ARCHIVE_VIEW_EXEMPT = [
    'src/Infrastructure/Archive/',
    'src/Shared/Frontend/FrontendRecycleBinView.php',
];

/**
 * The line numbers of `filterClause()` calls whose state is neither a
 * literal ordinary-list state nor a `listView()` result. Comments are
 * skipped; the method's own definition is not a call.
 *
 * @return list<int>
 */
function tt_archive_view_offenders( string $code ): array {
    $out = [];
    if ( ! preg_match_all( '/filterClause\s*\(/', $code, $matches, PREG_OFFSET_CAPTURE ) ) return $out;
    foreach ( $matches[0] as [ $text, $offset ] ) {
        $line_start = strrpos( substr( $code, 0, $offset ), "\n" );
        $line_start = $line_start === false ? 0 : $line_start + 1;
        $line_end   = strpos( $code, "\n", $offset );
        $line       = substr( $code, $line_start, ( $line_end === false ? strlen( $code ) : $line_end ) - $line_start );
        $trim       = ltrim( $line );
        if ( str_starts_with( $trim, '*' ) || str_starts_with( $trim, '//' ) || str_starts_with( $trim, '/*' ) || str_starts_with( $trim, '#' ) ) continue;
        // The definition, or a mention inside a docblock-like line.
        if ( preg_match( '/function\s+filterClause\s*\(/', $line ) === 1 ) continue;
        // Only the call itself, not `listViewClause(` or a name ending in it.
        $prev = $offset > 0 ? $code[ $offset - 1 ] : '';
        if ( $prev !== ':' && $prev !== '>' && $prev !== ' ' && $prev !== '(' ) continue;

        $rest  = ltrim( substr( $code, $offset + strlen( $text ) ) );
        $state = '[\'"](?:active|archived|all)[\'"]';
        if ( preg_match( '/^' . $state . '/', $rest ) === 1 ) continue;
        // A choice between two literal states: `$v === 'archived' ? 'archived' : 'active'`.
        if ( preg_match( '/^\$\w+\s*===?\s*[\'"][a-z]+[\'"]\s*\?\s*' . $state . '\s*:\s*' . $state . '/', $rest ) === 1 ) continue;
        if ( preg_match( '/^(?:\\\\?(?:TT\\\\Infrastructure\\\\Archive\\\\)?ArchiveRepository|self|static)::listView\s*\(/', $rest ) === 1 ) continue;
        $out[] = substr_count( substr( $code, 0, $offset ), "\n" ) + 1;
    }
    return $out;
}
