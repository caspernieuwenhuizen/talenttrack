<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\TableExists;
use TT\Modules\PersonaDashboard\Frontend\PersonaLandingRenderer;
use TT\Modules\Authorization\PersonaResolver;

/**
 * #4478 — one per-request table check. `TableExists::has()` asks the
 * database once per table, and a head-of-development dashboard load asks
 * about each table at most once.
 */
final class TableExistsTest extends WP_UnitTestCase {

    /** @var list<string> */
    private $queries = [];

    public function set_up(): void {
        parent::set_up();
        TableExists::reset();
        $this->queries = [];
        add_filter( 'query', [ $this, 'capture' ] );
    }

    public function tear_down(): void {
        remove_filter( 'query', [ $this, 'capture' ] );
        TableExists::reset();
        parent::tear_down();
    }

    /** @param string $sql */
    public function capture( $sql ) {
        $this->queries[] = (string) $sql;
        return $sql;
    }

    public function test_it_answers_for_present_and_missing_tables(): void {
        $this->assertTrue( TableExists::has( 'tt_players' ) );
        $this->assertFalse( TableExists::has( 'tt_no_such_table_4478' ) );
    }

    public function test_it_asks_the_database_once_per_table(): void {
        TableExists::has( 'tt_players' );
        TableExists::has( 'tt_players' );
        TableExists::has( 'tt_no_such_table_4478' );
        TableExists::has( 'tt_no_such_table_4478' );

        $this->assertSame( 1, $this->showTablesFor( 'tt_players' ) );
        $this->assertSame( 1, $this->showTablesFor( 'tt_no_such_table_4478' ), 'a missing table is remembered too' );
    }

    public function test_reset_asks_again(): void {
        TableExists::has( 'tt_players' );
        TableExists::reset();
        TableExists::has( 'tt_players' );

        $this->assertSame( 2, $this->showTablesFor( 'tt_players' ) );
    }

    public function test_a_head_of_development_dashboard_asks_about_each_table_at_most_once(): void {
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        wp_set_current_user( $hod );
        $this->assertContains( 'head_of_development', PersonaResolver::personasFor( $hod ) );

        $this->queries = [];
        ob_start();
        PersonaLandingRenderer::render( $hod, home_url( '/' ) );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'data-tt-pd-persona="head_of_development"', $html, 'the HoD dashboard rendered' );
        $this->assertNotEmpty( $this->queries, 'the dashboard read the database' );

        $per_table = [];
        foreach ( $this->queries as $sql ) {
            if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/i", $sql, $m ) ) {
                $per_table[ $m[1] ] = ( $per_table[ $m[1] ] ?? 0 ) + 1;
            }
        }
        $repeated = array_filter( $per_table, static function ( int $n ): bool { return $n > 1; } );
        $this->assertSame( [], $repeated, 'no table is checked twice in one dashboard load' );
    }

    private function showTablesFor( string $bare_table ): int {
        global $wpdb;
        $needle = "SHOW TABLES LIKE '" . $wpdb->prefix . $bare_table . "'";
        $n      = 0;
        foreach ( $this->queries as $sql ) {
            if ( stripos( $sql, $needle ) !== false ) $n++;
        }
        return $n;
    }
}
