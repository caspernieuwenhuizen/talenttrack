<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DemoData\DemoCoverage;
use TT\Modules\DemoData\DemoDataCleaner;
use TT\Modules\DemoData\DemoGenerator;
use TT\Modules\DemoData\DemoRunPlan;
use TT\Modules\DemoData\DemoRunState;

/**
 * #3041 — generation stopped being one request.
 *
 * The reproducibility contract is what these pin: the run's shape is the
 * manifest's `run_order`, the cursor survives a request, and a run advanced
 * one step at a time reaches the same place as one done in a single pass.
 */
final class DemoRunChunkingTest extends WP_UnitTestCase {

    public function tear_down(): void {
        DemoRunState::clear();
        parent::tear_down();
    }

    /* ---- the plan ---------------------------------------------------- */

    public function test_a_procedural_plan_runs_master_data_before_its_dependents(): void {
        $steps = DemoRunPlan::build( [
            'source'               => 'procedural',
            'gen_people'           => true,
            'gen_flags'            => [],
            'excel_present_sheets' => [],
        ] );

        $this->assertSame( DemoRunPlan::STEP_PEOPLE, $steps[0] );
        $this->assertLessThan(
            array_search( DemoRunPlan::STEP_PLAYERS, $steps, true ),
            array_search( DemoRunPlan::STEP_TEAMS, $steps, true ),
            'players are generated onto teams, so teams come first'
        );
        $this->assertSame( DemoRunPlan::STEP_JOURNEY, end( $steps ),
            'the tagging sweep can only run once everything that fires a hook has' );
    }

    public function test_the_dependent_steps_follow_the_manifests_run_order(): void {
        $steps = DemoRunPlan::build( [
            'source'               => 'procedural',
            'gen_people'           => true,
            'gen_flags'            => [],
            'excel_present_sheets' => [],
        ] );

        $planned = [];
        foreach ( $steps as $step ) {
            $category = DemoRunPlan::categoryOf( $step );
            if ( $category !== null ) $planned[] = $category;
        }

        $this->assertSame( array_keys( DemoCoverage::dependentGenerators() ), $planned,
            'run_order is a reproducibility contract, not a convenience' );
    }

    public function test_a_category_the_operator_switched_off_is_not_planned(): void {
        $categories = array_keys( DemoCoverage::dependentGenerators() );
        $this->assertNotEmpty( $categories );
        $off = $categories[0];

        $steps = DemoRunPlan::build( [
            'source'               => 'procedural',
            'gen_people'           => true,
            'gen_flags'            => [ $off => false ],
            'excel_present_sheets' => [],
        ] );

        $this->assertNotContains( DemoRunPlan::forCategory( $off ), $steps,
            'the progress the overlay shows is the work that will actually happen' );
    }

    public function test_only_the_password_and_workbook_steps_are_inline(): void {
        $this->assertTrue( DemoRunPlan::isInline( DemoRunPlan::STEP_PEOPLE ) );
        $this->assertTrue( DemoRunPlan::isInline( DemoRunPlan::STEP_EXCEL ) );
        $this->assertFalse( DemoRunPlan::isInline( DemoRunPlan::STEP_TEAMS ) );
        $this->assertFalse( DemoRunPlan::isInline( DemoRunPlan::STEP_JOURNEY ) );
    }

    /* ---- the cursor -------------------------------------------------- */

    public function test_the_cursor_survives_a_request(): void {
        $state = DemoRunState::create( 'batch-x', [ 'a', 'b', 'c' ], [] );
        $state->markDone( 'a' );
        $state->persist();

        $reloaded = DemoRunState::load();
        $this->assertNotNull( $reloaded );
        $this->assertSame( 'b', $reloaded->nextStep() );
        $this->assertSame( 1, $reloaded->progress()['completed'] );
        $this->assertSame( 3, $reloaded->progress()['total'] );
    }

    public function test_a_run_is_complete_only_when_every_step_is_done(): void {
        $state = DemoRunState::create( 'batch-y', [ 'a', 'b' ], [] );

        $state->markDone( 'a' );
        $this->assertFalse( $state->isFinished() );

        $state->markDone( 'b' );
        $this->assertTrue( $state->isFinished() );
        $this->assertNull( $state->nextStep() );
    }

    public function test_load_by_id_refuses_a_different_run(): void {
        $state = DemoRunState::create( 'batch-z', [ 'a' ], [] );
        $state->persist();

        $this->assertNotNull( DemoRunState::loadById( $state->runId() ) );
        $this->assertNull( DemoRunState::loadById( 'not-this-one' ) );
    }

    /* ---- the run itself ---------------------------------------------- */

    /**
     * The acceptance criterion: same seed, same preset, same dataset — one
     * request or thirty. Run the tiny preset both ways and compare the
     * per-category counts.
     */
    public function test_stepping_a_run_lands_where_a_single_pass_does(): void {
        $opts = [
            'preset'      => 'tiny',
            'seed'        => 424242,
            'domain'      => '',
            'password'    => '',
            'source'      => 'procedural',
            'gen_people'  => false,
            'gen_teams'   => true,
            'gen_players' => true,
        ];

        global $wpdb;

        // `MatchAnalysisGenerator` reads every activity in the club and leans
        // on the `uk_activity` unique key to reject an activity that already
        // has an analysis. That prints a wpdb error, which PHPUnit counts as
        // unexpected output and marks the whole test risky — so quieten the
        // driver for the two runs. Filed as its own follow-up; the generator
        // should skip those activities rather than let the insert fail.
        $show_errors = $wpdb->hide_errors();

        $single = DemoGenerator::run( $opts );
        DemoRunState::clear();

        // Several generators pick their subjects club-wide rather than from
        // the batch they are writing — match analyses read every activity in
        // the club, for one — so a second run into a database the first one
        // populated collides with its unique keys and quietly writes fewer
        // rows. Clear the first batch so the stepped run starts from the same
        // ground the single pass did; otherwise the two are not comparable.
        DemoDataCleaner::wipeData( null, (string) $single['batch_id'] );

        $state = DemoGenerator::begin( $opts );
        $steps = 0;
        while ( $state->nextStep() !== null && $steps < 200 ) {
            DemoGenerator::advance( $state );
            $steps++;
        }
        $chunked = DemoGenerator::result( $state );

        if ( $show_errors ) $wpdb->show_errors();

        $this->assertTrue( $state->isFinished() );
        $this->assertGreaterThan( 1, $steps, 'a run of one step is not chunked' );

        // Counts are per category and independent of the batch, so they are
        // the shape comparison that survives two runs writing two batches.
        $this->assertSame(
            array_keys( $single['counts'] ),
            array_keys( $chunked['counts'] )
        );
        foreach ( $single['counts'] as $category => $count ) {
            $this->assertSame( (int) $count, (int) $chunked['counts'][ $category ],
                "category {$category} differed between a single pass and a stepped run" );
        }
    }

    public function test_a_finished_run_leaves_no_state_for_the_page_to_offer_resuming(): void {
        $state = DemoRunState::create( 'batch-done', [ 'a' ], [] );
        $state->markDone( 'a' );
        $state->persist();

        DemoRunState::clear();

        $this->assertNull( DemoRunState::load() );
    }
}
