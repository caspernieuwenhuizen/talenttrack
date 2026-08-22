<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\DemoData\DemoCoverage;
use TT\Modules\Training\Repositories\TrainingObservationsRepository;
use TT\Modules\Training\Repositories\TrainingPlanBlocksRepository;
use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;
use TT\Modules\Training\Services\PlayerExposureAggregator;
use TT\Modules\Training\Services\PlayerExposureReader;

/**
 * #2500 — player training exposure, observations, coverage.
 *
 * The wave the epic exists for, so the tests are about the promises
 * rather than the markup:
 *
 *   - the rebuild is idempotent, **including where no season is set**;
 *   - a plan edited after a run does not change what that run recorded;
 *   - a principle never trained is returned, not omitted;
 *   - an observation with no rating saves; one with neither does not;
 *   - a rating outside the configured scale is refused, not clamped.
 */
final class TrainingExposureTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function planner(): int {
        $id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $id );
        return $id;
    }

    // ---- fixtures ---------------------------------------------------------

    private function makePlayer( string $first = 'Sem', string $last = 'Bakker' ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'team_id'    => 7,
            'first_name' => $first,
            'last_name'  => $last,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function makePrinciple( string $code ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_principles', [
            'club_id'    => 1,
            'code'       => $code,
            'title_json' => wp_json_encode( [ 'en_US' => 'Principle ' . $code ] ),
        ] );

        return (int) $wpdb->insert_id;
    }

    private function makeExercise( string $name, array $principle_ids ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_exercises', [
            'club_id'          => 1,
            'uuid'             => wp_generate_uuid4(),
            'name'             => $name,
            'visibility'       => 'club',
            'duration_minutes' => 20,
        ] );
        $id = (int) $wpdb->insert_id;

        foreach ( $principle_ids as $principle_id ) {
            $wpdb->insert( $wpdb->prefix . 'tt_exercise_principles', [
                'club_id'      => 1,
                'exercise_id'  => $id,
                'principle_id' => $principle_id,
            ] );
        }

        return $id;
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

    private function markPresent( int $activity_id, int $player_id, string $status = 'present' ): void {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'     => 1,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'record_type' => 'actual',
        ] );
    }

    /** A completed run of a one-block plan on the given exercise. */
    private function makeCompletedRun( int $exercise_id, int $minutes, string $date = '2026-08-18' ): array {
        $plan_id = ( new TrainingPlansRepository() )->create( [
            'title'      => 'Plan ' . $date,
            'team_id'    => 7,
            'source'     => 'manual',
            'visibility' => 'club',
        ] );

        ( new TrainingPlanBlocksRepository() )->replaceAll( $plan_id, [
            [ 'order_index' => 0, 'block_type' => 'main', 'exercise_id' => $exercise_id, 'duration_minutes' => $minutes ],
        ] );

        $activity_id = $this->makeActivity( $date );

        $runs   = new TrainingPlanRunsRepository();
        $run_id = $runs->attach( $plan_id, $activity_id, 7, $date );
        $runs->setStatus( $run_id, 'completed' );

        return [ 'plan_id' => $plan_id, 'activity_id' => $activity_id, 'run_id' => $run_id ];
    }

    // ---- the aggregate ----------------------------------------------------

    public function test_a_present_player_accrues_the_blocks_minutes(): void {
        $principle = $this->makePrinciple( 'AO-01' );
        $exercise  = $this->makeExercise( 'Opbouw', [ $principle ] );
        $player    = $this->makePlayer();

        $run = $this->makeCompletedRun( $exercise, 22 );
        $this->markPresent( $run['activity_id'], $player );

        ( new PlayerExposureAggregator() )->rebuildAll();

        global $wpdb;
        $minutes = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT minutes_total FROM {$wpdb->prefix}tt_player_principle_exposure
              WHERE player_id = %d AND principle_id = %d",
            $player,
            $principle
        ) );

        $this->assertSame( 22, $minutes );
    }

    public function test_an_absent_player_accrues_nothing(): void {
        $principle = $this->makePrinciple( 'AO-02' );
        $exercise  = $this->makeExercise( 'Opbouw', [ $principle ] );
        $there     = $this->makePlayer( 'Aanwezig', 'Speler' );
        $not_there = $this->makePlayer( 'Afwezig', 'Speler' );

        $run = $this->makeCompletedRun( $exercise, 20 );
        $this->markPresent( $run['activity_id'], $there );
        $this->markPresent( $run['activity_id'], $not_there, 'absent' );

        ( new PlayerExposureAggregator() )->rebuildAll();

        global $wpdb;
        $rows = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_principle_exposure WHERE player_id = %d",
            $not_there
        ) );

        $this->assertSame( 0, $rows, 'a player who was not there was not taught anything' );
    }

    public function test_a_late_player_still_trained(): void {
        $principle = $this->makePrinciple( 'AO-03' );
        $exercise  = $this->makeExercise( 'Opbouw', [ $principle ] );
        $player    = $this->makePlayer();

        $run = $this->makeCompletedRun( $exercise, 15 );
        $this->markPresent( $run['activity_id'], $player, 'late' );

        ( new PlayerExposureAggregator() )->rebuildAll();

        global $wpdb;
        $this->assertSame( 15, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT minutes_total FROM {$wpdb->prefix}tt_player_principle_exposure WHERE player_id = %d",
            $player
        ) ) );
    }

    /**
     * The wave's load-bearing criterion, tested where it actually breaks.
     *
     * With no season configured every row carries season_id 0. Had the
     * column stayed nullable — as #2500's body specified — MySQL would
     * not treat two NULLs as equal in the UNIQUE key and each rebuild
     * would insert a duplicate. That failure is invisible on an install
     * WITH seasons, which is why this test deliberately has none.
     */
    public function test_the_rebuild_is_idempotent_with_no_season_configured(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_seasons" );

        $principle = $this->makePrinciple( 'AO-04' );
        $exercise  = $this->makeExercise( 'Opbouw', [ $principle ] );
        $player    = $this->makePlayer();

        $run = $this->makeCompletedRun( $exercise, 18 );
        $this->markPresent( $run['activity_id'], $player );

        $aggregator = new PlayerExposureAggregator();
        $aggregator->rebuildAll();
        $first = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_principle_exposure" );

        $aggregator->rebuildAll();
        $aggregator->rebuildAll();
        $third = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_principle_exposure" );

        $this->assertSame( $first, $third, 'three rebuilds must leave what one rebuild left' );

        $this->assertSame( 18, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT minutes_total FROM {$wpdb->prefix}tt_player_principle_exposure WHERE player_id = %d",
            $player
        ) ), 'and the total must not have accumulated' );
    }

    /**
     * The reason the aggregator reads the run's snapshot rather than the
     * live plan block. Editing a plan replaces its whole block set; a
     * join to those rows would drop the run and the player's minutes
     * would quietly fall.
     */
    public function test_editing_the_plan_does_not_change_what_a_run_recorded(): void {
        $principle = $this->makePrinciple( 'AO-05' );
        $exercise  = $this->makeExercise( 'Opbouw', [ $principle ] );
        $player    = $this->makePlayer();

        $run = $this->makeCompletedRun( $exercise, 25 );
        $this->markPresent( $run['activity_id'], $player );

        $aggregator = new PlayerExposureAggregator();
        $aggregator->rebuildAll();

        global $wpdb;
        $before = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT minutes_total FROM {$wpdb->prefix}tt_player_principle_exposure WHERE player_id = %d",
            $player
        ) );

        // The coach tidies the plan up afterwards — a save replaces the
        // whole set, so the block the run pointed at is gone.
        ( new TrainingPlanBlocksRepository() )->replaceAll( $run['plan_id'], [
            [ 'order_index' => 0, 'block_type' => 'talk', 'duration_minutes' => 5 ],
        ] );

        $aggregator->rebuildAll();

        $after = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT minutes_total FROM {$wpdb->prefix}tt_player_principle_exposure WHERE player_id = %d",
            $player
        ) );

        $this->assertSame( 25, $before );
        $this->assertSame( 25, $after, 'the run keeps what it recorded; the plan is a document, the run is history' );
    }

    public function test_a_skipped_block_contributes_nothing(): void {
        $principle = $this->makePrinciple( 'AO-06' );
        $exercise  = $this->makeExercise( 'Opbouw', [ $principle ] );
        $player    = $this->makePlayer();

        $run = $this->makeCompletedRun( $exercise, 20 );
        $this->markPresent( $run['activity_id'], $player );

        $runs   = new TrainingPlanRunsRepository();
        $blocks = $runs->listBlocks( $run['run_id'] );
        $runs->updateBlock( (int) $blocks[0]->id, [ 'was_skipped' => true ] );

        ( new PlayerExposureAggregator() )->rebuildAll();

        global $wpdb;
        $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_principle_exposure WHERE player_id = %d",
            $player
        ) ), 'recording a skip is the point of recording a skip' );
    }

    public function test_the_actual_duration_beats_the_planned_one(): void {
        $principle = $this->makePrinciple( 'AO-07' );
        $exercise  = $this->makeExercise( 'Opbouw', [ $principle ] );
        $player    = $this->makePlayer();

        $run = $this->makeCompletedRun( $exercise, 20 );
        $this->markPresent( $run['activity_id'], $player );

        $runs   = new TrainingPlanRunsRepository();
        $blocks = $runs->listBlocks( $run['run_id'] );
        $runs->updateBlock( (int) $blocks[0]->id, [ 'actual_duration_minutes' => 27 ] );

        ( new PlayerExposureAggregator() )->rebuildAll();

        global $wpdb;
        $this->assertSame( 27, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT minutes_total FROM {$wpdb->prefix}tt_player_principle_exposure WHERE player_id = %d",
            $player
        ) ), 'a block that ran 27 minutes contributed 27, not the 22 someone typed' );
    }

    /**
     * Completing a run recomputes the players who were present IN FULL.
     * Narrowing the aggregate to that run instead would overwrite a
     * player's season total with one evening.
     */
    public function test_the_incremental_rebuild_does_not_erase_earlier_trainings(): void {
        $principle = $this->makePrinciple( 'AO-08' );
        $exercise  = $this->makeExercise( 'Opbouw', [ $principle ] );
        $player    = $this->makePlayer();

        $first = $this->makeCompletedRun( $exercise, 20, '2026-08-04' );
        $this->markPresent( $first['activity_id'], $player );

        $second = $this->makeCompletedRun( $exercise, 30, '2026-08-11' );
        $this->markPresent( $second['activity_id'], $player );

        $aggregator = new PlayerExposureAggregator();
        $aggregator->rebuildAll();

        global $wpdb;
        $this->assertSame( 50, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT minutes_total FROM {$wpdb->prefix}tt_player_principle_exposure WHERE player_id = %d",
            $player
        ) ) );

        // Now the second run "completes" again, as the REST route does.
        $aggregator->rebuildForRun( $second['run_id'] );

        $this->assertSame( 50, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT minutes_total FROM {$wpdb->prefix}tt_player_principle_exposure WHERE player_id = %d",
            $player
        ) ), 'still both trainings — the narrowing is on who to recompute, not on what to count' );
    }

    // ---- the read side ----------------------------------------------------

    public function test_a_principle_never_trained_is_returned_not_omitted(): void {
        $trained = $this->makePrinciple( 'AO-09' );
        $never   = $this->makePrinciple( 'VS-01' );

        $exercise = $this->makeExercise( 'Opbouw', [ $trained ] );
        $player   = $this->makePlayer();

        $run = $this->makeCompletedRun( $exercise, 20 );
        $this->markPresent( $run['activity_id'], $player );

        ( new PlayerExposureAggregator() )->rebuildAll();

        $rows = ( new PlayerExposureReader() )->forPlayer( $player );
        $ids  = array_map( static fn( array $r ): int => (int) $r['principle_id'], $rows );

        $this->assertContains( $never, $ids, 'the empty row is the finding — omitting it hides what a coach came to see' );

        foreach ( $rows as $row ) {
            if ( (int) $row['principle_id'] !== $never ) continue;
            $this->assertSame( 0, (int) $row['minutes_total'] );
        }
    }

    public function test_never_trained_principles_sort_first(): void {
        $trained = $this->makePrinciple( 'AO-10' );
        $this->makePrinciple( 'VS-02' );

        $exercise = $this->makeExercise( 'Opbouw', [ $trained ] );
        $player   = $this->makePlayer();

        $run = $this->makeCompletedRun( $exercise, 20 );
        $this->markPresent( $run['activity_id'], $player );

        ( new PlayerExposureAggregator() )->rebuildAll();

        $rows = ( new PlayerExposureReader() )->forPlayer( $player );

        $this->assertSame(
            0,
            (int) $rows[0]['minutes_total'],
            'the reading order matches why someone opened the tab'
        );
    }

    public function test_the_summary_counts_a_training_once_not_once_per_principle(): void {
        $a = $this->makePrinciple( 'AO-11' );
        $b = $this->makePrinciple( 'AO-12' );

        // One exercise training two principles, run once.
        $exercise = $this->makeExercise( 'Opbouw', [ $a, $b ] );
        $player   = $this->makePlayer();

        $run = $this->makeCompletedRun( $exercise, 20 );
        $this->markPresent( $run['activity_id'], $player );

        ( new PlayerExposureAggregator() )->rebuildAll();

        $summary = ( new PlayerExposureReader() )->summaryFor( $player );

        $this->assertSame( 1, $summary['trainings'], 'one training that trained two things is one training' );
        $this->assertSame( 2, $summary['principles_trained'] );
    }

    // ---- observations -----------------------------------------------------

    public function test_a_note_with_no_rating_saves(): void {
        $player = $this->makePlayer();
        $run    = $this->makeCompletedRun( $this->makeExercise( 'X', [] ), 20 );

        $id = ( new TrainingObservationsRepository() )->create( [
            'run_id'    => $run['run_id'],
            'player_id' => $player,
            'note'      => 'Kept the ball moving under pressure.',
        ] );

        $this->assertGreaterThan( 0, $id, 'on a wet Tuesday this is the common case' );
    }

    public function test_an_observation_with_neither_a_rating_nor_a_note_is_refused(): void {
        $player = $this->makePlayer();
        $run    = $this->makeCompletedRun( $this->makeExercise( 'X', [] ), 20 );

        $id = ( new TrainingObservationsRepository() )->create( [
            'run_id'    => $run['run_id'],
            'player_id' => $player,
        ] );

        $this->assertSame( 0, $id, 'a blank entry on a child\'s timeline is worse than no entry' );
    }

    public function test_a_rating_outside_the_configured_scale_is_refused_not_clamped(): void {
        QueryHelpers::set_config( 'rating_min', '5' );
        QueryHelpers::set_config( 'rating_max', '9' );

        $player = $this->makePlayer();
        $run    = $this->makeCompletedRun( $this->makeExercise( 'X', [] ), 20 );

        $repo = new TrainingObservationsRepository();

        // Out of range with no note: nothing left to save, so refused.
        $this->assertSame( 0, $repo->create( [
            'run_id'    => $run['run_id'],
            'player_id' => $player,
            'rating'    => 47,
        ] ) );

        // Out of range WITH a note: the note saves, the bogus score does
        // not. Clamping to 9 would put a number on a child's record that
        // nobody chose.
        $id = $repo->create( [
            'run_id'    => $run['run_id'],
            'player_id' => $player,
            'rating'    => 47,
            'note'      => 'Still worth recording.',
        ] );
        $this->assertGreaterThan( 0, $id );

        global $wpdb;
        $this->assertNull( $wpdb->get_var( $wpdb->prepare(
            "SELECT rating FROM {$wpdb->prefix}tt_training_observations WHERE id = %d",
            $id
        ) ) );
    }

    public function test_an_observation_lands_on_the_journey_timeline_in_the_same_request(): void {
        $player = $this->makePlayer();
        $run    = $this->makeCompletedRun( $this->makeExercise( 'X', [] ), 20, '2026-08-11' );

        ( new TrainingObservationsRepository() )->create( [
            'run_id'    => $run['run_id'],
            'player_id' => $player,
            'note'      => 'Took the ball on the half-turn twice.',
        ] );

        global $wpdb;
        $event = $wpdb->get_row( $wpdb->prepare(
            "SELECT event_type, event_date, summary FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s ORDER BY id DESC LIMIT 1",
            $player,
            JourneyEventType::TRAINING_OBSERVED
        ) );

        $this->assertNotNull( $event );
        $this->assertStringContainsString( 'half-turn', (string) $event->summary );
        $this->assertStringStartsWith(
            '2026-08-11',
            (string) $event->event_date,
            'dated to the training, not to when the coach typed it up'
        );
    }

    public function test_deleting_a_run_takes_its_observations(): void {
        $player = $this->makePlayer();
        $run    = $this->makeCompletedRun( $this->makeExercise( 'X', [] ), 20 );

        $repo = new TrainingObservationsRepository();
        $repo->create( [ 'run_id' => $run['run_id'], 'player_id' => $player, 'note' => 'One.' ] );
        $repo->create( [ 'run_id' => $run['run_id'], 'player_id' => $player, 'note' => 'Two.' ] );

        $this->assertCount( 2, $repo->listForRun( $run['run_id'] ) );

        ( new TrainingPlanRunsRepository() )->delete( $run['run_id'] );

        $this->assertCount(
            0,
            $repo->listForRun( $run['run_id'] ),
            'a note about a training that no longer exists has nothing left to be about'
        );
    }

    // ---- REST + gating ----------------------------------------------------

    public function test_exposure_routes_are_registered(): void {
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey( self::BASE . '/players/(?P<id>\d+)/training-exposure', $routes );
        $this->assertArrayHasKey( self::BASE . '/training/coverage', $routes );
    }

    public function test_exposure_is_refused_to_an_anonymous_caller(): void {
        wp_set_current_user( 0 );

        foreach ( [ '/players/1/training-exposure', '/training/coverage' ] as $route ) {
            $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::BASE . $route ) );
            $this->assertContains( $response->get_status(), [ 401, 403 ], $route . ' must not be public' );
        }
    }

    // ---- privacy + coverage bookkeeping -----------------------------------

    public function test_a_player_can_hide_their_training_history_from_a_parent(): void {
        $this->assertContains(
            'training',
            PlayerParentVisibilityRepository::SECTIONS,
            'without the constant entry isVisible() returns true and the control is decorative'
        );
    }

    public function test_the_observations_table_is_generated_and_exposure_is_exempt(): void {
        $this->assertSame(
            DemoCoverage::STATE_GENERATED,
            DemoCoverage::stateOf( 'tt_training_observations' ),
            'D18 — a demo academy carries observations, which is what makes the module look used'
        );

        $this->assertSame(
            DemoCoverage::STATE_EXEMPT,
            DemoCoverage::stateOf( 'tt_player_principle_exposure' ),
            'derived: generating it would write rows that contradict their own source'
        );
    }
}
