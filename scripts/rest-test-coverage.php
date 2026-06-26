<?php
/**
 * rest-test-coverage.php (#1388) — CI guard enforcing the endpoint-test
 * mandate (docs/contributing.md § "Mandatory: a smoke test for every new
 * REST endpoint").
 *
 * The Tier 2 REST smoke suite (tests/php/RestSmokeTest.php) only freezes
 * the ~20 routes it names. Nothing stops the NEXT controller from shipping
 * a `register_rest_route(...)` with no smoke test — re-opening the
 * authorization-coverage bug class one new endpoint at a time. This guard
 * closes that: a PR that ADDS a `register_rest_route(` line under `src/`
 * MUST, in the same diff, add or modify a file under `tests/php/`.
 *
 * Design — diff-based, forward-only:
 *   - Grandfathers the existing ~167 routes. Only diff-ADDED route
 *     registrations are in scope; the backlog is untouched.
 *   - Coarse-but-robust: the requirement is "this PR touches the PHP test
 *     dir", NOT a brittle route-name → test-name match. A new controller +
 *     a new/edited test file anywhere under tests/php/ satisfies it. The
 *     reviewer confirms the test actually covers the route; the gate just
 *     guarantees one was written.
 *   - Override: the workflow skips the failure when the PR carries the
 *     `rest-test-exempt` label (mirrors the i18n gate's
 *     `i18n-drift-acceptable`). The label check lives in the workflow; this
 *     script is pure diff logic.
 *
 * Usage (CI):
 *   php scripts/rest-test-coverage.php <merge-base-ref>
 *   php scripts/rest-test-coverage.php origin/main
 *
 * If no ref is supplied it defaults to `origin/main`. Exit 0 when the diff
 * adds no new routes OR adds new routes AND touches tests/php/. Exit 1 with
 * a report naming the offending controller(s) when new routes land without
 * a test-dir change. Exit 2 on a tooling error (git unavailable, etc.).
 *
 * Refs: #1388.
 */

declare(strict_types=1);

$base_ref = $argv[1] ?? 'origin/main';

// ---------------------------------------------------------------
// Resolve the merge-base so we diff only what this branch added,
// not everything that happened on main since it forked.
// ---------------------------------------------------------------
$merge_base = git_capture( [ 'merge-base', $base_ref, 'HEAD' ] );
if ( $merge_base === null || $merge_base === '' ) {
    // Fall back to a two-dot diff against the ref directly. Still
    // forward-only enough for the guard; CI always has the ref fetched.
    $diff_range = $base_ref;
    fwrite( STDERR, "rest-test-coverage: could not compute merge-base with {$base_ref}; diffing against the ref directly.\n" );
} else {
    $diff_range = trim( $merge_base );
}

// ---------------------------------------------------------------
// 1. Unified diff of src/ — we need the ADDED lines to count new
//    register_rest_route( calls. `--diff-filter` is NOT used here:
//    a route can be added inside an otherwise-modified file.
// ---------------------------------------------------------------
$src_diff = git_capture( [ 'diff', '--unified=0', $diff_range . '...HEAD', '--', 'src/' ] );
if ( $src_diff === null ) {
    fwrite( STDERR, "rest-test-coverage: git diff failed for src/.\n" );
    exit( 2 );
}

$added_routes_by_file = [];
$current_file         = null;

foreach ( preg_split( "/\r\n|\n|\r/", $src_diff ) as $line ) {
    // Track which file the following hunk lines belong to.
    if ( strpos( $line, '+++ b/' ) === 0 ) {
        $current_file = substr( $line, strlen( '+++ b/' ) );
        continue;
    }
    // An ADDED line (single leading '+', not the '+++' header) that
    // registers a route. Match the call token anywhere on the added line
    // so indentation / wrapping doesn't matter.
    if ( $current_file !== null
        && isset( $line[0] ) && $line[0] === '+'
        && strpos( $line, '+++' ) !== 0
        && strpos( $line, 'register_rest_route(' ) !== false ) {
        $added_routes_by_file[ $current_file ] = ( $added_routes_by_file[ $current_file ] ?? 0 ) + 1;
    }
}

$new_route_count = array_sum( $added_routes_by_file );

// ---------------------------------------------------------------
// 2. Did the same diff add or modify any file under tests/php/?
// ---------------------------------------------------------------
$test_changes = git_capture( [ 'diff', '--name-only', $diff_range . '...HEAD', '--', 'tests/php/' ] );
if ( $test_changes === null ) {
    fwrite( STDERR, "rest-test-coverage: git diff failed for tests/php/.\n" );
    exit( 2 );
}
$touched_tests = array_values( array_filter( array_map( 'trim', preg_split( "/\r\n|\n|\r/", $test_changes ) ) ) );

// ---------------------------------------------------------------
// Report + exit.
// ---------------------------------------------------------------
echo "TalentTrack — REST endpoint-test mandate guard (#1388)\n";
echo "======================================================\n";
printf( "Diff range              : %s...HEAD\n", $diff_range );
printf( "New register_rest_route : %d (in %d file(s))\n", $new_route_count, count( $added_routes_by_file ) );
printf( "tests/php/ files touched: %d\n", count( $touched_tests ) );
echo "\n";

if ( $new_route_count === 0 ) {
    echo "[ ok ] No new REST routes added in this diff — nothing to enforce.\n";
    echo "\nPASS\n";
    exit( 0 );
}

if ( count( $touched_tests ) > 0 ) {
    echo "[ ok ] New routes are accompanied by a change under tests/php/:\n";
    foreach ( $touched_tests as $t ) {
        echo "         - $t\n";
    }
    echo "\nPASS\n";
    exit( 0 );
}

echo "[FAIL] New REST route(s) added with no accompanying tests/php/ change:\n";
foreach ( $added_routes_by_file as $file => $count ) {
    echo "         - $file (+{$count} register_rest_route)\n";
}
echo "\n";
echo "Every new `register_rest_route(...)` MUST ship with a smoke test in the\n";
echo "same PR — at minimum the denial path (an unauthenticated caller gets\n";
echo "401/403) and the happy path's status + envelope shape. See\n";
echo "docs/contributing.md § \"Mandatory: a smoke test for every new REST\n";
echo "endpoint\" and tests/php/RestSmokeTest.php for the pattern.\n";
echo "\n";
echo "If this PR genuinely needs no new test (e.g. a route moved verbatim\n";
echo "between files, or a trivial copy-only change), apply the\n";
echo "`rest-test-exempt` label to the PR.\n";
echo "\nFAIL\n";
exit( 1 );

/**
 * Run a git subcommand and return its stdout, or null on failure.
 *
 * @param string[] $args
 */
function git_capture( array $args ): ?string {
    $descriptors = [
        1 => [ 'pipe', 'w' ], // stdout
        2 => [ 'pipe', 'w' ], // stderr — captured so it doesn't pollute output
    ];
    $proc = proc_open( array_merge( [ 'git' ], $args ), $descriptors, $pipes );
    if ( ! is_resource( $proc ) ) {
        return null;
    }
    $stdout = stream_get_contents( $pipes[1] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );
    $exit_code = proc_close( $proc );
    if ( $exit_code !== 0 ) {
        return null;
    }
    return $stdout === false ? '' : $stdout;
}
