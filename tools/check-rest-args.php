<?php
/**
 * check-rest-args.php (#3689)
 *
 * Every write route (POST, PUT, PATCH) declares its `args`: the fields it
 * takes. A route that declares none can not refuse a key it was not built
 * for, so a typo'd field answers 200 and writes nothing, and route discovery
 * shows a client nothing to send. `BaseController::checkBody()` enforces the
 * contract; this gate makes sure there is a declaration for it to enforce.
 *
 * A RATCHET, NOT A SWEEP
 *
 * Hundreds of write routes predate the rule. They are listed in
 * `tools/rest-args-baseline.php` and pass. The gate fails when:
 *
 *   - a write route without `args` is NOT in the baseline (a new one), or
 *   - a baseline line no longer violates (the route gained `args`, moved or
 *     was removed). Delete that line in the same PR, the way the PHPStan
 *     baseline works, so the count only goes down.
 *
 * `'args' => []` is allowed and means "takes no body". What the parser reads
 * and what it treats as unreadable is described in `tools/lib/rest-args.php`.
 *
 * Usage:  php tools/check-rest-args.php
 * Exit:   0 clean, 1 a new violation, a stale baseline line, or nothing
 *         parsed at all.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/lib/rest-args.php';

$root          = dirname( __DIR__ );
$src           = $root . '/src';
$baseline_file = 'tools/rest-args-baseline.php';

if ( ! is_dir( $src ) ) {
    fwrite( STDERR, "check-rest-args: cannot read src/\n" );
    exit( 1 );
}

$violations = [];
$endpoints  = 0;
$prefix     = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS )
);
foreach ( $it as $file ) {
    if ( ! $file->isFile() || strtolower( $file->getExtension() ) !== 'php' ) continue;
    $code = (string) @file_get_contents( $file->getPathname() );
    if ( strpos( $code, 'register_rest_route' ) === false ) continue;

    $relative = str_replace( '\\', '/', $file->getPathname() );
    if ( strpos( $relative, $prefix ) === 0 ) $relative = substr( $relative, strlen( $prefix ) );

    $endpoints += count( tt_rest_args_endpoints( $code ) );
    foreach ( tt_rest_args_violations( $code, $relative ) as $identity ) {
        $violations[ $identity ] = true;
    }
}

if ( $endpoints === 0 ) {
    fwrite( STDERR, "check-rest-args: parsed no register_rest_route() endpoints at all; the gate has gone blind. Fix it before merging.\n" );
    exit( 1 );
}

$baseline = is_file( $root . '/' . $baseline_file ) ? require $root . '/' . $baseline_file : [];
if ( ! is_array( $baseline ) ) {
    fwrite( STDERR, "check-rest-args: {$baseline_file} must return an array of strings.\n" );
    exit( 1 );
}
$baseline = array_fill_keys( array_map( 'strval', $baseline ), true );

$new   = array_keys( array_diff_key( $violations, $baseline ) );
$stale = array_keys( array_diff_key( $baseline, $violations ) );
sort( $new );
sort( $stale );

if ( $new === [] && $stale === [] ) {
    printf(
        "check-rest-args OK: %d endpoint(s) read, %d write route(s) without args, all in the baseline.\n",
        $endpoints,
        count( $violations )
    );
    exit( 0 );
}

echo "check-rest-args FAILED\n";

if ( $stale !== [] ) {
    echo "\n";
    printf( "%d line(s) in %s no longer match a write route without args.\n", count( $stale ), $baseline_file );
    echo "The route now declares `args` (or it moved, or it is gone), so the baseline\n";
    echo "shrinks with it. Delete each of these lines from {$baseline_file}\n";
    echo "in this PR, exactly as shown:\n\n";
    $lines = (array) @file( $root . '/' . $baseline_file, FILE_IGNORE_NEW_LINES );
    foreach ( $stale as $identity ) {
        $line   = tt_rest_args_baseline_line( $identity );
        $number = array_search( $line, $lines, true );
        printf( "  %s:%s\n%s\n", $baseline_file, $number === false ? '?' : (string) ( (int) $number + 1 ), $line );
    }
    if ( $new !== [] ) {
        echo "\n";
        echo "If you changed a route's path or methods rather than adding args, the route\n";
        echo "is listed again below under its new identity: give it args rather than\n";
        echo "moving its line.\n";
    }
}

if ( $new !== [] ) {
    echo "\n";
    printf( "%d write route(s) without args that are not in the baseline:\n\n", count( $new ) );
    foreach ( $new as $identity ) {
        echo "  ", $identity, "\n";
    }
    echo "\n";
    echo "Declare the fields each one takes in its endpoint array:\n\n";
    echo "    'args' => [\n";
    echo "        'name' => [ 'type' => 'string', 'required' => true, 'description' => '...' ],\n";
    echo "    ],\n\n";
    echo "or `'args' => []` when it takes no body. Then refuse what it does not take:\n\n";
    echo "    \$refused = BaseController::checkBody( \$request, self::createArgs() );\n";
    echo "    if ( \$refused !== null ) return \$refused;\n\n";
    echo "A route whose path or methods is marked `?` or quoted as code could not be\n";
    echo "read statically: use a string literal or a self:: string constant.\n";
    echo "The body contract is in docs/rest-api.md (Body contract).\n";
}

exit( 1 );
