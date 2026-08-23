<?php
/**
 * Proves check-dashboard-urls.php still detects what it claims to.
 *
 * A gate that has quietly stopped matching reports the same "OK" as a
 * clean tree, so the passing case alone is worthless. Each fixture below
 * is written to a scratch src/ tree and the real checker is run over it.
 *
 * usage: php bin/dashboard-url-selfcheck.php
 */

$root    = dirname( __DIR__ );
$checker = $root . '/tools/check-dashboard-urls.php';
$php     = PHP_BINARY;

$failures = 0;

/** Run the checker over a throwaway tree containing one file. */
function tt_run_case( string $label, string $body, bool $expect_failure ): bool {
    global $checker, $php;

    $dir = sys_get_temp_dir() . '/tt-dashurl-' . bin2hex( random_bytes( 6 ) );
    mkdir( $dir . '/src', 0777, true );
    mkdir( $dir . '/tools', 0777, true );
    copy( $checker, $dir . '/tools/check-dashboard-urls.php' );
    file_put_contents( $dir . '/src/Fixture.php', "<?php\n" . $body . "\n" );

    // proc_open, not exec: the cases that are *supposed* to fail print the
    // checker's report to stderr, and letting that through makes a passing
    // self-check look like a failing one in the CI log.
    $cmd  = escapeshellarg( $php ) . ' ' . escapeshellarg( $dir . '/tools/check-dashboard-urls.php' );
    $spec = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
    $proc = proc_open( $cmd, $spec, $pipes );

    $code = 1;
    if ( is_resource( $proc ) ) {
        stream_get_contents( $pipes[1] );
        stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $code = proc_close( $proc );
    }

    // Best-effort cleanup; a leftover temp dir must never fail the check.
    @unlink( $dir . '/src/Fixture.php' );
    @unlink( $dir . '/tools/check-dashboard-urls.php' );
    @rmdir( $dir . '/src' );
    @rmdir( $dir . '/tools' );
    @rmdir( $dir );

    $failed = $code !== 0;
    $ok     = $failed === $expect_failure;

    printf(
        "  %-58s %s\n",
        $label,
        $ok ? 'ok' : ( $expect_failure ? 'MISSED IT' : 'FALSE POSITIVE' )
    );

    return $ok;
}

echo "dashboard-url-selfcheck\n";

$cases = [
    // label, source, should the checker fail?
    [
        'bare home_url base is caught',
        '$u = add_query_arg( [ "tt_view" => "players", "id" => 1 ], home_url( "/" ) );',
        true,
    ],
    [
        'caught when the call spans several lines',
        "\$u = add_query_arg(\n    [ 'tt_view' => 'docs', 'topic' => 'x' ],\n    home_url( '/' )\n);",
        true,
    ],
    [
        'caught with the scalar key/value form',
        '$u = add_query_arg( "tt_view", "my-tasks", home_url( "/" ) );',
        true,
    ],
    [
        'RecordLink base passes',
        '$u = add_query_arg( [ "tt_view" => "players" ], RecordLink::dashboardUrl() );',
        false,
    ],
    [
        'home_url only as a fallback passes',
        '$u = add_query_arg( "tt_view", $view, $base ?: home_url( "/" ) );',
        false,
    ],
    [
        'home_url without tt_view is none of our business',
        '$u = add_query_arg( [ "page" => 2 ], home_url( "/" ) );',
        false,
    ],
    [
        'the escape hatch suppresses it',
        '$u = add_query_arg( [ "tt_view" => "x" ], home_url( "/" ) /* tt-dashboard-url-ok */ );',
        false,
    ],
];

foreach ( $cases as [ $label, $body, $expect ] ) {
    if ( ! tt_run_case( $label, $body, $expect ) ) $failures++;
}

if ( $failures > 0 ) {
    fwrite( STDERR, "\ndashboard-url-selfcheck FAILED — {$failures} case(s) wrong.\n" );
    exit( 1 );
}

echo "dashboard-url-selfcheck OK — " . count( $cases ) . " cases.\n";
