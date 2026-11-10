<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4986 — an evaluation category key is unique per club, not per install.
 *
 * Migration 0324 replaces `UNIQUE (category_key)` with
 * `UNIQUE (club_id, category_key)`. Every read was already club-scoped, so a
 * second academy on the install could not create a category whose key
 * another academy held.
 */
final class EvalCategoryKeyPerClubTest extends WP_UnitTestCase {

    // The migration test runs DDL, which commits the per-test transaction;
    // the sweep keeps its rows from reaching the next test.
    use CommitsAfterCascade;

    private const MIGRATION = '/database/migrations/0324_eval_category_key_per_club.php';

    private int $club_two = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        $this->club_two = (int) CurrentClub::id() + 1;
    }

    public function tear_down(): void {
        remove_all_filters( 'tt_current_club_id' );
        $this->cleanUpAndCommit();
        parent::tear_down();
    }

    public function test_two_clubs_each_hold_the_same_key_and_read_only_their_own(): void {
        $repo = new EvalCategoriesRepository();
        $one  = $repo->create( [ 'category_key' => 'kopspel_4986', 'label' => 'Kopspel', 'is_active' => 1 ] );
        $this->assertGreaterThan( 0, $one );

        $this->asClub( $this->club_two );
        $two = $repo->create( [ 'category_key' => 'kopspel_4986', 'label' => 'Heading', 'is_active' => 1 ] );
        $this->assertGreaterThan( 0, $two, 'the second club creates a category with the same key' );
        $this->assertNotSame( $one, $two );
        $mine = $repo->getByKey( 'kopspel_4986' );
        $this->assertNotNull( $mine );
        $this->assertSame( $two, (int) $mine->id, 'the second club reads its own' );
        $this->assertSame( 'Heading', (string) $mine->label );
        $this->assertNull( $repo->get( $one ), 'and not the first club\'s' );

        remove_all_filters( 'tt_current_club_id' );
        $theirs = $repo->getByKey( 'kopspel_4986' );
        $this->assertNotNull( $theirs );
        $this->assertSame( $one, (int) $theirs->id, 'the first club reads its own' );
        $this->assertNull( $repo->get( $two ) );

        global $wpdb;
        $wpdb->suppress_errors( true );
        $again = $repo->create( [ 'category_key' => 'kopspel_4986', 'label' => 'Again', 'is_active' => 1 ] );
        $wpdb->suppress_errors( false );
        $this->assertSame( 0, $again, 'within one club the key stays unique' );
    }

    public function test_the_migration_keeps_every_category_and_evaluation_and_a_second_run_changes_nothing(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_eval_categories';

        // The pre-0324 shape: the install-wide key, no per-club one.
        if ( $this->hasIndex( 'uniq_club_category_key' ) ) $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `uniq_club_category_key`" );
        if ( ! $this->hasIndex( 'uniq_category_key' ) ) $wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY `uniq_category_key` (`category_key`)" );
        $this->assertTrue( $this->hasIndex( 'uniq_category_key' ), 'precondition: the old shape is restored' );
        $this->assertFalse( $this->hasIndex( 'uniq_club_category_key' ), 'precondition: without the per-club key' );

        $repo = new EvalCategoriesRepository();
        $cat  = $repo->create( [ 'category_key' => 'passing_4986', 'label' => 'Passing', 'is_active' => 1 ] );
        $this->assertGreaterThan( 0, $cat );
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [ 'club_id' => (int) CurrentClub::id(), 'player_id' => 1, 'eval_date' => '2026-11-01', 'notes' => '' ] );
        $eval = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_eval_ratings", [ 'club_id' => (int) CurrentClub::id(), 'evaluation_id' => $eval, 'category_id' => $cat, 'rating' => 7 ] );

        $categories  = $this->rows( $table );
        $evaluations = $this->rows( $wpdb->prefix . 'tt_evaluations' );
        $ratings     = $this->rows( $wpdb->prefix . 'tt_eval_ratings' );

        $this->migration()->up();

        $this->assertTrue( $this->hasIndex( 'uniq_club_category_key' ), 'the per-club key is in place' );
        $this->assertFalse( $this->hasIndex( 'uniq_category_key' ), 'the install-wide key is gone' );
        $this->assertSame( [ 'club_id', 'category_key' ], $this->indexColumns( 'uniq_club_category_key' ) );
        $this->assertSame( $categories, $this->rows( $table ), 'every category is kept' );
        $this->assertSame( $evaluations, $this->rows( $wpdb->prefix . 'tt_evaluations' ), 'every evaluation is kept' );
        $this->assertSame( $ratings, $this->rows( $wpdb->prefix . 'tt_eval_ratings' ), 'and every rating' );

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        $this->migration()->up();
        $this->assertSame( $create, $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N ), 'a second run changes nothing' );
    }

    /**
     * The suite builds its schema from the migrations, so the Activator's
     * CREATE is run here explicitly: `ensureSchema()` runs on every
     * activation, and `dbDelta` adds any key the CREATE declares. With the
     * old CREATE it put the install-wide key back.
     */
    public function test_the_activator_schema_keeps_the_per_club_key_and_not_the_old_one(): void {
        $this->migration()->up();
        $this->assertTrue( $this->hasIndex( 'uniq_club_category_key' ) );

        $ensure = new \ReflectionMethod( \TT\Core\Activator::class, 'ensureSchema' );
        $ensure->setAccessible( true );
        global $wpdb;
        $wpdb->suppress_errors( true );
        ob_start();
        $ensure->invoke( null );
        ob_end_clean();
        $wpdb->suppress_errors( false );

        $this->assertTrue( $this->hasIndex( 'uniq_club_category_key' ), 'the per-club key stays' );
        $this->assertFalse( $this->hasIndex( 'uniq_category_key' ), 'and the install-wide key is not added back' );
    }

    public function test_duplicates_within_one_club_refuse_the_migration_and_change_nothing(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_eval_categories';
        $club  = (int) CurrentClub::id();

        // An install that lost the old key and holds a duplicate in one club.
        if ( $this->hasIndex( 'uniq_club_category_key' ) ) $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `uniq_club_category_key`" );
        if ( $this->hasIndex( 'uniq_category_key' ) ) $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `uniq_category_key`" );
        $ids = [];
        foreach ( [ 'Dup A', 'Dup B' ] as $label ) {
            $wpdb->insert( $table, [ 'club_id' => $club, 'category_key' => 'dup_4986', 'label' => $label, 'is_active' => 1 ] );
            $ids[] = (int) $wpdb->insert_id;
        }
        $before = $this->rows( $table );

        try {
            $this->migration()->up();
            $this->fail( 'duplicates within one club should refuse the migration' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( $club . ':dup_4986', $e->getMessage(), 'the refusal names the club:key pair' );
        } finally {
            $after_keys = [ $this->hasIndex( 'uniq_club_category_key' ), $this->hasIndex( 'uniq_category_key' ) ];
            $after_rows = $this->rows( $table );
            // Put the table back the way the suite expects it.
            $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id IN (%d, %d)", $ids[0], $ids[1] ) );
            $this->migration()->up();
        }

        $this->assertSame( [ false, false ], $after_keys, 'no key was added' );
        $this->assertSame( $before, $after_rows, 'and no row was changed' );
    }

    private function asClub( int $club ): void {
        add_filter( 'tt_current_club_id', static fn(): int => $club );
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }

    private function hasIndex( string $index ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
            $wpdb->prefix . 'tt_eval_categories', $index
        ) );
    }

    /** @return list<string> */
    private function indexColumns( string $index ): array {
        global $wpdb;
        return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX',
            $wpdb->prefix . 'tt_eval_categories', $index
        ) ) );
    }

    /** @return list<array<string,mixed>> */
    private function rows( string $table ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (array) $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY id", ARRAY_A );
    }
}
