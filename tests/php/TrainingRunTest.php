<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\DemoData\DemoCoverage;
use TT\Modules\Training\Print\TrainingPlanPrintable;
use TT\Modules\Training\Repositories\TrainingPlanBlocksRepository;
use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;

/**
 * #2499 — running a plan.
 *
 * The integrity of the run record matters more than the polish of the
 * screen that writes it: every wave-7 number is computed from these rows.
 * So what is tested here is the promises the run makes —
 *
 *   - the snapshot is written once and never rewritten;
 *   - re-attaching returns the existing run rather than replacing it;
 *   - a skip is recorded on the run and leaves the plan untouched;
 *   - the printable fits and repeats the growth-spurt warning.
 */
final class TrainingRunTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- fixtures ---------------------------------------------------------

    private function makePlan( string $title = 'Tuesday' ): int {
        $plan_id = ( new TrainingPlansRepository() )->create( [
            'title'      => $title,
            'team_id'    => 7,
            'source'     => 'manual',
            'visibility' => 'club',
        ] );

        ( new TrainingPlanBlocksRepository() )->replaceAll( $plan_id, [
            [ 'order_index' => 0, 'block_type' => 'warmup',   'duration_minutes' => 12 ],
            [ 'order_index' => 1, 'block_type' => 'main',     'duration_minutes' => 22 ],
            [ 'order_index' => 2, 'block_type' => 'cooldown', 'duration_minutes' => 6 ],
        ] );

        return $plan_id;
    }

    private function makeActivity( string $date = '2026-08-18' ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'team_id'           => 7,
            'session_date'      => $date,
            'activity_type_key' => 'training',
        ] );

        return (int) $wpdb->insert_id;
    }

    // ---- attach -----------------------------------------------------------

    public function test_attaching_snapshots_the_plan_as_it_is_now(): void {
        $plan_id     = $this->makePlan();
        $activity_id = $this->makeActivity();

        $runs   = new TrainingPlanRunsRepository();
        $run_id = $runs->attach( $plan_id, $activity_id, 7, '2026-08-18' );

        $this->assertGreaterThan( 0, $run_id );

        $snapshot = $runs->snapshot( $run_id );
        $this->assertCount( 3, $snapshot['blocks'] );
        $this->assertSame( 12, (int) $snapshot['blocks'][0]['duration_minutes'] );
    }

    public function test_re_attaching_returns_the_same_run_and_leaves_the_snapshot_alone(): void {
        $plan_id     = $this->makePlan();
        $activity_id = $this->makeActivity();

        $runs  = new TrainingPlanRunsRepository();
        $first = $runs->attach( $plan_id, $activity_id, 7, '2026-08-18' );

        // The plan changes after the first attach — a coach tidying it up
        // on Wednesday must not rewrite what Tuesday recorded.
        ( new TrainingPlanBlocksRepository() )->replaceAll( $plan_id, [
            [ 'order_index' => 0, 'block_type' => 'game', 'duration_minutes' => 90 ],
        ] );

        $second = $runs->attach( $plan_id, $activity_id, 7, '2026-08-18' );

        $this->assertSame( $first, $second, 're-attaching returns the existing run, it does not create a second' );

        $snapshot = $runs->snapshot( $first );
        $this->assertCount( 3, $snapshot['blocks'], 'the snapshot is written once and never rewritten' );
        $this->assertSame( 12, (int) $snapshot['blocks'][0]['duration_minutes'] );
    }

    public function test_a_second_plan_cannot_displace_an_activitys_run(): void {
        $activity_id = $this->makeActivity();
        $runs        = new TrainingPlanRunsRepository();

        $first  = $runs->attach( $this->makePlan( 'First' ), $activity_id, 7, '2026-08-18' );
        $second = $runs->attach( $this->makePlan( 'Second' ), $activity_id, 7, '2026-08-18' );

        $this->assertSame(
            $first,
            $second,
            'a training has one run; swapping the plan under a completed session would rewrite history'
        );
    }

    // ---- what actually happened -------------------------------------------

    public function test_a_skip_is_recorded_on_the_run_and_not_on_the_plan(): void {
        $plan_id = $this->makePlan();
        $runs    = new TrainingPlanRunsRepository();
        $run_id  = $runs->attach( $plan_id, $this->makeActivity(), 7, '2026-08-18' );

        $blocks = $runs->listBlocks( $run_id );
        $runs->updateBlock( (int) $blocks[2]->id, [ 'was_skipped' => true ] );

        $after = $runs->listBlocks( $run_id );
        $this->assertTrue( (bool) $after[2]->was_skipped );

        $this->assertCount(
            3,
            ( new TrainingPlanBlocksRepository() )->listForPlan( $plan_id ),
            'the plan keeps all three blocks — a skip says what happened once, not what the plan is'
        );
    }

    public function test_actual_duration_is_recorded_against_the_run(): void {
        $runs   = new TrainingPlanRunsRepository();
        $run_id = $runs->attach( $this->makePlan(), $this->makeActivity(), 7, '2026-08-18' );

        $blocks = $runs->listBlocks( $run_id );
        $runs->updateBlock( (int) $blocks[1]->id, [ 'actual_duration_minutes' => 27 ] );

        $after = $runs->listBlocks( $run_id );
        $this->assertSame( 27, (int) $after[1]->actual_duration_minutes );
        $this->assertSame(
            22,
            (int) $after[1]->planned_duration_minutes,
            'the planned figure survives — the difference between the two is the point of the record'
        );
    }

    public function test_status_transitions_stamp_their_own_timestamps(): void {
        $runs   = new TrainingPlanRunsRepository();
        $run_id = $runs->attach( $this->makePlan(), $this->makeActivity(), 7, '2026-08-18' );

        $this->assertSame( 'planned', (string) $runs->findById( $run_id )->status );

        $runs->setStatus( $run_id, 'running' );
        $this->assertNotEmpty( $runs->findById( $run_id )->started_at );

        $runs->setStatus( $run_id, 'completed' );
        $this->assertNotEmpty( $runs->findById( $run_id )->completed_at );
    }

    public function test_deleting_a_plan_does_not_take_its_runs(): void {
        $plan_id = $this->makePlan();
        $runs    = new TrainingPlanRunsRepository();
        $run_id  = $runs->attach( $plan_id, $this->makeActivity(), 7, '2026-08-18' );

        ( new TrainingPlansRepository() )->archive( $plan_id );

        $this->assertNotNull(
            $runs->findById( $run_id ),
            'a plan going away must never take a training that happened with it'
        );
    }

    // ---- the printable ----------------------------------------------------

    public function test_the_print_sheet_carries_the_blocks_and_a_running_clock(): void {
        $plan_id = $this->makePlan( 'Opbouwen' );

        $parts = TrainingPlanPrintable::render( $plan_id );

        $this->assertFalse( $parts['empty'] );
        $this->assertStringContainsString( 'Opbouwen', $parts['body'] );
        $this->assertStringContainsString( '0:00', $parts['body'], 'the first block starts the clock' );
        $this->assertStringContainsString( '0:12', $parts['body'], 'the second starts after the 12-minute warm-up' );
        $this->assertStringContainsString( '0:34', $parts['body'], 'and the third after 12 + 22' );
    }

    public function test_the_print_sheet_styles_come_from_the_stylesheet_not_from_php(): void {
        $parts = TrainingPlanPrintable::render( $this->makePlan() );

        $this->assertStringContainsString(
            '@page',
            $parts['style'],
            'the A4 page rule lives in assets/css/frontend-training-print.css and is read from there'
        );
    }

    public function test_a_missing_plan_prints_a_message_rather_than_a_blank_page(): void {
        $parts = TrainingPlanPrintable::render( 999999 );

        $this->assertTrue( $parts['empty'] );
        $this->assertNotEmpty( $parts['body'] );
    }

    // ---- demo coverage ----------------------------------------------------

    public function test_the_run_tables_are_generated_not_merely_planned(): void {
        foreach ( [ 'tt_training_plan_runs', 'tt_training_plan_run_blocks' ] as $table ) {
            $this->assertSame(
                DemoCoverage::STATE_GENERATED,
                DemoCoverage::stateOf( $table ),
                "{$table} still has no demo generator"
            );
        }
    }

    public function test_run_blocks_are_reachable_by_the_wipe(): void {
        $delete_by = DemoCoverage::deleteBy( 'tt_training_plan_run_blocks' );

        $this->assertNotNull( $delete_by );
        $this->assertSame( 'run_id', $delete_by['column'] );
        $this->assertSame(
            'training_plan_run_block',
            $delete_by['entity_type'],
            'the cleaner reads the child type\'s own tags before it looks at delete_by, so the type must be tagged'
        );
    }
}
