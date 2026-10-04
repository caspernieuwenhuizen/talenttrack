<?php
/**
 * check-nul-bytes.php (#4242)
 *
 * Fails when a script or stylesheet under `assets/js/` or `assets/css/`
 * contains a NUL byte (0x00).
 *
 * `assets/js/components/frontend-list-table.js` carried one raw NUL inside
 * a string literal used as a sentinel. The script ran correctly, but one
 * such byte makes the whole file binary to tooling: ripgrep and `git grep`
 * skip it, `git diff` reports "Binary files differ", and code search and
 * review miss every match in it. Nothing looked at file bytes, so it sat
 * there unnoticed.
 *
 * A NUL is never needed in source. Where the character itself is meant,
 * write the escape: `'\u0000'` in JavaScript, `\0` in a CSS string.
 *
 * Every file under the two directories is read, whatever its extension:
 * they hold text only. A binary asset (font, image) belongs elsewhere
 * under `assets/`.
 *
 * Usage:  php tools/check-nul-bytes.php [root]
 *         `root` defaults to the repository; pass another directory to
 *         run the check against a copy.
 * Exit:   0 clean, 1 NUL byte(s) found, 2 nothing scanned.
 */

declare( strict_types = 1 );

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : dirname( __DIR__ );

$scan_dirs = [ 'assets/js', 'assets/css' ];

$scanned  = 0;
$failures = [];

foreach ( $scan_dirs as $dir ) {
    $path = $root . '/' . $dir;
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

        $contents = file_get_contents( $file->getPathname() );
        if ( $contents === false ) {
            continue;
        }
        $scanned++;

        $offset = strpos( $contents, "\0" );
        if ( $offset === false ) {
            continue;
        }

        $relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );

        $failures[] = [
            'file'   => $relative,
            'count'  => substr_count( $contents, "\0" ),
            'offset' => $offset,
            'line'   => substr_count( $contents, "\n", 0, $offset ) + 1,
        ];
    }
}

if ( $scanned === 0 ) {
    echo "check-nul-bytes FAILED — no files found under " . implode( ', ', $scan_dirs ) . " in {$root}.\n";
    exit( 2 );
}

if ( ! $failures ) {
    printf( "check-nul-bytes OK — %d file(s) scanned, no NUL bytes.\n", $scanned );
    exit( 0 );
}

echo "check-nul-bytes FAILED — NUL byte(s) in source:\n\n";
foreach ( $failures as $failure ) {
    printf(
        "  %s:%d — %d NUL byte(s), first at byte offset %d\n",
        $failure['file'],
        $failure['line'],
        $failure['count'],
        $failure['offset']
    );
}

echo "\n";
echo "A NUL byte makes the file binary to ripgrep, git grep and git diff, so\n";
echo "search and review skip it. Replace the raw byte with its escape\n";
echo "('\\u0000' in JavaScript, '\\0' in a CSS string); the runtime value is\n";
echo "the same.\n";
printf( "\n%d file(s) with NUL bytes, %d scanned.\n", count( $failures ), $scanned );

exit( 1 );
