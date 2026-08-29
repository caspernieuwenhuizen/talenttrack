<?php
/**
 * check-changelog-snippets.php (#3043)
 *
 * Validates the *shape* of every `changelog.d/*.md` snippet, not merely
 * that one exists.
 *
 * Cutting v4.108.0, seven of the nine snippets in the batch were
 * malformed and the release script degraded silently rather than
 * failing. `release.ps1` takes the first non-empty line as the title and
 * scans from the line *after* it for a `Bump:` marker, so a snippet
 * written as
 *
 *     Bump: minor
 *
 *     Prose…
 *
 * produces a changelog entry literally titled "Bump: minor" and falls
 * back to a patch bump, because the marker was eaten as the title. Two
 * `Bump: minor` snippets in that batch computed 4.107.1.
 *
 * Nothing caught it because the existing gate looks only for a new file
 * under `changelog.d/`. It never opened one. The failure therefore
 * surfaced weeks later, in a release diff the whole two-stage design
 * exists so that nobody has to read line by line.
 *
 * Rules, in the order a reader hits them:
 *
 *   1. First non-empty line is an ATX heading — `# Something`.
 *   2. `Bump:` appears at most once, and never on the title line.
 *   3. There is a body beyond the title and the marker.
 *   4. The title carries `(#123)` — a WARNING, not a failure, since the
 *      only cost is a release note without an issue link.
 *
 * Every snippet is checked, not only the ones a PR added: a snippet
 * carried on a long-lived branch reaches a release the same way a new
 * one does, and this is cheaper than discovering it at release time.
 *
 * Usage:  php tools/check-changelog-snippets.php
 * Exit:   0 clean (warnings still print), 1 malformed snippet found.
 */

declare( strict_types = 1 );

/**
 * Problems with one snippet's shape.
 *
 * @return array{errors: list<string>, warnings: list<string>}
 */
function tt_changelog_snippet_problems( string $raw ): array {
    $errors   = [];
    $warnings = [];

    $lines = preg_split( "/\r\n|\n|\r/", trim( $raw ) );
    if ( ! is_array( $lines ) ) $lines = [];

    $title_index = null;
    foreach ( $lines as $i => $line ) {
        if ( trim( $line ) !== '' ) { $title_index = $i; break; }
    }

    if ( $title_index === null ) {
        return [ 'errors' => [ 'the file is empty' ], 'warnings' => [] ];
    }

    $title = trim( $lines[ $title_index ] );

    if ( preg_match( '/^\s*Bump:/i', $title ) === 1 ) {
        $errors[] = 'the first line is a "Bump:" marker, so the release would title the entry with it and then fall back to a patch bump — put the "# Title" heading above it';
    } elseif ( preg_match( '/^#\s+\S/', $title ) !== 1 ) {
        $errors[] = 'the first line is not an "# Title" heading — the release uses it verbatim as the changelog entry title';
    }

    $bump_count = 0;
    $body       = [];
    foreach ( $lines as $i => $line ) {
        if ( $i === $title_index ) continue;
        if ( preg_match( '/^\s*Bump:\s*(.*)$/i', $line, $m ) === 1 ) {
            $bump_count++;
            $value = strtolower( trim( $m[1] ) );
            if ( ! in_array( $value, [ 'patch', 'minor', 'major' ], true ) ) {
                $errors[] = sprintf( 'the "Bump:" line reads "%s" — it must be patch, minor or major', trim( $m[1] ) );
            }
            continue;
        }
        if ( trim( $line ) !== '' ) $body[] = $line;
    }

    if ( $bump_count > 1 ) {
        $errors[] = sprintf( 'there are %d "Bump:" lines — the release keeps the last one, which is not obviously what you meant', $bump_count );
    }

    if ( $body === [] ) {
        $errors[] = 'there is no body beyond the title and the "Bump:" marker — the release would print the title twice';
    }

    if ( preg_match( '/#\d+/', $title ) !== 1 ) {
        $warnings[] = 'the title carries no "(#123)", so the release note will have no issue link';
    }

    return [ 'errors' => $errors, 'warnings' => $warnings ];
}

// ---- CLI -------------------------------------------------------------------

if ( PHP_SAPI !== 'cli' ) return;
if ( ! isset( $argv[0] ) || realpath( $argv[0] ) !== realpath( __FILE__ ) ) return;

$root = dirname( __DIR__ );
$dir  = $root . '/changelog.d';

if ( ! is_dir( $dir ) ) {
    echo "check-changelog-snippets OK — no changelog.d directory.\n";
    exit( 0 );
}

$files = glob( $dir . '/*.md' ) ?: [];
sort( $files );

$failed   = [];
$warned   = [];
$checked  = 0;

foreach ( $files as $path ) {
    if ( basename( $path ) === 'README.md' ) continue;
    $checked++;

    $raw = (string) file_get_contents( $path );
    $out = tt_changelog_snippet_problems( $raw );

    $name = 'changelog.d/' . basename( $path );
    if ( $out['errors'] !== [] )   $failed[ $name ] = $out['errors'];
    if ( $out['warnings'] !== [] ) $warned[ $name ] = $out['warnings'];
}

foreach ( $warned as $name => $warnings ) {
    foreach ( $warnings as $warning ) {
        printf( "warning: %s — %s\n", $name, $warning );
    }
}

if ( $failed === [] ) {
    printf(
        "check-changelog-snippets OK — %d snippet(s) checked, %d warning(s).\n",
        $checked,
        count( $warned )
    );
    exit( 0 );
}

echo "check-changelog-snippets FAILED:\n\n";
foreach ( $failed as $name => $errors ) {
    foreach ( $errors as $error ) {
        printf( "  %s\n    %s\n", $name, $error );
    }
}

echo "\nThe expected shape (see changelog.d/README.md):\n\n";
echo "  # Short title of the change (#1234)\n";
echo "\n";
echo "  Bump: patch\n";
echo "\n";
echo "  What changed and why, in the same voice as CHANGES.md entries.\n";
echo "\n";
echo "The heading is required and the \"Bump:\" line must come after it. A\n";
echo "snippet that starts with the marker is read as an entry titled\n";
echo "\"Bump: minor\" that then bumps the patch version — which is how four\n";
echo "such entries nearly shipped in v4.108.0.\n";

printf( "\n%d malformed snippet(s) of %d checked.\n", count( $failed ), $checked );

exit( 1 );
