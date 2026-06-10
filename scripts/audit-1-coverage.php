<?php
/**
 * audit-1-coverage.php (#1191) — CI guard for the #1143 / #1105 / #1106 /
 * #1147 / #1159 bug class.
 *
 * The 2026-06 Audit 1 (#1175) sketched three reverse-direction
 * assertions that mechanically check tile <-> seed coverage. The first
 * one ("every seeded entity has a consumer surface") has 51 orphans
 * today and only becomes useful after a separate widening, so it is
 * deferred. The two reverse assertions ship here as a standalone CI
 * guard that catches phantom-entity and cap-without-entity regressions
 * at PR time.
 *
 *   Assertion B — every tile entity exists in the seed
 *                  (no phantom entities like #1143's `scouting_visits`
 *                  or #1189's `exports`).
 *
 *   Assertion C — every tile cap maps through LegacyCapMapper to a
 *                  seeded entity (cap layer can't smuggle a phantom
 *                  entity in either).
 *
 * Implementation notes:
 *   - This script runs in plain CLI PHP with no WordPress bootstrap
 *     and no PHPUnit. The repo CI shape is PHPStan + Playwright; no
 *     WP_UnitTestCase infrastructure exists. Following the existing
 *     `bin/audit-tenancy-source.sh` pattern (static check, no WP) but
 *     in PHP because the inputs (seed array literals + tile
 *     registrations) are PHP data.
 *
 *   - The seed file (`config/authorization_seed.php`) declares
 *     entities inside a `$expand(persona, [ 'entity' => [...], ... ])`
 *     closure. We extract entity names by regex over the per-persona
 *     blocks — no PHP `require` needed (which would pull in the
 *     plugin's module autoloader).
 *
 *   - Tile registrations in `src/Shared/CoreSurfaceRegistration.php`
 *     are extracted with the same `'entity' => '...'` /
 *     `'cap' => '...'` / `'view_slug' => '...'` regex grep.
 *
 *   - LegacyCapMapper's MAPPING table is extracted from
 *     `src/Modules/Authorization/LegacyCapMapper.php` by regex on the
 *     `'cap_slug' => [ 'entity', 'activity' ]` lines inside MAPPING.
 *
 * Exit code 0 on green, non-zero with per-offender report on red.
 *
 * Usage:
 *   php scripts/audit-1-coverage.php
 *
 * Refs: #1191, #1175 (Audit 1), #1143, #1105, #1106, #1147, #1159, #1189.
 */

declare(strict_types=1);

// ---------------------------------------------------------------
// Locate inputs relative to repo root.
// ---------------------------------------------------------------
$repo_root = dirname( __DIR__ );

$seed_path    = $repo_root . '/config/authorization_seed.php';
$surface_path = $repo_root . '/src/Shared/CoreSurfaceRegistration.php';
$mapper_path  = $repo_root . '/src/Modules/Authorization/LegacyCapMapper.php';

foreach ( [ 'seed' => $seed_path, 'surface' => $surface_path, 'mapper' => $mapper_path ] as $label => $path ) {
    if ( ! is_file( $path ) ) {
        fwrite( STDERR, "audit-1-coverage: cannot find $label file at $path\n" );
        exit( 2 );
    }
}

// ---------------------------------------------------------------
// 1. Extract seeded entities from authorization_seed.php.
// ---------------------------------------------------------------
// The seed file's only entity-bearing rows match:
//   '<entity_name>' => [ '<activities>', '<scope>', $mod_<x> ],
// inside per-persona $expand([...]) blocks. The activities literal is
// always one of: r / c / d / rc / rd / cd / rcd. The scope literal is
// always one of: global / team / player / self. Anchoring on the
// activities + scope shape gives us a sharp regex that won't false-
// match constants or class-shorthand definitions.
$seed_src = file_get_contents( $seed_path );
if ( $seed_src === false ) {
    fwrite( STDERR, "audit-1-coverage: failed to read $seed_path\n" );
    exit( 2 );
}

$seed_entities    = [];
$seed_entity_set  = [];
$pattern_seed_row = "~'([a-z][a-z0-9_]*)'\\s*=>\\s*\\[\\s*'(r|c|d|rc|rd|cd|rcd)'\\s*,\\s*'(global|team|player|self)'\\s*,~m";
if ( preg_match_all( $pattern_seed_row, $seed_src, $matches, PREG_SET_ORDER ) === false ) {
    fwrite( STDERR, "audit-1-coverage: regex failure on seed file\n" );
    exit( 2 );
}
foreach ( $matches as $m ) {
    $entity                       = $m[1];
    $seed_entity_set[ $entity ]   = true;
}
$seed_entities = array_keys( $seed_entity_set );
sort( $seed_entities );

if ( count( $seed_entities ) === 0 ) {
    fwrite( STDERR, "audit-1-coverage: extracted zero entities from seed — regex out of sync?\n" );
    exit( 2 );
}

// ---------------------------------------------------------------
// 2. Extract tile registrations from CoreSurfaceRegistration.php.
// ---------------------------------------------------------------
// Each tile is a TileRegistry::register([ ... ]) block. Split the file
// on the literal call so each chunk holds a single tile array literal,
// then pluck `view_slug`, `entity`, `cap` from each chunk.
$surface_src = file_get_contents( $surface_path );
if ( $surface_src === false ) {
    fwrite( STDERR, "audit-1-coverage: failed to read $surface_path\n" );
    exit( 2 );
}

$tiles  = [];
$chunks = preg_split( '~TileRegistry::register\(\s*\[~', $surface_src );
if ( ! is_array( $chunks ) || count( $chunks ) < 2 ) {
    fwrite( STDERR, "audit-1-coverage: found no TileRegistry::register() calls — file shape changed?\n" );
    exit( 2 );
}
// First chunk is the prologue before the first call; skip it.
array_shift( $chunks );

foreach ( $chunks as $chunk ) {
    // Only look at the first array literal — clip at the closing `]);`.
    $close = strpos( $chunk, ']);' );
    if ( $close === false ) {
        continue;
    }
    $body = substr( $chunk, 0, $close );

    $view_slug = null;
    $entity    = null;
    $cap       = null;

    if ( preg_match( "~'view_slug'\\s*=>\\s*'([a-z0-9][a-z0-9_-]*)'~", $body, $m ) ) {
        $view_slug = $m[1];
    }
    if ( preg_match( "~'entity'\\s*=>\\s*'([a-z][a-z0-9_]*)'~", $body, $m ) ) {
        $entity = $m[1];
    }
    if ( preg_match( "~'cap'\\s*=>\\s*'([a-z][a-z0-9_]*)'~", $body, $m ) ) {
        $cap = $m[1];
    }

    if ( $view_slug === null && $entity === null && $cap === null ) {
        continue;
    }
    $tiles[] = [ 'view_slug' => $view_slug, 'entity' => $entity, 'cap' => $cap ];
}

if ( count( $tiles ) === 0 ) {
    fwrite( STDERR, "audit-1-coverage: extracted zero tiles from CoreSurfaceRegistration — file shape changed?\n" );
    exit( 2 );
}

// ---------------------------------------------------------------
// 3. Extract LegacyCapMapper MAPPING.
// ---------------------------------------------------------------
// Each MAPPING row is:
//   'tt_<cap>' => [ '<entity>', '<activity>' ],
// inside the MAPPING constant. Same one-line regex shape as the seed.
$mapper_src = file_get_contents( $mapper_path );
if ( $mapper_src === false ) {
    fwrite( STDERR, "audit-1-coverage: failed to read $mapper_path\n" );
    exit( 2 );
}

$cap_to_entity   = [];
$pattern_mapping = "~'(tt_[a-z][a-z0-9_]*)'\\s*=>\\s*\\[\\s*'([a-z][a-z0-9_]*)'\\s*,\\s*'(read|change|create_delete)'\\s*\\]~m";
if ( preg_match_all( $pattern_mapping, $mapper_src, $matches, PREG_SET_ORDER ) === false ) {
    fwrite( STDERR, "audit-1-coverage: regex failure on mapper file\n" );
    exit( 2 );
}
foreach ( $matches as $m ) {
    $cap_to_entity[ $m[1] ] = $m[2];
}

if ( count( $cap_to_entity ) === 0 ) {
    fwrite( STDERR, "audit-1-coverage: extracted zero MAPPING rows from LegacyCapMapper — file shape changed?\n" );
    exit( 2 );
}

// ---------------------------------------------------------------
// Assertion B — every tile entity exists in the seed.
// ---------------------------------------------------------------
$phantoms = [];
foreach ( $tiles as $tile ) {
    $entity = $tile['entity'];
    if ( $entity === null || $entity === '' ) {
        continue;
    }
    if ( ! isset( $seed_entity_set[ $entity ] ) ) {
        $phantoms[] = sprintf(
            '%s (view=%s)',
            $entity,
            (string) ( $tile['view_slug'] ?? '?' )
        );
    }
}

// ---------------------------------------------------------------
// Assertion C — every tile cap maps to a seeded entity.
// ---------------------------------------------------------------
$broken_caps = [];
foreach ( $tiles as $tile ) {
    $cap = $tile['cap'];
    if ( $cap === null || $cap === '' ) {
        continue;
    }
    // Caps absent from the mapper fall through to native WP cap eval
    // and are not the audit's concern — per the audit doc, the bug
    // class is "cap in mapper that points to a phantom entity".
    if ( ! isset( $cap_to_entity[ $cap ] ) ) {
        continue;
    }
    $entity = $cap_to_entity[ $cap ];
    if ( ! isset( $seed_entity_set[ $entity ] ) ) {
        $broken_caps[] = sprintf(
            '%s (cap=%s → entity=%s)',
            (string) ( $tile['view_slug'] ?? '?' ),
            $cap,
            $entity
        );
    }
}

// ---------------------------------------------------------------
// Report + exit.
// ---------------------------------------------------------------
$tile_count    = count( $tiles );
$entity_count  = count( $seed_entities );
$mapping_count = count( $cap_to_entity );

echo "TalentTrack — Audit 1 coverage guard (#1191)\n";
echo "============================================\n";
printf( "Seed entities          : %d\n", $entity_count );
printf( "Tiles registered       : %d\n", $tile_count );
printf( "Legacy cap mappings    : %d\n", $mapping_count );
echo "\n";

$fail = 0;

if ( $phantoms === [] ) {
    echo "[ ok ] B — every tile entity exists in the seed.\n";
} else {
    $fail = 1;
    echo "[FAIL] B — tiles declare phantom entities (entity not in seed):\n";
    foreach ( $phantoms as $p ) {
        echo "         - $p\n";
    }
    echo "\n";
    echo "       Fix: either align the tile's entity to an existing seed entity\n";
    echo "       (cheapest — see #1189 aligning 'exports' to 'reports'), or add\n";
    echo "       the entity to config/authorization_seed.php for the personas\n";
    echo "       that should see the tile.\n";
}

if ( $broken_caps === [] ) {
    echo "[ ok ] C — every tile cap maps to a seeded entity.\n";
} else {
    $fail = 1;
    echo "[FAIL] C — tile caps map to entities missing from seed:\n";
    foreach ( $broken_caps as $b ) {
        echo "         - $b\n";
    }
    echo "\n";
    echo "       Fix: align the LegacyCapMapper entry or add the entity to the\n";
    echo "       seed. The tile's cap resolves via the mapper to an entity that\n";
    echo "       no persona is seeded for, so MatrixGate denies every user.\n";
}

echo "\n";
if ( $fail === 0 ) {
    echo "PASS — no phantom entities, no cap-without-entity tiles.\n";
    exit( 0 );
}

echo "FAIL — Audit 1 coverage guard tripped.\n";
echo "Background: docs/audits/2026-06-audit-1-authorization-coverage.md\n";
exit( 1 );
