<?php
/**
 * demo-coverage-selfcheck.php (#2462) — invariant gate for the demo-data
 * coverage manifest. Pure PHP, no WordPress or database needed:
 *
 *   php bin/demo-coverage-selfcheck.php
 *
 * Asserts:
 *   1. Every generated table declares entity_type, category and written_by,
 *      and names a category that exists.
 *   2. The derived delete order is dependency-safe — a type never appears
 *      after something it depends on. This is what keeps the wipe from
 *      orphaning child rows.
 *   3. Every generated entity type appears in at least one category cascade,
 *      so no generator can write rows the wipe cannot reach.
 *   4. Every cascade is ordered children-first.
 *   5. Dependent categories with a generator declare a `run_order`. All
 *      dependent generators share one seeded MT stream, so an undeclared
 *      order would silently break `(seed, preset)` reproducibility.
 *
 * Exits non-zero on the first failure (CI-friendly).
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../' );
}

// The manifest references generator classes via ::class, which does not
// require them to be loadable — but the interfaces they implement are
// resolved when the generator files load, so pull those in first.
require_once __DIR__ . '/../src/Modules/DemoData/Generators/GeneratorInterface.php';
require_once __DIR__ . '/../src/Modules/DemoData/Generators/DependentGeneratorInterface.php';
require_once __DIR__ . '/../src/Modules/DemoData/DemoCoverage.php';

use TT\Modules\DemoData\DemoCoverage;

$failures = 0;

function tt_check( string $label, bool $ok, string $detail = '' ): void {
    global $failures;
    if ( $ok ) {
        echo "PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}" . ( $detail !== '' ? " — {$detail}" : '' ) . "\n";
}

// --- 1. Generated entries are complete.

$generated  = DemoCoverage::generatedTables();
$categories = DemoCoverage::CATEGORIES;

$incomplete = [];
$bad_category = [];
foreach ( $generated as $table => $entry ) {
    foreach ( [ 'entity_type', 'category', 'written_by' ] as $key ) {
        if ( ! isset( $entry[ $key ] ) || (string) $entry[ $key ] === '' ) {
            $incomplete[] = "{$table}.{$key}";
        }
    }
    $cat = (string) ( $entry['category'] ?? '' );
    if ( $cat !== '' && ! isset( $categories[ $cat ] ) ) {
        $bad_category[] = "{$table} => {$cat}";
    }
}
tt_check( 'generated entries declare entity_type, category, written_by', $incomplete === [], implode( ', ', $incomplete ) );
tt_check( 'every declared category exists', $bad_category === [], implode( ', ', $bad_category ) );

// --- 2. Delete order is dependency-safe.

$order = DemoCoverage::deleteOrder();
$pos   = array_flip( $order );
$late  = [];
foreach ( $generated as $entry ) {
    $type = (string) $entry['entity_type'];
    foreach ( (array) ( $entry['depends_on'] ?? [] ) as $dep ) {
        if ( ! isset( $pos[ (string) $dep ] ) ) continue;
        if ( ( $pos[ $type ] ?? -1 ) > $pos[ (string) $dep ] ) {
            $late[] = "{$type} deleted after {$dep}";
        }
    }
}
tt_check( 'delete order is dependency-safe', $late === [], implode( '; ', $late ) );
tt_check( 'delete order covers every generated type', count( $order ) === count( DemoCoverage::tableMap() ), count( $order ) . ' vs ' . count( DemoCoverage::tableMap() ) );

// --- 3. No generated type is unreachable by the wipe.

$in_cascade = [];
foreach ( $categories as $cfg ) {
    foreach ( (array) ( $cfg['cascade'] ?? [] ) as $type ) {
        $in_cascade[ (string) $type ] = true;
    }
}
$unreachable = [];
foreach ( $generated as $table => $entry ) {
    $type = (string) $entry['entity_type'];
    if ( ! isset( $in_cascade[ $type ] ) ) {
        $unreachable[] = "{$type} ({$table})";
    }
}
tt_check( 'every generated type is in some cascade', $unreachable === [], implode( ', ', $unreachable ) );

// --- 4. Cascades are ordered children-first.

$depends_by_type = [];
foreach ( $generated as $entry ) {
    $depends_by_type[ (string) $entry['entity_type'] ] = array_map( 'strval', (array) ( $entry['depends_on'] ?? [] ) );
}
$misordered = [];
foreach ( $categories as $key => $cfg ) {
    $cascade = array_values( array_map( 'strval', (array) ( $cfg['cascade'] ?? [] ) ) );
    $index   = array_flip( $cascade );
    foreach ( $cascade as $type ) {
        foreach ( $depends_by_type[ $type ] ?? [] as $dep ) {
            if ( ! isset( $index[ $dep ] ) ) continue;
            if ( $index[ $type ] > $index[ $dep ] ) {
                $misordered[] = "{$key}: {$type} after {$dep}";
            }
        }
    }
}
tt_check( 'cascades are ordered children-first', $misordered === [], implode( '; ', $misordered ) );

// --- 5. Dependent generators declare a run order.

$missing_order = [];
foreach ( array_keys( DemoCoverage::dependentGenerators() ) as $category ) {
    if ( ! isset( $categories[ $category ]['run_order'] ) ) {
        $missing_order[] = $category;
    }
}
tt_check( 'dependent generators declare run_order', $missing_order === [], implode( ', ', $missing_order ) );

// A duplicate run_order makes the sequence depend on array order, which is
// the reproducibility hazard this check exists for.
$orders = [];
foreach ( array_keys( DemoCoverage::dependentGenerators() ) as $category ) {
    $o = (int) ( $categories[ $category ]['run_order'] ?? 0 );
    $orders[ $o ][] = $category;
}
$dupes = [];
foreach ( $orders as $o => $cats ) {
    if ( count( $cats ) > 1 ) $dupes[] = "{$o}: " . implode( ' + ', $cats );
}
tt_check( 'run_order values are unique', $dupes === [], implode( '; ', $dupes ) );

echo "\n" . ( $failures === 0
    ? "demo-coverage-selfcheck OK\n"
    : "demo-coverage-selfcheck FAILED ({$failures})\n" );

exit( $failures === 0 ? 0 : 1 );
