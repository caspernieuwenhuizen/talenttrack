<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Training\Repositories\TrainingPlansRepository;
use TT\Modules\Training\Repositories\TrainingPlanBlocksRepository;
use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;

/**
 * Training plan foundation (#2496).
 *
 * The tests that matter here are the integrity ones. Wave 7 computes
 * per-player training exposure from runs, so anything that lets a run's
 * record drift — a rewritten snapshot, a duplicate run on one activity, a
 * gap in the block ordering — corrupts a number a coach will later read on
 * a player's file and believe.
 */
final class TrainingPlanRepositoriesTest extends WP_UnitTestCase {

    private TrainingPlansRepository $plans;
    private TrainingPlanBlocksRepository $blocks;
    private TrainingPlanRunsRepository $runs;

    public function set_up(): void {
        parent::set_up();
        $this->plans  = new TrainingPlansRepository();
        $this->blocks = new TrainingPlanBlocksRepository();
        $this->runs   = new TrainingPlanRunsRepository();
    }

    private function makePlan( string $title = 'Opbouwen van achteruit' ): int {
        return $this->plans->create( [
            'title'     => $title,
            'team_id'   => 7,
            'theme_key' => 'build_up',
        ] );
    }

    /** @return list<int> block ids in order */
    private function makeBlocks( int $plan_id ): array {
        return [
            $this->blocks->append( $plan_id, [ 'block_type' => 'warmup', 'duration_minutes' => 12 ] ),
            $this->blocks->append( $plan_id, [ 'block_type' => 'main',   'duration_minutes' => 22 ] ),
            $this->blocks->append( $plan_id, [ 'block_type' => 'game',   'duration_minutes' => 20 ] ),
        ];
    }

    public function test_create_and_read_back(): void {
        $id = $this->makePlan();
        $this->assertGreaterThan( 0, $id );

        $plan = $this->plans->findById( $id );
        $this->assertNotNull( $plan );
        $this->assertSame( 'Opbouwen van achteruit', $plan->title );
        $this->assertSame( 1, (int) $plan->club_id, 'every row must carry the tenancy scaffold' );
        $this->assertNotEmpty( $plan->uuid, 'root entities carry a uuid per CLAUDE.md §4' );
        $this->assertSame( 'manual', $plan->source );
        $this->assertNull( $plan->archived_at );

        $this->assertNotNull( $this->plans->findByUuid( (string) $plan->uuid ) );
    }

    public function test_create_rejects_an_empty_title(): void {
        $this->assertSame( 0, $this->plans->create( [ 'title' => '   ' ] ) );
    }

    public function test_unknown_payload_keys_are_dropped(): void {
        $id = $this->plans->create( [
            'title'       => 'Payload guard',
            'club_id'     => 999,      // must not be writable by a caller
            'nonsense'    => 'x',
        ] );

        $plan = $this->plans->findById( $id );
        $this->assertNotNull( $plan, 'the row must still land in the current club' );
        $this->assertSame( 1, (int) $plan->club_id );
    }

    public function test_blocks_keep_a_dense_zero_based_order(): void {
        $plan_id = $this->makePlan();
        $this->makeBlocks( $plan_id );

        $ordered = $this->blocks->listForPlan( $plan_id );
        $this->assertCount( 3, $ordered );
        $this->assertSame( [ 0, 1, 2 ], array_map( static fn( $b ) => (int) $b->order_index, $ordered ) );
    }

    public function test_plan_duration_tracks_its_blocks(): void {
        $plan_id = $this->makePlan();
        $ids     = $this->makeBlocks( $plan_id );

        $this->assertSame( 54, (int) $this->plans->findById( $plan_id )->total_duration_minutes );

        $this->blocks->update( $ids[1], [ 'duration_minutes' => 30 ] );
        $this->assertSame( 62, (int) $this->plans->findById( $plan_id )->total_duration_minutes );

        $this->blocks->delete( $ids[2] );
        $this->assertSame( 42, (int) $this->plans->findById( $plan_id )->total_duration_minutes );
    }

    public function test_deleting_a_block_repacks_the_order(): void {
        $plan_id = $this->makePlan();
        $ids     = $this->makeBlocks( $plan_id );

        $this->blocks->delete( $ids[0] );

        $ordered = $this->blocks->listForPlan( $plan_id );
        $this->assertSame(
            [ 0, 1 ],
            array_map( static fn( $b ) => (int) $b->order_index, $ordered ),
            'a gap in order_index would let two blocks claim one slot on the next insert'
        );
    }

    public function test_move_reorders_without_tripping_the_unique_index(): void {
        $plan_id = $this->makePlan();
        $ids     = $this->makeBlocks( $plan_id );

        // Send the last block to the front — the case a naive single-pass
        // UPDATE would break on UNIQUE (plan_id, order_index).
        $this->assertTrue( $this->blocks->move( $ids[2], 0 ) );

        $ordered = $this->blocks->listForPlan( $plan_id );
        $this->assertSame( $ids[2], (int) $ordered[0]->id );
        $this->assertSame( $ids[0], (int) $ordered[1]->id );
        $this->assertSame( $ids[1], (int) $ordered[2]->id );
        $this->assertSame( [ 0, 1, 2 ], array_map( static fn( $b ) => (int) $b->order_index, $ordered ) );
    }

    public function test_move_clamps_out_of_range_targets(): void {
        $plan_id = $this->makePlan();
        $ids     = $this->makeBlocks( $plan_id );

        $this->assertTrue( $this->blocks->move( $ids[0], -5 ) );
        $this->assertTrue( $this->blocks->move( $ids[0], 99 ) );

        $ordered = $this->blocks->listForPlan( $plan_id );
        $this->assertSame( $ids[0], (int) $ordered[2]->id, 'clamped to the end' );
        $this->assertSame( [ 0, 1, 2 ], array_map( static fn( $b ) => (int) $b->order_index, $ordered ) );
    }

    public function test_replace_all_swaps_the_whole_set(): void {
        $plan_id = $this->makePlan();
        $this->makeBlocks( $plan_id );

        $this->assertTrue( $this->blocks->replaceAll( $plan_id, [
            [ 'block_type' => 'talk', 'duration_minutes' => 5 ],
            [ 'block_type' => 'main', 'duration_minutes' => 40 ],
        ] ) );

        $ordered = $this->blocks->listForPlan( $plan_id );
        $this->assertCount( 2, $ordered );
        $this->assertSame( 'talk', $ordered[0]->block_type );
        $this->assertSame( 45, (int) $this->plans->findById( $plan_id )->total_duration_minutes );
    }

    public function test_unknown_block_type_falls_back_rather_than_writing_garbage(): void {
        $plan_id = $this->makePlan();
        $id      = $this->blocks->append( $plan_id, [ 'block_type' => 'nonsense', 'duration_minutes' => 10 ] );

        $this->assertSame( 'main', $this->blocks->findById( $id )->block_type );
    }

    public function test_duplicate_copies_the_blocks(): void {
        $plan_id = $this->makePlan();
        $this->makeBlocks( $plan_id );

        $copy_id = $this->plans->duplicate( $plan_id );
        $this->assertGreaterThan( 0, $copy_id );
        $this->assertNotSame( $plan_id, $copy_id );

        $copy = $this->plans->findById( $copy_id );
        $this->assertSame( 'duplicated', $copy->source );
        $this->assertNotSame( $this->plans->findById( $plan_id )->uuid, $copy->uuid );
        $this->assertCount( 3, $this->blocks->listForPlan( $copy_id ) );
        $this->assertSame( 54, (int) $copy->total_duration_minutes );
    }

    public function test_duplicate_as_template_drops_the_team(): void {
        $plan_id = $this->makePlan();
        $this->makeBlocks( $plan_id );

        $copy_id = $this->plans->duplicate( $plan_id, 'Standaard MD-3', null, true );
        $copy    = $this->plans->findById( $copy_id );

        $this->assertSame( 1, (int) $copy->is_template );
        $this->assertNull( $copy->team_id, 'a club template belongs to no single team' );
        $this->assertSame( 'Standaard MD-3', $copy->title );
    }

    public function test_attach_creates_one_run_with_a_snapshot(): void {
        $plan_id = $this->makePlan();
        $this->makeBlocks( $plan_id );

        $run_id = $this->runs->attach( $plan_id, 4242, 7, '2026-08-19' );
        $this->assertGreaterThan( 0, $run_id );

        $run = $this->runs->findById( $run_id );
        $this->assertSame( 'planned', $run->status );
        $this->assertSame( '2026-08-19', $run->run_date );
        $this->assertNotEmpty( $run->blocks_snapshot_json );

        $snapshot = $this->runs->snapshot( $run_id );
        $this->assertSame( $plan_id, $snapshot['plan_id'] );
        $this->assertCount( 3, $snapshot['blocks'] );
        $this->assertSame( 12, $snapshot['blocks'][0]['duration_minutes'] );

        $this->assertCount( 3, $this->runs->listBlocks( $run_id ) );
    }

    public function test_attaching_twice_returns_the_same_run(): void {
        $plan_id = $this->makePlan();
        $this->makeBlocks( $plan_id );

        $first  = $this->runs->attach( $plan_id, 4243, 7, '2026-08-19' );
        $second = $this->runs->attach( $plan_id, 4243, 7, '2026-08-19' );

        $this->assertSame(
            $first,
            $second,
            'one run per activity — a double-tap must be idempotent, not a duplicate'
        );
    }

    public function test_editing_the_plan_never_rewrites_a_snapshot(): void {
        $plan_id = $this->makePlan();
        $ids     = $this->makeBlocks( $plan_id );

        $run_id = $this->runs->attach( $plan_id, 4244, 7, '2026-08-19' );
        $before = $this->runs->snapshot( $run_id );

        // Everything a coach might do to the plan afterwards.
        $this->plans->update( $plan_id, [ 'title' => 'Heel iets anders' ] );
        $this->blocks->update( $ids[0], [ 'duration_minutes' => 99 ] );
        $this->blocks->delete( $ids[1] );
        $this->blocks->append( $plan_id, [ 'block_type' => 'cooldown', 'duration_minutes' => 8 ] );
        $this->plans->archive( $plan_id );

        $after = $this->runs->snapshot( $run_id );

        $this->assertSame(
            $before,
            $after,
            'the snapshot is the only history a run has — D5 rests entirely on it being immutable'
        );
        $this->assertSame( 'Opbouwen van achteruit', $after['title'] );
        $this->assertCount( 3, $after['blocks'] );
        $this->assertSame( 12, $after['blocks'][0]['duration_minutes'] );
    }

    public function test_archiving_a_plan_leaves_its_runs_alone(): void {
        $plan_id = $this->makePlan();
        $this->makeBlocks( $plan_id );
        $run_id = $this->runs->attach( $plan_id, 4245, 7, '2026-08-19' );

        $this->plans->archive( $plan_id );

        $this->assertNotNull(
            $this->runs->findById( $run_id ),
            'a plan going away must not take a session that happened with it'
        );
        $this->assertCount( 1, $this->runs->listForPlan( $plan_id ) );
    }

    public function test_run_lifecycle_stamps_its_timestamps(): void {
        $plan_id = $this->makePlan();
        $this->makeBlocks( $plan_id );
        $run_id = $this->runs->attach( $plan_id, 4246, 7, '2026-08-19' );

        $this->assertTrue( $this->runs->setStatus( $run_id, 'running' ) );
        $started = $this->runs->findById( $run_id )->started_at;
        $this->assertNotEmpty( $started );

        $this->assertTrue( $this->runs->setStatus( $run_id, 'completed' ) );
        $run = $this->runs->findById( $run_id );
        $this->assertSame( 'completed', $run->status );
        $this->assertNotEmpty( $run->completed_at );
        $this->assertSame( $started, $run->started_at, 'started_at is stamped once, not on every transition' );

        $this->assertFalse( $this->runs->setStatus( $run_id, 'nonsense' ) );
    }

    public function test_run_block_records_what_actually_happened(): void {
        $plan_id = $this->makePlan();
        $this->makeBlocks( $plan_id );
        $run_id = $this->runs->attach( $plan_id, 4247, 7, '2026-08-19' );

        $run_blocks = $this->runs->listBlocks( $run_id );
        $this->assertTrue( $this->runs->updateBlock( (int) $run_blocks[0]->id, [
            'actual_duration_minutes' => 17,
            'notes'                   => 'Liep uit, groep was laat.',
        ] ) );
        $this->assertTrue( $this->runs->updateBlock( (int) $run_blocks[2]->id, [ 'was_skipped' => true ] ) );

        $after = $this->runs->listBlocks( $run_id );
        $this->assertSame( 17, (int) $after[0]->actual_duration_minutes );
        $this->assertSame( 'Liep uit, groep was laat.', $after[0]->notes );
        $this->assertSame( 1, (int) $after[2]->was_skipped );
        $this->assertSame( 12, (int) $after[0]->planned_duration_minutes, 'planned stays readable beside actual' );
    }

    public function test_derived_principles_follow_the_blocks_and_manual_pins_survive(): void {
        global $wpdb;

        // A library exercise tagged with two principles.
        $wpdb->insert( $wpdb->prefix . 'tt_exercises', [
            'uuid'             => wp_generate_uuid4(),
            'club_id'          => 1,
            'name'             => 'Opbouw 7v5',
            'duration_minutes' => 22,
            'visibility'       => 'club',
        ] );
        $exercise_id = (int) $wpdb->insert_id;

        foreach ( [ 501, 502 ] as $principle_id ) {
            $wpdb->insert( $wpdb->prefix . 'tt_exercise_principles', [
                'club_id'      => 1,
                'exercise_id'  => $exercise_id,
                'principle_id' => $principle_id,
            ] );
        }

        $plan_id  = $this->makePlan();
        $block_id = $this->blocks->append( $plan_id, [
            'block_type'       => 'main',
            'exercise_id'      => $exercise_id,
            'duration_minutes' => 22,
        ] );

        $this->assertSame( [ 501, 502 ], $this->plans->listPrincipleIds( $plan_id ) );

        // The coach pins a third by hand — an intent the blocks don't carry.
        $this->assertTrue( $this->plans->pinPrinciple( $plan_id, 777 ) );
        $this->assertSame( [ 501, 502, 777 ], $this->plans->listPrincipleIds( $plan_id ) );

        // Swapping the drill out clears the derived rows but must leave
        // the coach's own statement of intent standing.
        $this->blocks->delete( $block_id );
        $this->assertSame(
            [ 777 ],
            $this->plans->listPrincipleIds( $plan_id ),
            'a manual pin must survive a block swap'
        );

        $this->assertTrue( $this->plans->unpinPrinciple( $plan_id, 777 ) );
        $this->assertSame( [], $this->plans->listPrincipleIds( $plan_id ) );
    }

    public function test_list_filters_by_team_template_and_archive_state(): void {
        $team_plan   = $this->plans->create( [ 'title' => 'Team plan', 'team_id' => 11 ] );
        $club_tmpl   = $this->plans->create( [ 'title' => 'Club template', 'team_id' => null, 'is_template' => 1 ] );
        $other_team  = $this->plans->create( [ 'title' => 'Other team', 'team_id' => 12 ] );
        $archived_id = $this->plans->create( [ 'title' => 'Archived', 'team_id' => 11 ] );
        $this->plans->archive( $archived_id );

        $ids = array_map( static fn( $p ) => (int) $p->id, $this->plans->listPlans( [ 'team_id' => 11 ] ) );

        $this->assertContains( $team_plan, $ids );
        $this->assertContains( $club_tmpl, $ids, 'club-wide plans are available to every team' );
        $this->assertNotContains( $other_team, $ids );
        $this->assertNotContains( $archived_id, $ids, 'archived plans are hidden by default' );

        $with_archived = array_map( static fn( $p ) => (int) $p->id, $this->plans->listPlans( [
            'team_id'          => 11,
            'include_archived' => true,
        ] ) );
        $this->assertContains( $archived_id, $with_archived );

        $templates = array_map( static fn( $p ) => (int) $p->id, $this->plans->listPlans( [ 'is_template' => true ] ) );
        $this->assertContains( $club_tmpl, $templates );
        $this->assertNotContains( $team_plan, $templates );
    }

    public function test_restore_brings_a_plan_back(): void {
        $id = $this->makePlan();
        $this->plans->archive( $id );
        $this->assertNotNull( $this->plans->findById( $id )->archived_at );

        $this->assertTrue( $this->plans->restore( $id ) );
        $this->assertNull( $this->plans->findById( $id )->archived_at );
    }
}
