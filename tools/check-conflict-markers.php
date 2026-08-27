<?php
/**
 * check-conflict-markers.php (#2891)
 *
 * Fails when a git conflict marker has been committed as literal file
 * content. `docs/rest-api.md` reached main carrying one for three days
 * (#2889): git cannot three-way-merge a file that already contains a
 * marker, so every branch touching that file afterwards hit
 * `error: could not parse conflict hunks` and had to resolve by hand.
 *
 * Twenty other workflows lint this repository and none of them looked at
 * file bodies for markers, which is why it merged.
 *
 * Detection — read this before "improving" the patterns:
 *
 *   The obvious rule, "flag any line of seven `<`, `=` or `>`", is wrong
 *   for `=======`: a run of equals signs directly under a line of text is
 *   a Markdown setext H1 underline, and a seven-character heading gets a
 *   seven-character underline. Requiring exactly seven does not
 *   disambiguate it either — `Heading` is seven characters.
 *
 *   So this scans for the two markers that have no other meaning in any
 *   language we write here: `<<<<<<<` and `>>>>>>>` at the start of a
 *   line. Git always emits all three markers together, so finding either
 *   angle marker is sufficient to catch a conflict — no `=======` rule is
 *   needed, and none can produce a false positive on prose.
 *
 *   `=======` IS reported, but only inside a file that already tripped an
 *   angle marker, where it is context for the human reading the failure
 *   rather than the thing that failed the build.
 *
 * Usage:  php tools/check-conflict-markers.php
 * Exit:   0 clean, 1 markers found.
 */

declare( strict_types = 1 );

$root = dirname( __DIR__ );

/** Directories worth scanning. Everything here is text we author. */
$scan_dirs = [ 'docs', 'src', 'config', 'assets', 'tests', 'tools', 'database', '.github' ];

/** Extensions to read. Anything else is binary or generated. */
$extensions = [ 'php', 'md', 'js', 'css', 'json', 'yml', 'yaml', 'txt', 'html', 'po', 'pot', 'sql', 'xml', 'ps1' ];

$open_marker  = '/^<{7}(\s|$)/';
$close_marker = '/^>{7}(\s|$)/';
$mid_marker   = '/^={7}$/';

$failures = [];

foreach ( $scan_dirs as $dir ) {
    $path = $root . DIRECTORY_SEPARATOR . $dir;
    if ( ! is_dir( $path ) ) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
    );

    foreach ( $iterator as $file ) {
        if ( ! $file->isFile() ) {
            continue;
        }
        $ext = strtolower( $file->getExtension() );
        if ( ! in_array( $ext, $extensions, true ) ) {
            continue;
        }

        $relative = str_replace( $root . DIRECTORY_SEPARATOR, '', $file->getPathname() );
        $relative = str_replace( '\\', '/', $relative );

        // This file necessarily contains the patterns it searches for.
        if ( $relative === 'tools/check-conflict-markers.php' ) {
            continue;
        }

        $contents = @file_get_contents( $file->getPathname() );
        if ( $contents === false ) {
            continue;
        }

        // Fast path. The overwhelming majority of files contain neither
        // marker, and this check runs over the whole corpus on every PR —
        // a substring test against the raw bytes skips the per-line regex
        // for all of them. Without it the scan takes minutes; the
        // `languages/*.po` catalogues alone are ~56k lines each.
        if ( strpos( $contents, '<<<<<<<' ) === false && strpos( $contents, '>>>>>>>' ) === false ) {
            continue;
        }

        $lines = explode( "\n", str_replace( "\r\n", "\n", $contents ) );

        $hits = [];
        $mids = [];
        foreach ( $lines as $i => $line ) {
            if ( preg_match( $open_marker, $line ) || preg_match( $close_marker, $line ) ) {
                $hits[] = [ $i + 1, $line ];
            } elseif ( preg_match( $mid_marker, $line ) ) {
                $mids[] = [ $i + 1, $line ];
            }
        }

        if ( $hits ) {
            // Only now is a bare `=======` meaningful — the file is already
            // known to hold a conflict, so the separator is context.
            $failures[ $relative ] = array_merge( $hits, $mids );
        }
    }
}

if ( ! $failures ) {
    echo "check-conflict-markers OK — no committed conflict markers.\n";
    exit( 0 );
}

$count = 0;
echo "check-conflict-markers FAILED — committed git conflict marker(s):\n\n";
foreach ( $failures as $relative => $hits ) {
    foreach ( $hits as [ $line_no, $line ] ) {
        $count++;
        printf( "  %s:%d\n    %s\n", $relative, $line_no, trim( $line ) );
    }
}

echo "\n";
echo "A conflict marker in a committed file is not only cosmetic: git cannot\n";
echo "three-way-merge a file that already contains one, so every later branch\n";
echo "touching it fails with 'could not parse conflict hunks' and has to be\n";
echo "resolved by hand. Delete the marker lines and keep the intended content.\n";
printf( "\n%d marker line(s) in %d file(s).\n", $count, count( $failures ) );

exit( 1 );
