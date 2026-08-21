<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\DemoData\DemoCoverage;
use TT\Modules\Training\Services\PlanCoverageService;

/**
 * #2498 — the plan builder.
 *
 * What is worth testing here is the reasoning, not the DOM. The builder's
 * drag handles and steppers are exercised by hand and by the Playwright
 * smoke; what a unit test can hold onto is the question the whole module
 * exists to answer — *which players does this plan actually work on* —
 * and the ranking that makes the exercise picker worth opening.
 */
final class TrainingPlanBuilderTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1/training/plans';

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

    /** Administrators bypass every tt_* cap, so they can plan. */
    private function planner(): int {
        $id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $id );
        return $id;
    }

    /** @return array{0:int,1:mixed} */
    private function call( string $method, string $route, array $params = [] ): array {
        $request = new WP_REST_Request( $method, $route );
        foreach ( $params as $k => $v ) $request->set_param( $k, $v );
        $response = rest_get_server()->dispatch( $request );
        $envelope = $response->get_data();

        return [
            $response->get_status(),
            is_array( $envelope ) ? ( $envelope['data'] ?? null ) : $envelope,
        ];
    }

    // ---- fixtures ---------------------------------------------------------

    private function makePlayerWithGoalOn( int $principle_id, string $first, string $last ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => $first,
            'last_name'  => $last,
        ] );
        $player_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_goals', [
            'club_id'             => 1,
            'player_id'           => $player_id,
            'title'               => 'Open goal',
            'status'              => 'In Progress',
            'created_by'          => 1,
            'linked_principle_id' => $principle_id,
        ] );

        return $player_id;
    }

    private function makeExerciseOn( string $name, array $principle_ids ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_exercises', [
            'club_id'          => 1,
            'name'             => $name,
            'visibility'       => 'club',
            'duration_minutes' => 20,
        ] );
        $exercise_id = (int) $wpdb->insert_id;

        foreach ( $principle_ids as $principle_id ) {
            $wpdb->insert( $wpdb->prefix . 'tt_exercise_principles', [
                'club_id'      => 1,
                'exercise_id'  => $exercise_id,
                'principle_id' => $principle_id,
            ] );
        }

        return $exercise_id;
    }

    // ---- coverage ---------------------------------------------------------

    public function test_coverage_splits_the_squad_into_served_and_missed(): void {
        $served = $this->makePlayerWithGoalOn( 501, 'Sem', 'Bakker' );
        $missed = $this->makePlayerWithGoalOn( 502, 'Nora', 'de Wit' );

        $coverage = ( new PlanCoverageService() )->forPrincipleIds( [ 501 ], [ $served, $missed ] );

        $this->assertSame( [ $served ], $coverage['player_ids'] );
        $this->assertSame( [ $missed ], $coverage['missed_player_ids'] );
    }

    public function test_coverage_carries_names_because_a_count_is_not_actionable(): void {
        $served = $this->makePlayerWithGoalOn( 511, 'Sem', 'Bakker' );

        $coverage = ( new PlanCoverageService() )->forPrincipleIds( [ 511 ], [ $served ] );

        $this->assertSame( 'Sem Bakker', $coverage['players'][0]['name'] );
        $this->assertTrue( $coverage['players'][0]['covered'] );
    }

    public function test_coverage_lists_served_players_before_missed_ones(): void {
        // Alphabetically 'Aad' sorts first, so if the served player still
        // leads, the sort is on coverage rather than on name alone.
        $missed = $this->makePlayerWithGoalOn( 522, 'Aad', 'Aalders' );
        $served = $this->makePlayerWithGoalOn( 521, 'Zoe', 'Zomer' );

        $coverage = ( new PlanCoverageService() )->forPrincipleIds( [ 521 ], [ $served, $missed ] );

        $this->assertTrue( $coverage['players'][0]['covered'] );
        $this->assertSame( 'Zoe Zomer', $coverage['players'][0]['name'] );
        $this->assertFalse( $coverage['players'][1]['covered'] );
    }

    public function test_a_player_with_no_open_goal_is_neither_served_nor_missed(): void {
        // Nothing to say about a player who is not working on anything —
        // counting them as "missed" would make every plan look worse than
        // it is and train coaches to ignore the number.
        $coverage = ( new PlanCoverageService() )->forPrincipleIds( [ 531 ], [ 987654 ] );

        $this->assertSame( [], $coverage['player_ids'] );
        $this->assertSame( [], $coverage['missed_player_ids'] );
        $this->assertSame( [], $coverage['players'] );
    }

    public function test_coverage_resolves_exercises_to_the_principles_they_train(): void {
        $player   = $this->makePlayerWithGoalOn( 541, 'Daan', 'Koster' );
        $exercise = $this->makeExerciseOn( 'Opbouw 7v5', [ 541 ] );

        $coverage = ( new PlanCoverageService() )->forExerciseIds( [ $exercise ], [ $player ] );

        $this->assertSame( [ $player ], $coverage['player_ids'] );
    }

    // ---- picker ranking ---------------------------------------------------

    public function test_the_picker_ranks_by_how_many_players_a_drill_serves(): void {
        $a = $this->makePlayerWithGoalOn( 551, 'Sem', 'Bakker' );
        $b = $this->makePlayerWithGoalOn( 551, 'Nora', 'de Wit' );
        $c = $this->makePlayerWithGoalOn( 552, 'Youssef', 'El Amrani' );

        $popular = $this->makeExerciseOn( 'Serves two', [ 551 ] );
        $niche   = $this->makeExerciseOn( 'Serves one', [ 552 ] );
        $useless = $this->makeExerciseOn( 'Serves nobody', [ 553 ] );

        $served = ( new PlanCoverageService() )
            ->playersServedByExercise( [ $popular, $niche, $useless ], [ $a, $b, $c ] );

        $this->assertSame( 2, $served[ $popular ] );
        $this->assertSame( 1, $served[ $niche ] );
        $this->assertSame( 0, $served[ $useless ], 'an unranked drill still gets a number, not a missing key' );
    }

    public function test_one_player_wanting_two_of_a_drills_principles_is_counted_once(): void {
        // Otherwise a drill tagged with five principles would outrank a
        // better one purely by being over-tagged.
        $player = $this->makePlayerWithGoalOn( 561, 'Mees', 'Jansen' );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_goals', [
            'club_id'             => 1,
            'player_id'           => $player,
            'title'               => 'Second open goal',
            'status'              => 'In Progress',
            'created_by'          => 1,
            'linked_principle_id' => 562,
        ] );

        $exercise = $this->makeExerciseOn( 'Tagged twice', [ 561, 562 ] );

        $served = ( new PlanCoverageService() )->playersServedByExercise( [ $exercise ], [ $player ] );

        $this->assertSame( 1, $served[ $exercise ] );
    }

    // ---- REST -------------------------------------------------------------

    public function test_builder_routes_are_registered(): void {
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey( self::BASE . '/(?P<id>\d+)/coverage', $routes );
        $this->assertArrayHasKey( self::BASE . '/(?P<id>\d+)/exercise-options', $routes );
    }

    public function test_coverage_and_options_refuse_an_anonymous_caller(): void {
        wp_set_current_user( 0 );

        [ $status ] = $this->call( 'GET', self::BASE . '/1/coverage' );
        $this->assertContains( $status, [ 401, 403 ] );

        [ $status ] = $this->call( 'GET', self::BASE . '/1/exercise-options' );
        $this->assertContains( $status, [ 401, 403 ] );
    }

    public function test_coverage_on_a_missing_plan_is_a_404(): void {
        $this->planner();

        [ $status ] = $this->call( 'GET', self::BASE . '/999999/coverage' );
        $this->assertSame( 404, $status );
    }

    public function test_exercise_options_carry_their_own_sort_key(): void {
        $this->planner();
        $this->makeExerciseOn( 'Any drill', [] );

        [ $status, $data ] = $this->call( 'POST', self::BASE, [ 'title' => 'A plan' ] );
        $this->assertSame( 201, $status );
        $plan_id = (int) $data['plan']['id'];

        [ $status, $data ] = $this->call( 'GET', self::BASE . '/' . $plan_id . '/exercise-options' );

        $this->assertSame( 200, $status );
        $this->assertArrayHasKey( 'options', $data );
        $this->assertArrayHasKey(
            'players_served',
            $data['options'][0],
            'the sort key travels with the row — a ranking the user cannot see is one they cannot trust'
        );
    }

    public function test_blocks_round_trip_through_the_builders_save(): void {
        $this->planner();

        [ , $data ] = $this->call( 'POST', self::BASE, [ 'title' => 'Round trip' ] );
        $plan_id = (int) $data['plan']['id'];

        [ $status, $data ] = $this->call( 'PUT', self::BASE . '/' . $plan_id . '/blocks', [
            'blocks' => [
                [ 'order_index' => 0, 'block_type' => 'warmup', 'duration_minutes' => 12 ],
                [ 'order_index' => 1, 'block_type' => 'main',   'duration_minutes' => 22 ],
            ],
        ] );

        $this->assertSame( 200, $status );
        $this->assertCount( 2, $data['blocks'] );
        $this->assertSame( 'warmup', $data['blocks'][0]['block_type'] );
        $this->assertSame( 22, $data['blocks'][1]['duration_minutes'] );
    }

    public function test_a_reorder_is_a_whole_new_block_list_not_a_diff(): void {
        // The builder holds the desired order client-side and sends it
        // whole; anything else means two sources of truth for what
        // position a block is in.
        $this->planner();

        [ , $data ] = $this->call( 'POST', self::BASE, [ 'title' => 'Reorder' ] );
        $plan_id = (int) $data['plan']['id'];

        $this->call( 'PUT', self::BASE . '/' . $plan_id . '/blocks', [
            'blocks' => [
                [ 'order_index' => 0, 'block_type' => 'warmup',   'duration_minutes' => 10 ],
                [ 'order_index' => 1, 'block_type' => 'cooldown', 'duration_minutes' => 5 ],
            ],
        ] );

        [ , $data ] = $this->call( 'PUT', self::BASE . '/' . $plan_id . '/blocks', [
            'blocks' => [
                [ 'order_index' => 0, 'block_type' => 'cooldown', 'duration_minutes' => 5 ],
                [ 'order_index' => 1, 'block_type' => 'warmup',   'duration_minutes' => 10 ],
            ],
        ] );

        $this->assertSame( 'cooldown', $data['blocks'][0]['block_type'] );
        $this->assertSame( 'warmup', $data['blocks'][1]['block_type'] );
    }

    // ---- demo coverage ----------------------------------------------------

    public function test_the_plan_tables_are_no_longer_merely_planned(): void {
        // Wave 3 marked these `planned` against this issue rather than
        // exempting them. Shipping the builder without the generator
        // would leave a demo academy with an empty Training module.
        foreach ( [ 'tt_training_plans', 'tt_training_plan_blocks', 'tt_training_plan_principles' ] as $table ) {
            $this->assertSame(
                DemoCoverage::STATE_GENERATED,
                DemoCoverage::stateOf( $table ),
                "{$table} still has no demo generator"
            );
        }
    }

    public function test_a_plans_children_are_reachable_by_the_wipe(): void {
        // Their rows are addressed by plan_id, and the cleaner reads the
        // child type's own tag set before it ever looks at delete_by — so
        // a child type with no tags is silently skipped and its rows
        // survive every wipe.
        foreach ( [ 'tt_training_plan_blocks', 'tt_training_plan_principles' ] as $table ) {
            $delete_by = DemoCoverage::deleteBy( $table );

            $this->assertNotNull( $delete_by, "{$table} has no delete_by route" );
            $this->assertSame( 'plan_id', $delete_by['column'] );
        }
    }
}
