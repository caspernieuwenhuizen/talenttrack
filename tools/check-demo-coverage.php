<?php
/**
 * Demo-coverage gate (#2462, epic #2461).
 *
 * Every `tt_*` table created by a migration must appear in
 * `DemoCoverage::MANIFEST` — either owned by a demo generator or exempt
 * with a stated reason. Fails listing anything unaccounted for, so a new
 * table forces a generate-or-exempt decision instead of silently widening
 * the demo-data gap.
 *
 * Usage: php tools/check-demo-coverage.php
 */

$root = dirname( __DIR__ );

$manifest_file = $root . '/src/Modules/DemoData/DemoCoverage.php';
if ( ! is_file( $manifest_file ) ) {
    fwrite( STDERR, "check-demo-coverage: DemoCoverage.php not found at {$manifest_file}\n" );
    exit( 1 );
}

// --- 1. Tables the schema creates, minus the ones a migration renames away.
//        Both sources matter: the Activator builds a fresh install, the
//        migrations carry upgrade installs, and neither is a superset of
//        the other (tt_people and tt_team_people exist only in the
//        Activator; most post-0001 tables only in migrations).

$sources = glob( $root . '/database/migrations/*.php' );
sort( $sources );
$sources[] = $root . '/src/Core/Activator.php';

$created = [];
$removed = [];

foreach ( $sources as $file ) {
    if ( ! is_file( $file ) ) continue;
    $src = (string) file_get_contents( $file );

    // CREATE TABLE [IF NOT EXISTS] {$p}tt_foo (   /  `{$wpdb->prefix}tt_foo`
    if ( preg_match_all( '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\']?\{?\$?[a-zA-Z_>\-\[\]\'"]*\}?(tt_[a-z0-9_]+)/i', $src, $m ) ) {
        foreach ( $m[1] as $table ) {
            $created[ $table ] = true;
        }
    }

    // A rename retires the old name and introduces the new one. Both sides
    // are PHP variables at the call site, so read the pair off the
    // migration's own docblock arrow (`tt_a` → `tt_b`).
    if ( preg_match_all( '/(tt_[a-z0-9_]+)`?\s*(?:→|->|=>)\s*`?(tt_[a-z0-9_]+)/u', $src, $m ) ) {
        foreach ( $m[1] as $i => $old ) {
            $new = $m[2][ $i ];
            if ( $old === $new ) continue;
            $removed[ $old ] = true;
            $created[ $new ] = true;
        }
    }
}

$tables = array_values( array_diff( array_keys( $created ), array_keys( $removed ) ) );
sort( $tables );

// --- 2. Manifest keys. Parsed from source rather than loaded — the class
//        pulls in WordPress-only dependencies this CLI script can't boot.

$manifest_src = (string) file_get_contents( $manifest_file );

// Only the MANIFEST constant — CATEGORIES and TABLE_QUIRKS below it also
// mention table names and entity types, and would otherwise be read as
// coverage entries.
$start = strpos( $manifest_src, 'public const MANIFEST' );
$end   = strpos( $manifest_src, 'public const CATEGORIES' );
if ( $start === false || $end === false || $end < $start ) {
    fwrite( STDERR, "check-demo-coverage: could not locate the MANIFEST constant block.\n" );
    exit( 1 );
}
$manifest_block = substr( $manifest_src, $start, $end - $start );

$generated = [];
$planned   = [];
$exempt    = [];

// Entries are one of three shapes; a nested array never appears inside an
// entry, so a non-greedy match to the closing bracket is enough.
if ( preg_match_all( "/'(tt_[a-z0-9_]+)'\s*=>\s*\[(.*?)\],\s*\n/s", $manifest_block, $m ) ) {
    foreach ( $m[1] as $i => $table ) {
        $body = $m[2][ $i ];
        if ( strpos( $body, "'exempt'" ) !== false ) {
            $exempt[ $table ] = true;
        } elseif ( strpos( $body, "'planned'" ) !== false ) {
            $planned[ $table ] = true;
        } else {
            $generated[ $table ] = true;
        }
    }
}

$known = $generated + $planned + $exempt;

// --- 3. Diff both ways.

$missing = array_values( array_diff( $tables, array_keys( $known ) ) );
$stale   = array_values( array_diff( array_keys( $known ), $tables ) );

$fail = false;

if ( $missing ) {
    $fail = true;
    fwrite( STDERR, "\ncheck-demo-coverage FAILED — table(s) missing from DemoCoverage::MANIFEST:\n\n" );
    foreach ( $missing as $table ) {
        fwrite( STDERR, "  - {$table}\n" );
    }
    fwrite( STDERR, "\nAdd each one to src/Modules/DemoData/DemoCoverage.php in one of three states:\n\n" );
    fwrite( STDERR, "  '{$missing[0]}' => [ 'entity_type' => '…', 'category' => '…', 'written_by' => …::class, 'depends_on' => [ … ] ],\n" );
    fwrite( STDERR, "  '{$missing[0]}' => [ 'planned' => '#1234' ],\n" );
    fwrite( STDERR, "  '{$missing[0]}' => [ 'exempt' => 'Why this table holds no demo content.' ],\n\n" );
    fwrite( STDERR, "A generated entry must also appear in some CATEGORIES cascade, or its rows can never be wiped.\n\n" );
}

if ( $stale ) {
    $fail = true;
    fwrite( STDERR, "\ncheck-demo-coverage FAILED — manifest entries for table(s) no migration creates:\n\n" );
    foreach ( $stale as $table ) {
        fwrite( STDERR, "  - {$table}\n" );
    }
    fwrite( STDERR, "\nRemove them, or fix the table name.\n\n" );
}

// --- 4. Every generated entity type must be reachable by the wipe, or the
//        generator leaves rows no operator can remove.

$entity_types = [];
if ( preg_match_all( "/'(tt_[a-z0-9_]+)'\s*=>\s*\[(.*?)\],\s*\n/s", $manifest_block, $m ) ) {
    foreach ( $m[1] as $i => $table ) {
        if ( ! isset( $generated[ $table ] ) ) continue;
        if ( preg_match( "/'entity_type'\s*=>\s*'([a-z0-9_]+)'/", $m[2][ $i ], $t ) ) {
            $entity_types[ $t[1] ] = $table;
        } else {
            $fail = true;
            fwrite( STDERR, "check-demo-coverage FAILED — {$table} is generated but declares no entity_type.\n" );
        }
    }
}

$categories_block = substr( $manifest_src, (int) $end );
$in_cascade = [];
if ( preg_match_all( "/'cascade'\s*=>\s*\[(.*?)\]/s", $categories_block, $m ) ) {
    foreach ( $m[1] as $list ) {
        if ( preg_match_all( "/'([a-z0-9_]+)'/", $list, $t ) ) {
            foreach ( $t[1] as $type ) {
                $in_cascade[ $type ] = true;
            }
        }
    }
}

$unwipeable = array_diff( array_keys( $entity_types ), array_keys( $in_cascade ) );
if ( $unwipeable ) {
    $fail = true;
    fwrite( STDERR, "\ncheck-demo-coverage FAILED — generated entity type(s) in no CATEGORIES cascade:\n\n" );
    foreach ( $unwipeable as $type ) {
        fwrite( STDERR, "  - {$type} (table {$entity_types[ $type ]})\n" );
    }
    fwrite( STDERR, "\nRows of these types would survive every wipe. Add each type to the cascade of\n" );
    fwrite( STDERR, "the category that owns it in DemoCoverage::CATEGORIES.\n\n" );
}

if ( $fail ) {
    exit( 1 );
}

printf(
    "check-demo-coverage OK — %d tables: %d generated, %d planned, %d exempt.\n",
    count( $tables ),
    count( $generated ),
    count( $planned ),
    count( $exempt )
);
exit( 0 );
