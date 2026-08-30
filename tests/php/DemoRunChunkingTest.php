<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DemoData\DemoCoverage;
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

        $single = DemoGenerator::run( $opts );
        DemoRunState::clear();

        // #3184 — nothing is wiped between the passes any more. The
        // generators that used to pick their subjects club-wide now pick
        // them from the batch they are writing, so the second run neither
        // sees the first run's rows nor depends on where `tt_activities`
        // happens to have reached. Wiping in between hid that: it made the
        // comparison pass for one particular starting state, which is not a
        // property a reproducibility contract should have.
        $state = DemoGenerator::begin( $opts );
        $steps = 0;
        while ( $state->nextStep() !== null && $steps < 200 ) {
            DemoGenerator::advance( $state );
            $steps++;
        }
        $chunked = DemoGenerator::result( $state );

        $this->assertTrue( $state->isFinished() );
        $this->assertGreaterThan( 1, $steps, 'a run of one step is not chunked' );

        // Counts are per category and independent of the batch, so they are
        // the shape comparison that survives two runs writing two batches.
        $this->assertSame(
            array_keys( $single['counts'] ),
            array_keys( $chunked['counts'] )
        );
        $this->assertSameShape(
            $single['counts'],
            $chunked['counts'],
            (string) $single['batch_id'],
            'a single pass and a stepped run'
        );
    }

    /**
     * #3184 — the production half of the same defect. Two identical runs
     * into one install must produce the same per-category counts.
     *
     * They did not: the generators read every matching row in the club, met
     * run one's, and wrote fewer rows the second time — silently since
     * #3102, with a duplicate-key error before it. An operator generating a
     * second demo academy onto a populated install got a thinner one and was
     * told otherwise.
     */
    public function test_a_second_run_into_the_same_install_writes_the_same_shape(): void {
        $opts = [
            'preset'      => 'tiny',
            'seed'        => 515151,
            'domain'      => '',
            'password'    => '',
            'source'      => 'procedural',
            'gen_people'  => false,
            'gen_teams'   => true,
            'gen_players' => true,
        ];

        $first = DemoGenerator::run( $opts );
        DemoRunState::clear();
        $second = DemoGenerator::run( $opts );
        DemoRunState::clear();

        $this->assertNotSame( $first['batch_id'], $second['batch_id'], 'two runs, two batches' );

        $this->assertSameShape(
            $first['counts'],
            $second['counts'],
            (string) $first['batch_id'],
            'a first and a second run into one install'
        );
    }

    /**
     * Two runs write the same per-category counts, with one stated
     * exception.
     *
     * `PlayerProfileGenerator` creates the club's three player custom-field
     * *definitions* on the run that finds none and reuses them afterwards —
     * a definition is club configuration, not per-batch data, and inventing
     * three more identical ones on every run would be the bug. So the later
     * run's `player_profile` count is legitimately lower by exactly the
     * number of definitions the earlier run had to create.
     *
     * Read from `tt_demo_tags` rather than written as `3`, so the day a
     * fourth field is added the test keeps telling the truth.
     *
     * @param array<string, int|string> $earlier
     * @param array<string, int|string> $later
     */
    private function assertSameShape( array $earlier, array $later, string $earlier_batch, string $what ): void {
        $definitions = $this->tagCount( $earlier_batch, 'custom_field' );

        foreach ( $earlier as $category => $count ) {
            $allowance = $category === 'player_profile' ? $definitions : 0;

            $this->assertSame(
                (int) $count - $allowance,
                (int) ( $later[ $category ] ?? -1 ),
                "category {$category} differed between {$what}"
            );
        }
    }

    private function tagCount( string $batch_id, string $entity_type ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_demo_tags
              WHERE batch_id = %s AND entity_type = %s",
            $batch_id,
            $entity_type
        ) );
    }

    public function test_a_finished_run_leaves_no_state_for_the_page_to_offer_resuming(): void {
        $state = DemoRunState::create( 'batch-done', [ 'a' ], [] );
        $state->markDone( 'a' );
        $state->persist();

        DemoRunState::clear();

        $this->assertNull( DemoRunState::load() );
    }
}
