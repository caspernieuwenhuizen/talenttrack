<?php
/**
 * check-message-audiences.php (#3389)
 *
 * Every opt-outable `MessageType` constant must declare who it is
 * addressed to, and must have a label on the preferences card.
 *
 * WHY A GATE RATHER THAN A SAFE DEFAULT
 *
 * There is already a safe default: `MessageType::audiences()` returns
 * every audience for a type it does not know, so an unmapped type shows
 * up for everyone. That is deliberate — the failure that matters is a
 * *missing* toggle for mail somebody is actually receiving, which leaves
 * them unable to refuse it. A spare row is untidy; a lost row is a person
 * who cannot stop mail about their child.
 *
 * But a default that silently absorbs every omission is also a default
 * that quietly undoes the feature one type at a time. #3389 exists
 * because fifteen rows were shown to everyone; a year of unmapped
 * additions would put it back there without anyone noticing. So the
 * runtime fails open and the build fails closed.
 *
 * THE SECOND CHECK IS THE ONE THAT CAUGHT SOMETHING
 *
 * `MessageType` declared 22 types and the card labelled 15. Two of the
 * seven were operational (correctly absent — they cannot be refused), and
 * five were opt-outable types with no toggle anywhere. Three of those
 * reached players. That is the exact failure the card's own docblock
 * warned about, sitting in the product unnoticed, and nothing would have
 * reported it. So the label coverage is checked here too.
 *
 * Usage:  php tools/check-message-audiences.php
 * Exit:   0 clean, 1 an unmapped or unlabelled opt-outable type.
 */

declare( strict_types = 1 );

$root = dirname( __DIR__ );

$typePath = $root . '/src/Modules/Comms/Domain/MessageType.php';
$viewPath = $root . '/src/Shared/Frontend/FrontendMySettingsView.php';

foreach ( [ $typePath, $viewPath ] as $path ) {
    if ( ! is_file( $path ) ) {
        fwrite( STDERR, "check-message-audiences: cannot read " . basename( $path ) . "\n" );
        exit( 1 );
    }
}

$typeSrc = (string) file_get_contents( $typePath );
$viewSrc = (string) file_get_contents( $viewPath );

/* ---- the declared types ---------------------------------------------- */

// Public constants only, matching MessageType::all()'s own rule: a message
// type is part of the class's contract, anything private is bookkeeping.
// #3382's private OPERATIONAL_BY_POLICY list is why that distinction is
// load-bearing rather than stylistic.
if ( ! preg_match_all(
    '/^\s*public\s+const\s+([A-Z0-9_]+)\s*=\s*\'([^\']+)\'\s*;/m',
    $typeSrc,
    $m,
    PREG_SET_ORDER
) ) {
    fwrite( STDERR, "check-message-audiences: parsed no constants at all — MessageType's shape has changed and this gate is blind\n" );
    exit( 1 );
}

$declared = [];
foreach ( $m as $set ) {
    $declared[ $set[1] ] = $set[2];
}

/* ---- which of them are operational ----------------------------------- */

// Two routes, both read from source rather than reimplemented: the
// `_OPERATIONAL` suffix convention, and #3382's explicit policy list for
// the one type whose stored value predates the convention.
$byPolicy = [];
if ( preg_match(
    '/private\s+const\s+OPERATIONAL_BY_POLICY\s*=\s*\[(.*?)\]\s*;/s',
    $typeSrc,
    $policyBlock
) ) {
    if ( preg_match_all( '/self::([A-Z0-9_]+)/', $policyBlock[1], $policyNames ) ) {
        $byPolicy = $policyNames[1];
    }
}

$optOutable = [];
foreach ( $declared as $name => $value ) {
    $operational = substr( $value, -12 ) === '_OPERATIONAL'
        || in_array( $name, $byPolicy, true );
    if ( ! $operational ) {
        $optOutable[ $name ] = $value;
    }
}

if ( $optOutable === [] ) {
    fwrite( STDERR, "check-message-audiences: every declared type reads as operational — the parse is wrong\n" );
    exit( 1 );
}

/* ---- what the audience map covers ------------------------------------ */

$mapped = [];
if ( preg_match( '/private\s+const\s+AUDIENCES\s*=\s*\[(.*?)^\s*\]\s*;/ms', $typeSrc, $mapBlock ) ) {
    if ( preg_match_all( '/self::([A-Z0-9_]+)\s*=>/', $mapBlock[1], $mapNames ) ) {
        $mapped = $mapNames[1];
    }
}

if ( $mapped === [] ) {
    fwrite( STDERR, "check-message-audiences: parsed no AUDIENCES entries — the map's shape has changed and this gate is blind\n" );
    exit( 1 );
}

/* ---- what the preferences card labels -------------------------------- */

$labelled = [];
if ( preg_match_all( '/MessageType::([A-Z0-9_]+)\s*=>\s*__\(/', $viewSrc, $labelNames ) ) {
    $labelled = $labelNames[1];
}

/* ---- report ----------------------------------------------------------- */

$unmapped   = [];
$unlabelled = [];
foreach ( $optOutable as $name => $value ) {
    if ( ! in_array( $name, $mapped, true ) )   $unmapped[]   = $name;
    if ( ! in_array( $name, $labelled, true ) ) $unlabelled[] = $name;
}

// An audience for a type nobody can refuse is a fact nothing reads, and
// listing one invites a later change to filter the always-sent block —
// which would be wrong, because those send regardless.
$strayOperational = [];
foreach ( $mapped as $name ) {
    if ( isset( $declared[ $name ] ) && ! isset( $optOutable[ $name ] ) ) {
        $strayOperational[] = $name;
    }
}

if ( ! $unmapped && ! $unlabelled && ! $strayOperational ) {
    printf(
        "check-message-audiences OK — %d declared types, %d opt-outable, all mapped and labelled.\n",
        count( $declared ),
        count( $optOutable )
    );
    exit( 0 );
}

echo "check-message-audiences FAILED\n\n";

if ( $unmapped ) {
    echo "Opt-outable type(s) with no audience entry:\n";
    foreach ( $unmapped as $name ) {
        printf( "  MessageType::%s\n", $name );
    }
    echo "\nAdd each to MessageType::AUDIENCES, naming who its send path\n";
    echo "actually reaches. Without an entry it falls back to every\n";
    echo "audience, which puts a staff row back on a player's screen —\n";
    echo "the thing #3389 removed.\n\n";
}

if ( $unlabelled ) {
    echo "Opt-outable type(s) with no toggle on the preferences card:\n";
    foreach ( $unlabelled as $name ) {
        printf( "  MessageType::%s\n", $name );
    }
    echo "\nAdd a label to FrontendMySettingsView::messageTypeLabels().\n";
    echo "A type somebody can be sent, and cannot refuse because no screen\n";
    echo "offers it, is the failure that card exists to prevent.\n\n";
}

if ( $strayOperational ) {
    echo "Operational type(s) listed in the audience map:\n";
    foreach ( $strayOperational as $name ) {
        printf( "  MessageType::%s\n", $name );
    }
    echo "\nRemove them. An operational message cannot be refused by anyone,\n";
    echo "so its audience is never read — and having one there invites a\n";
    echo "later change to filter the always-sent block, which must not be\n";
    echo "filtered.\n\n";
}

exit( 1 );
