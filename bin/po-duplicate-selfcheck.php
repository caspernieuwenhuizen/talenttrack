<?php
/**
 * Proof that the duplicate gate still bites (#2765).
 *
 * A gate that passes is not evidence that it works — and this one has three
 * ways to stop working quietly:
 *
 *   1. It stops seeing duplicates that were rewrapped differently, which is
 *      exactly the shape a union merge produces.
 *   2. It starts reporting a `msgctxt` entry as a duplicate of its plain
 *      twin, at which point everyone learns to ignore it (21 false positives
 *      on `main` the day this was written).
 *   3. It starts counting obsolete `#~` blocks, which gettext treats as
 *      comments.
 *
 * Each is asserted below against a catalogue built for the purpose. Run with
 * `php bin/po-duplicate-selfcheck.php`.
 *
 * Exit: 0 the gate behaves, 1 it does not, 2 tooling error.
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$tool = $root . '/tools/check-po-duplicates.php';

if ( ! is_readable( $tool ) ) {
    fwrite( STDERR, "po-duplicate-selfcheck: tools/check-po-duplicates.php is missing\n" );
    exit( 2 );
}

$dir = $root . '/languages/.selfcheck';
if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
    fwrite( STDERR, "po-duplicate-selfcheck: cannot create {$dir}\n" );
    exit( 2 );
}

$header = <<<'PO'
msgid ""
msgstr ""
"Project-Id-Version: selfcheck\n"


PO;

$cases = [
    'a duplicate wrapped differently is caught' => [
        'expect' => 1,
        'body'   => <<<'PO'
msgid ""
"A long string that was "
"wrapped across lines."
msgstr "Een lange tekst."

msgid "A long string that was wrapped across lines."
msgstr "Een lange tekst."
PO,
    ],
    'a plain duplicate is caught' => [
        'expect' => 1,
        'body'   => <<<'PO'
msgid "Save"
msgstr "Opslaan"

msgid "Save"
msgstr "Opslaan"
PO,
    ],
    'a msgctxt twin is not a duplicate' => [
        'expect' => 0,
        'body'   => <<<'PO'
msgid "Save"
msgstr "Opslaan"

msgctxt "a verb"
msgid "Save"
msgstr "Bewaren"
PO,
    ],
    'an obsolete block is not a duplicate' => [
        'expect' => 0,
        'body'   => <<<'PO'
msgid "Save"
msgstr "Opslaan"

#~ msgid "Save"
#~ msgstr "Opslaan"
PO,
    ],
];

$failures = [];

foreach ( $cases as $name => $case ) {
    $relative = 'languages/.selfcheck/case.po';
    file_put_contents( $root . '/' . $relative, $header . $case['body'] . "\n" );

    // Discard the tool's own stderr: two cases here are SUPPOSED to fail,
    // and a green self-check that prints two alarming failure reports is a
    // self-check people learn to skim past.
    $devnull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

    $output = [];
    $status = 0;
    exec(
        escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tool )
            . ' --base= --file=' . escapeshellarg( $relative )
            . ' 2>' . $devnull,
        $output,
        $status
    );

    if ( $status !== $case['expect'] ) {
        $failures[] = sprintf(
            "  %s\n    expected exit %d, got %d\n    %s",
            $name,
            $case['expect'],
            $status,
            implode( "\n    ", $output )
        );
    }
}

@unlink( $root . '/languages/.selfcheck/case.po' );
@rmdir( $dir );

if ( $failures ) {
    fwrite( STDERR, "po-duplicate-selfcheck FAILED — the gate no longer behaves:\n\n" );
    fwrite( STDERR, implode( "\n\n", $failures ) . "\n" );
    exit( 1 );
}

printf( "po-duplicate-selfcheck OK — %d behaviours asserted.\n", count( $cases ) );
exit( 0 );
