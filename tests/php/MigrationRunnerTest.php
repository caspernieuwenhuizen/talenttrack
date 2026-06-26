<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Database\MigrationRunner;

/**
 * Tier 1 (#1388) — MigrationRunner failure surfacing + re-run semantics.
 *
 * The runner's contract (read by SchemaStatus + the admin notice): a failed
 * migration must surface as ok=false with an error, be recorded in
 * FAILURES_OPTION, and never be marked applied so it re-runs on the next
 * pass. A regression here silently strands a broken schema.
 */
final class MigrationRunnerTest extends WP_UnitTestCase {

    private function fixturesDir(): string {
        return __DIR__ . '/fixtures/migrations';
    }

    public function test_run_applies_good_and_surfaces_failed_migration(): void {
        delete_option( MigrationRunner::FAILURES_OPTION );

        $results = ( new MigrationRunner( $this->fixturesDir() ) )->run();

        $by_name = [];
        foreach ( $results as $r ) {
            $by_name[ $r['name'] ] = $r;
        }

        $this->assertArrayHasKey( '0001_tt_test_ok', $by_name );
        $this->assertTrue( $by_name['0001_tt_test_ok']['ok'], 'a clean migration must succeed' );

        $this->assertArrayHasKey( '0002_tt_test_broken', $by_name );
        $this->assertFalse( $by_name['0002_tt_test_broken']['ok'], 'a throwing migration must fail' );
        $this->assertNotSame( '', (string) $by_name['0002_tt_test_broken']['error'], 'failure must carry an error message' );

        $failures = get_option( MigrationRunner::FAILURES_OPTION );
        $this->assertIsArray( $failures, 'failures must be persisted for the admin notice' );
        $this->assertContains( '0002_tt_test_broken', array_column( $failures, 'name' ) );
    }

    public function test_failed_migration_is_not_marked_applied_and_reruns(): void {
        $runner = new MigrationRunner( $this->fixturesDir() );
        $runner->run(); // first pass applies the good one, fails the broken one

        $names = array_column( $runner->run(), 'name' ); // second pass

        $this->assertNotContains( '0001_tt_test_ok', $names, 'an applied migration must not re-run' );
        $this->assertContains( '0002_tt_test_broken', $names, 'a failed migration must re-run until it passes' );
    }

    public function test_missing_directory_returns_empty(): void {
        $this->assertSame( [], ( new MigrationRunner( '/no/such/dir' ) )->run() );
    }
}
