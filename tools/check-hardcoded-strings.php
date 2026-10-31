<?php
/**
 * The hard-coded user-facing string gate (#4509, part of #4508).
 *
 * Fails a PR that ADDS, on its added lines only:
 *
 *   - JS: a letter-bearing literal written to textContent / innerText /
 *     placeholder / title / ariaLabel, or passed to setAttribute() for
 *     aria-label / title / placeholder. Read the text from the script's
 *     localized i18n object (TT.i18n, filled server-side with __()).
 *   - PHP: echo / print / printf / esc_html() / esc_attr() of a literal
 *     that still has letters once markup is stripped. Wrap it in __() and
 *     friends.
 *   - A talenttrack msgid that is itself Dutch. Msgids are English; the
 *     Dutch goes in the PR's languages/pending fragment.
 *
 * Lines already on main are never judged, so a PR that touches a file with
 * old violations and adds none passes. A trailing tt-i18n-ok marker on the
 * line (`/* tt-i18n-ok *\/` in PHP, `// tt-i18n-ok` in JS) exempts it: for
 * data that only looks like copy, never for copy.
 *
 * Usage:
 *   php tools/check-hardcoded-strings.php                  # added lines vs origin/main
 *   php tools/check-hardcoded-strings.php --base=main
 *   php tools/check-hardcoded-strings.php --format=github  # + workflow annotations
 *   php tools/check-hardcoded-strings.php --report         # whole tree: the backlog
 *
 * Exit: 0 clean, 1 violations added (diff mode), 2 tooling error. --report
 * always exits 0: it lists, it does not gate.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/lib/hardcoded-strings.php';

$options = [ 'base' => 'origin/main', 'root' => dirname( __DIR__ ), 'format' => 'plain', 'report' => false ];
foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( $arg === '--report' ) {
        $options['report'] = true;
        continue;
    }
    if ( preg_match( '/^--([a-z-]+)=(.*)$/', $arg, $m ) === 1 && array_key_exists( $m[1], $options ) ) {
        $options[ $m[1] ] = $m[2];
        continue;
    }
    fwrite( STDERR, "check-hardcoded-strings: unrecognised argument {$arg}\n" );
    exit( 2 );
}

$root     = rtrim( str_replace( '\\', '/', (string) $options['root'] ), '/' );
$annotate = $options['format'] === 'github';

/** @param list<string> $args */
function tt_hs_git( string $root, array $args ): ?string {
    $command = 'git -C ' . escapeshellarg( $root );
    foreach ( $args as $arg ) {
        $command .= ' ' . escapeshellarg( $arg );
    }
    $output = [];
    $status = 0;
    exec( $command, $output, $status );
    return $status === 0 ? implode( "\n", $output ) : null;
}

/** @return array<int, true> */
function tt_hs_added_lines( string $root, string $merge_base, string $relative ): array {
    $diff = tt_hs_git( $root, [ 'diff', '--unified=0', $merge_base . '...HEAD', '--', $relative ] );
    $added = [];
    foreach ( preg_split( '/\R/', (string) $diff ) ?: [] as $line ) {
        if ( preg_match( '/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $m ) !== 1 ) continue;
        $start = (int) $m[1];
        $count = isset( $m[2] ) ? (int) $m[2] : 1;
        for ( $i = 0; $i < $count; $i++ ) {
            $added[ $start + $i ] = true;
        }
    }
    return $added;
}

// --- which files, which lines -------------------------------------------

if ( $options['report'] ) {
    $listing = tt_hs_git( $root, [ 'ls-files', '--', 'src', 'assets/js' ] );
    if ( $listing === null ) {
        fwrite( STDERR, "check-hardcoded-strings: cannot list files in {$root}.\n" );
        exit( 2 );
    }
    $merge_base = null;
} else {
    $base = trim( (string) $options['base'] );
    $merge_base = tt_hs_git( $root, [ 'merge-base', 'HEAD', $base ] );
    if ( $merge_base === null ) {
        fwrite( STDERR, "check-hardcoded-strings: cannot find a merge base with {$base}.\n" );
        exit( 2 );
    }
    $merge_base = trim( $merge_base );
    $listing = tt_hs_git( $root, [ 'diff', '--name-only', '--diff-filter=AM', $merge_base . '...HEAD', '--', 'src', 'assets/js' ] );
    if ( $listing === null ) {
        fwrite( STDERR, "check-hardcoded-strings: cannot diff against {$merge_base}.\n" );
        exit( 2 );
    }
}

$files = [];
foreach ( preg_split( '/\R/', $listing ) ?: [] as $line ) {
    $line = trim( $line );
    if ( $line !== '' && tt_hs_in_scope( $line ) ) $files[] = $line;
}

// --- judge ----------------------------------------------------------------

$total = 0;
$by_rule = [ 'js' => 0, 'php' => 0, 'dutch' => 0 ];
foreach ( $files as $relative ) {
    $path = $root . '/' . $relative;
    if ( ! is_readable( $path ) ) continue;

    $only = null;
    if ( $merge_base !== null ) {
        $only = tt_hs_added_lines( $root, $merge_base, $relative );
        if ( ! $only ) continue;
    }

    foreach ( tt_hs_file_violations( $relative, (string) file_get_contents( $path ), $only ) as $number => $messages ) {
        foreach ( $messages as $message ) {
            $total++;
            $rule = strpos( $message, 'msgid reads as Dutch' ) === 0 ? 'dutch'
                : ( substr( $relative, -3 ) === '.js' ? 'js' : 'php' );
            $by_rule[ $rule ]++;
            echo "{$relative}:{$number}: {$message}\n";
            if ( $annotate && ! $options['report'] ) {
                echo "::error file={$relative},line={$number}::" . str_replace( [ "\r", "\n" ], ' ', $message ) . "\n";
            }
        }
    }
}

if ( $options['report'] ) {
    echo "\ncheck-hardcoded-strings report — {$total} finding(s) in " . count( $files ) . " file(s): "
        . "{$by_rule['js']} js, {$by_rule['php']} php, {$by_rule['dutch']} Dutch msgid.\n";
    exit( 0 );
}

if ( $total > 0 ) {
    echo "\ncheck-hardcoded-strings: this PR adds {$total} hard-coded user-facing string(s).\n";
    echo "  JS: read the text from the script's localized i18n object (TT.i18n), filled server-side with __().\n";
    echo "  PHP: wrap the literal in __() / esc_html__() / _e() and friends.\n";
    echo "  Dutch msgid: msgids are English; put the Dutch in languages/pending/<issue>-<slug>.po.\n";
    echo "  Data, not copy? Add a trailing tt-i18n-ok marker on the line: /* tt-i18n-ok */ (PHP) or // tt-i18n-ok (JS).\n";
    exit( 1 );
}

echo "check-hardcoded-strings OK — no hard-coded user-facing strings added.\n";
exit( 0 );
