<?php
/**
 * The parser behind `tools/check-attendance-scope.php` (#3451).
 *
 * Split out from the CLI script so it can be tested against snippets rather
 * than against the repository — a gate whose own behaviour is unverified is
 * the same category of problem as the bug it exists to catch. See
 * `tests/php/AttendanceScopeGateTest.php`.
 *
 * WHAT IT DECIDES
 *
 * `tt_attendance` holds a planned squad (`record_type = 'expected'`) and a
 * recorded register (`record_type = 'actual'`) in one table. A query that
 * does not name the column gets both, and the planned rows carry real
 * statuses, so "was this player present?" answers yes from a selection
 * nobody has registered.
 *
 * The unit of judgement is the enclosing FUNCTION, not the statement. A
 * query built in pieces —
 *
 *     $sql = "SELECT … FROM {$p}tt_attendance WHERE …";
 *     if ( $record_type !== null ) $sql .= ' AND record_type = %s';
 *
 * — is properly scoped, and a statement-level check would call it a
 * violation and push somebody towards a marker that lies. Any mention of
 * `record_type` inside the function satisfies it: a column in an INSERT map,
 * a WHERE clause, a `$record_type` parameter.
 *
 * The innermost function wins, so a closure is judged on its own and does
 * not inherit its parent's scoping.
 *
 * WHAT IT CANNOT DECIDE
 *
 * It reads for the word, not for the meaning. A function that selects
 * `record_type` and then ignores it passes, and so does one that scopes a
 * first query and leaves a second one open. It is a review aid that makes
 * the question unavoidable, not a proof that the answer is right — which is
 * why the marker asks for a sentence rather than a flag.
 */

declare( strict_types = 1 );

const TT_ATTENDANCE_TABLE  = 'tt_attendance';
const TT_SCOPE_COLUMN      = 'record_type';
const TT_BOTH_KINDS_MARKER = 'both-kinds-ok';

/**
 * Every function in the file that mentions the table, with whether it is
 * scoped and whether it is marked.
 *
 * @return list<array{name:string, line:int, scoped:bool, marked:bool}>
 */
function tt_attendance_units( string $code ): array {
    $tokens = token_get_all( $code );
    $count  = count( $tokens );

    // Function bodies as [openIndex, closeIndex, name, line]. Properly
    // nested, so the innermost enclosing unit is the one with the largest
    // open index that still contains the occurrence.
    $units = [];
    for ( $i = 0; $i < $count; $i++ ) {
        $tok = $tokens[ $i ];
        if ( ! is_array( $tok ) || $tok[0] !== T_FUNCTION ) continue;

        $name = tt_function_name( $tokens, $i );
        $open = tt_body_open( $tokens, $i, $count );
        if ( $open === null ) continue;   // abstract / interface declaration

        $close = tt_body_close( $tokens, $open, $count );
        if ( $close === null ) continue;

        $units[] = [
            'open'  => $open,
            'close' => $close,
            'name'  => $name,
            'line'  => (int) $tok[2],
        ];
    }

    // Occurrences of the table name in code (never in comments), and the
    // tokens carrying an explicit both-kinds marker.
    $hits    = [];
    $markers = [];
    foreach ( $tokens as $index => $tok ) {
        if ( ! is_array( $tok ) ) continue;

        if ( $tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT ) {
            if ( strpos( $tok[1], TT_BOTH_KINDS_MARKER ) !== false ) {
                $markers[] = $index;
            }
            continue;
        }
        if ( strpos( $tok[1], TT_ATTENDANCE_TABLE ) !== false ) {
            $hits[] = [ 'index' => $index, 'line' => (int) $tok[2] ];
        }
    }

    if ( $hits === [] ) return [];

    $out  = [];
    $seen = [];
    foreach ( $hits as $hit ) {
        $unit = tt_innermost_unit( $units, $hit['index'] );

        if ( $unit === null ) {
            // Top-level code — a registry array, a bootstrap. The whole file
            // is the unit; an occurrence out here is a declaration rather
            // than a query.
            if ( isset( $seen['file'] ) ) continue;
            $seen['file'] = true;

            $out[] = [
                'name'   => 'file scope',
                'line'   => $hit['line'],
                'scoped' => strpos( $code, TT_SCOPE_COLUMN ) !== false,
                'marked' => $markers !== [],
            ];
            continue;
        }

        $key = (string) $unit['open'];
        if ( isset( $seen[ $key ] ) ) continue;
        $seen[ $key ] = true;

        $marked = false;
        foreach ( $markers as $marker_index ) {
            if ( $marker_index > $unit['open'] && $marker_index < $unit['close'] ) {
                $marked = true;
                break;
            }
        }

        $out[] = [
            'name'   => $unit['name'],
            'line'   => $hit['line'],
            'scoped' => strpos(
                tt_unit_code( $tokens, $unit['open'], $unit['close'] ),
                TT_SCOPE_COLUMN
            ) !== false,
            'marked' => $marked,
        ];
    }

    return $out;
}

/**
 * @param list<array{open:int, close:int, name:string, line:int}> $units
 * @return array{open:int, close:int, name:string, line:int}|null
 */
function tt_innermost_unit( array $units, int $index ): ?array {
    $best = null;
    foreach ( $units as $unit ) {
        if ( $index <= $unit['open'] || $index >= $unit['close'] ) continue;
        if ( $best === null || $unit['open'] > $best['open'] ) {
            $best = $unit;
        }
    }
    return $best;
}

/**
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 */
function tt_function_name( array $tokens, int $function_index ): string {
    for ( $i = $function_index + 1, $n = count( $tokens ); $i < $n; $i++ ) {
        $tok = $tokens[ $i ];
        if ( $tok === '(' ) break;
        if ( is_array( $tok ) && $tok[0] === T_STRING ) {
            return $tok[1] . '()';
        }
    }
    return 'closure';
}

/**
 * Index of the `{` that opens the function body, or null when the
 * declaration ends in `;` (abstract method, interface method).
 *
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 */
function tt_body_open( array $tokens, int $function_index, int $count ): ?int {
    for ( $i = $function_index + 1; $i < $count; $i++ ) {
        $tok = $tokens[ $i ];
        if ( $tok === '{' ) return $i;
        if ( $tok === ';' ) return null;
    }
    return null;
}

/**
 * Index of the matching `}`. `T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES`
 * are string-interpolation openers whose closer is a plain `}`, so they have
 * to count or the match lands in the wrong place — which matters here,
 * because an interpolated table name is exactly what these queries contain.
 *
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 */
function tt_body_close( array $tokens, int $open, int $count ): ?int {
    $depth = 0;
    for ( $i = $open; $i < $count; $i++ ) {
        $tok = $tokens[ $i ];
        if ( $tok === '{' ) {
            $depth++;
            continue;
        }
        if ( $tok === '}' ) {
            $depth--;
            if ( $depth === 0 ) return $i;
            continue;
        }
        if ( is_array( $tok ) && ( $tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES ) ) {
            $depth++;
        }
    }
    return null;
}

/**
 * The unit's source with comments dropped, so a docblock explaining the
 * distinction cannot be mistaken for a query that honours it.
 *
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 */
function tt_unit_code( array $tokens, int $open, int $close ): string {
    $out = '';
    for ( $i = $open; $i <= $close; $i++ ) {
        $tok = $tokens[ $i ];
        if ( is_string( $tok ) ) {
            $out .= $tok;
            continue;
        }
        if ( $tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT ) continue;
        $out .= $tok[1];
    }
    return $out;
}
