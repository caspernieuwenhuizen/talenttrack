<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\Import\ImportBatchRegistry;
use TT\Modules\Import\ImportUndoService;

/**
 * #2959 — an undo must remove exactly one import and nothing else.
 *
 * The assertions that matter are the negative ones. An undo that removes
 * the right rows AND some wrong ones is far worse than one that removes
 * nothing: it deletes a club's hand-entered records with no warning and
 * no way back. So every test here checks the survivors, not just the
 * casualties.
 */
final class ImportUndoTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
    }

    private function makeTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => 1, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function teamExists( int $id ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_teams WHERE id = %d", $id
        ) ) > 0;
    }

    public function test_undo_removes_only_its_own_batch(): void {
        $mine    = $this->makeTeam( 'From my import' );
        $other   = $this->makeTeam( 'From another import' );
        $byhand  = $this->makeTeam( 'Typed in by hand' );
        $demo    = $this->makeTeam( 'Demo data' );

        ( new ImportBatchRegistry( 'batch-a', 'a.xlsx' ) )->tag( 'team', $mine );
        ( new ImportBatchRegistry( 'batch-b', 'b.xlsx' ) )->tag( 'team', $other );
        ( new DemoBatchRegistry( 'demo-batch' ) )->tag( 'team', $demo );

        $result = ( new ImportUndoService() )->undo( 'batch-a' );

        $this->assertTrue( $result['ok'] );
        $this->assertFalse( $this->teamExists( $mine ), 'the batch\'s own row survived' );
        $this->assertTrue( $this->teamExists( $other ),  'another import batch was deleted' );
        $this->assertTrue( $this->teamExists( $byhand ), 'a hand-entered record was deleted' );
        $this->assertTrue( $this->teamExists( $demo ),   'a demo record was deleted' );
    }

    public function test_undoing_twice_is_a_no_op(): void {
        $team = $this->makeTeam( 'Once' );
        ( new ImportBatchRegistry( 'batch-a', 'a.xlsx' ) )->tag( 'team', $team );

        $service = new ImportUndoService();
        $first   = $service->undo( 'batch-a' );
        $second  = $service->undo( 'batch-a' );

        $this->assertTrue( $first['ok'] );
        $this->assertTrue( $second['ok'], 'a second undo errored instead of doing nothing' );
        $this->assertSame( [], $second['deleted'] );
    }

    public function test_undo_clears_the_batch_tags(): void {
        global $wpdb;
        $team = $this->makeTeam( 'Tagged' );
        ( new ImportBatchRegistry( 'batch-a', 'a.xlsx' ) )->tag( 'team', $team );

        ( new ImportUndoService() )->undo( 'batch-a' );

        $this->assertSame(
            0,
            (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->p}tt_import_tags WHERE batch_key = %s", 'batch-a'
            ) )
        );
    }

    public function test_the_batch_row_survives_so_the_history_still_shows_it(): void {
        $team = $this->makeTeam( 'Historic' );
        $reg  = new ImportBatchRegistry( 'batch-a', 'a.xlsx' );
        $reg->tag( 'team', $team );
        $reg->recordCounts( [ 'teams' => 1 ] );

        ( new ImportUndoService() )->undo( 'batch-a' );

        $batches = ImportBatchRegistry::listBatches();
        $this->assertCount( 1, $batches, 'the history forgot the import happened' );
        $this->assertSame( [], $batches[0]['counts'], 'counts were not cleared after the undo' );
    }

    public function test_an_empty_batch_key_is_refused(): void {
        $result = ( new ImportUndoService() )->undo( '' );

        $this->assertFalse( $result['ok'] );
        $this->assertNotSame( '', $result['error'] );
    }

    public function test_describe_counts_reads_as_prose(): void {
        $this->assertSame(
            '3 teams, 1 player',
            ImportUndoService::describeCounts( [ 'teams' => 3, 'players' => 1 ] )
        );
        $this->assertSame( '', ImportUndoService::describeCounts( [ 'teams' => 0 ] ) );
    }
}
