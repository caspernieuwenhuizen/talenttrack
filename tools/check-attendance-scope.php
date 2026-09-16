<?php
/**
 * check-attendance-scope.php (#3451)
 *
 * `tt_attendance` holds two kinds of row, told apart by one column:
 *
 *   `expected` — a squad somebody PLANNED
 *   `actual`   — a register somebody TOOK
 *
 * Migration 0121 introduced the split with `DEFAULT 'actual'`, so nothing
 * about the table's shape warns a reader, and the cheapest way to get it
 * wrong is to say nothing at all. Ten defects came out of that in one
 * week — eight reads returning the wrong answer, and two writes that
 * destroyed a planned roster (#3456, #3451) and hid a match's minutes
 * where no correct reader could find them (#3445).
 *
 * The writes were closed by construction: `AttendanceWriter` has no method
 * that writes a row without naming the kind. Thirty files READ the table,
 * and most of them get the distinction right by accident, so a refactor
 * would be out of proportion to the risk. This gate is the proportionate
 * half: every function that touches `tt_attendance` must either name
 * `record_type`, or say in a comment that it means both kinds.
 *
 * THE MARKER IS THE POINT, NOT AN ESCAPE HATCH
 *
 * Several queries legitimately span both kinds, and scoping them would be
 * the bug. `GdprSubjectAccessZipExporter` is the clearest: a subject-access
 * request must return everything held about the person, so narrowing it
 * would be a compliance defect, not a fix. The backup, archive-cascade and
 * privacy registries are the same shape — they enumerate the table, they do
 * not interpret it.
 *
 * So the exemption ships WITH the check rather than being bolted on the
 * first time it fires, and it is a comment at the site rather than a list
 * in this file: the person who has to decide is the person editing the
 * query, and the decision belongs next to it. The marker is a block
 * comment reading `both-kinds-ok`, anywhere inside the function.
 *
 * A marker on a query that is actually wrong is worse than no gate at all,
 * so do not add one to make the build pass. Scope it, or say why not.
 *
 * What counts as naming it, and what this check deliberately cannot decide,
 * are in `tools/lib/attendance-scope.php` alongside the parser.
 *
 * Usage:  php tools/check-attendance-scope.php
 * Exit:   0 clean, 1 an unscoped, unmarked query (or nothing parsed at all).
 */

declare( strict_types = 1 );

// The parser lives in a lib so it can be tested against snippets rather than
// against the repository (tests/php/AttendanceScopeGateTest.php). A gate whose
// own behaviour is unverified is the same category of problem as the bug it
// exists to catch.
require_once __DIR__ . '/lib/attendance-scope.php';

$root = dirname( __DIR__ );
$src  = $root . '/src';

if ( ! is_dir( $src ) ) {
    fwrite( STDERR, "check-attendance-scope: cannot read src/\n" );
    exit( 1 );
}

$offenders = [];
$scoped    = 0;
$marked    = 0;

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS )
);

foreach ( $it as $file ) {
    if ( ! $file->isFile() || strtolower( $file->getExtension() ) !== 'php' ) continue;

    $code = (string) @file_get_contents( $file->getPathname() );
    if ( strpos( $code, TT_ATTENDANCE_TABLE ) === false ) continue;

    // Normalise both sides before stripping: the iterator yields Windows
    // separators while $root may carry forward slashes.
    $relative = str_replace( '\\', '/', $file->getPathname() );
    $prefix   = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
    if ( strpos( $relative, $prefix ) === 0 ) {
        $relative = substr( $relative, strlen( $prefix ) );
    }

    foreach ( tt_attendance_units( $code ) as $unit ) {
        if ( $unit['scoped'] ) {
            $scoped++;
            continue;
        }
        if ( $unit['marked'] ) {
            $marked++;
            continue;
        }
        $offenders[] = [ $relative, $unit['line'], $unit['name'] ];
    }
}

$checked = $scoped + $marked + count( $offenders );

if ( $checked === 0 ) {
    fwrite(
        STDERR,
        "check-attendance-scope: parsed no `" . TT_ATTENDANCE_TABLE . "` queries at all — "
        . "either the table was renamed or this gate has gone blind. Fix the gate before merging.\n"
    );
    exit( 1 );
}

if ( ! $offenders ) {
    printf(
        "check-attendance-scope OK — %d site(s): %d name %s, %d marked as spanning both kinds.\n",
        $checked,
        $scoped,
        TT_SCOPE_COLUMN,
        $marked
    );
    exit( 0 );
}

echo "check-attendance-scope FAILED — query/queries against `" . TT_ATTENDANCE_TABLE
   . "` that do not say which kind of row they mean:\n\n";
foreach ( $offenders as [ $relative, $line, $name ] ) {
    printf( "  %s:%d\n    in %s\n", $relative, $line, $name );
}

echo "\n";
echo "`" . TT_ATTENDANCE_TABLE . "` holds a planned squad (record_type = 'expected')\n";
echo "and a recorded register (record_type = 'actual') in the same table, and the\n";
echo "planned rows carry real statuses — Expected is stored as `Present`. A query\n";
echo "that asks \"was this player there?\" without naming the column gets yes from a\n";
echo "squad nobody has registered.\n\n";
echo "Either add the scope:\n\n";
echo "    AND record_type = 'actual'      -- what happened\n";
echo "    AND record_type = 'expected'    -- what was planned\n\n";
echo "...or, if the query genuinely wants both (a backup, an export, a subject-access\n";
echo "request), put a trailing comment on it saying so:\n\n";
echo "    /* " . TT_BOTH_KINDS_MARKER . " */\n\n";
echo "Writes do not need a marker — they go through AttendanceWriter, whose method\n";
echo "names ARE the declaration (recordActual / planExpected / clearActual / …).\n";

exit( 1 );
