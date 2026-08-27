<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoDataCleaner;
use TT\Modules\Import\ImportBatchRegistry;

/**
 * #2956 — the guard the whole slice exists for.
 *
 * `DemoDataCleaner::wipeData( null, null )` resolves what to delete
 * straight out of `tt_demo_tags` with no batch filter. Before this slice
 * the Excel importer tagged everything it created into that table, so
 * importing a club's real squad and then running a routine "wipe demo
 * data" would have deleted the club's actual players — silently.
 *
 * Real imports now record into `tt_import_batches` / `tt_import_tags`,
 * which the demo cleaner has no knowledge of. This test asserts the
 * property that makes that safe, and it asserts it against the
 * all-batches wipe specifically, because that is the call that forgets.
 */
final class ImportBatchIsolationTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
    }

    private function makeTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id' => $this->club,
            'name'    => $name,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function teamExists( int $id ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_teams WHERE id = %d", $id
        ) ) > 0;
    }

    public function test_wiping_all_demo_data_leaves_real_imported_rows_alone(): void {
        // One team that came from a demo workbook…
        $demo_team = $this->makeTeam( 'Demo United' );
        ( new DemoBatchRegistry( 'demo-batch' ) )->tag( 'team', $demo_team, [ 'source' => 'excel' ] );

        // …and one that came from the club's real squad import.
        $real_team = $this->makeTeam( 'Ajax U17' );
        ( new ImportBatchRegistry( 'import-batch', 'squad.xlsx' ) )->tag( 'team', $real_team, [ 'source' => 'excel' ] );

        // The all-batches wipe — the call with no filter to forget.
        DemoDataCleaner::wipeData( null, null );

        $this->assertFalse( $this->teamExists( $demo_team ), 'demo team survived the wipe' );
        $this->assertTrue( $this->teamExists( $real_team ), 'REAL team was deleted by a demo wipe' );
    }

    public function test_real_import_writes_nothing_to_the_demo_tag_table(): void {
        global $wpdb;

        $team = $this->makeTeam( 'Feyenoord U15' );
        ( new ImportBatchRegistry( 'import-batch', 'squad.xlsx' ) )->tag( 'team', $team );

        $demo_rows = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->p}tt_demo_tags"
        );
        $this->assertSame( 0, $demo_rows, 'a real import leaked into tt_demo_tags' );

        $import_rows = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->p}tt_import_tags"
        );
        $this->assertSame( 1, $import_rows );
    }

    public function test_demo_import_still_records_the_way_it_always_did(): void {
        global $wpdb;

        $team = $this->makeTeam( 'Demo City' );
        ( new DemoBatchRegistry( 'demo-batch' ) )->tag( 'team', $team, [ 'source' => 'excel' ] );

        $this->assertSame(
            1,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_demo_tags" )
        );
        $this->assertSame(
            0,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_import_tags" )
        );
    }

    public function test_batch_row_is_created_lazily_and_carries_a_uuid(): void {
        global $wpdb;

        $registry = new ImportBatchRegistry( 'lazy-batch', 'squad.xlsx' );

        // Nothing tagged yet — no batch should exist.
        $this->assertSame(
            0,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_import_batches" ),
            'an empty import left a batch row behind'
        );

        $registry->tag( 'team', $this->makeTeam( 'PSV U16' ) );

        $row = $wpdb->get_row( "SELECT * FROM {$this->p}tt_import_batches LIMIT 1" );
        $this->assertNotNull( $row );
        $this->assertSame( 'lazy-batch', $row->batch_key );
        $this->assertSame( 'squad.xlsx', $row->source_filename );
        $this->assertSame( 1, (int) $row->club_id );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $row->uuid
        );
    }

    public function test_counts_are_recorded_on_the_batch(): void {
        $registry = new ImportBatchRegistry( 'counted-batch', 'squad.xlsx' );
        $registry->tag( 'team', $this->makeTeam( 'AZ U14' ) );
        $registry->recordCounts( [ 'teams' => 1, 'players' => 12 ] );

        $batches = ImportBatchRegistry::listBatches();
        $this->assertCount( 1, $batches );
        $this->assertSame( [ 'teams' => 1, 'players' => 12 ], $batches[0]['counts'] );
        $this->assertSame( 'squad.xlsx', $batches[0]['source_filename'] );
    }

    public function test_entity_ids_are_scoped_to_their_batch(): void {
        $a = new ImportBatchRegistry( 'batch-a', 'a.xlsx' );
        $b = new ImportBatchRegistry( 'batch-b', 'b.xlsx' );

        $team_a = $this->makeTeam( 'Batch A team' );
        $team_b = $this->makeTeam( 'Batch B team' );
        $a->tag( 'team', $team_a );
        $b->tag( 'team', $team_b );

        $this->assertSame( [ $team_a ], $a->entityIds( 'team' ) );
        $this->assertSame( [ $team_b ], $b->entityIds( 'team' ) );
        $this->assertCount( 2, ImportBatchRegistry::allEntityIds( 'team' ) );
    }
}
