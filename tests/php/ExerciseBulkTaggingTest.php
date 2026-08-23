<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Exercises\ExercisesRepository;

/**
 * #2753 — classifying the exercise library in bulk.
 *
 * The promises worth pinning are the ones that fail quietly:
 *
 *   - `add` must not wipe what an exercise already had, or applying a
 *     principle to a dozen rondos destroys the two already tagged well;
 *   - `replace` must stay inside the methodology it is writing to, or
 *     it silently deletes a club's other methodology's tagging with
 *     nothing to report it;
 *   - an empty principle set is not a no-op — it is "I looked, none
 *     apply", and it has to take the row out of the work list, or the
 *     39 warm-ups never leave and the count never reaches zero.
 */
final class ExerciseBulkTaggingTest extends WP_UnitTestCase {

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

    private function curator(): int {
        $id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $id );

        return $id;
    }

    private function makeExercise( string $name = 'Rondo 4v1' ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_exercises', [
            'club_id' => 1, 'uuid' => wp_generate_uuid4(), 'name' => $name,
            'visibility' => 'club', 'duration_minutes' => 12,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function makePrinciple( string $code, int $methodology_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_principles', [
            'club_id' => 1, 'code' => $code, 'methodology_id' => $methodology_id,
            'title_json' => wp_json_encode( [ 'nl_NL' => 'Principe ' . $code ] ),
        ] );

        return (int) $wpdb->insert_id;
    }

    private function call( string $route, array $params = [], string $method = 'POST' ): array {
        $request = new WP_REST_Request( $method, self::BASE . $route );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $response = rest_get_server()->dispatch( $request );

        return [ $response->get_status(), $response->get_data() ];
    }

    // ---- add --------------------------------------------------------------

    public function test_many_exercises_are_tagged_in_one_call(): void {
        $this->curator();
        $repo = new ExercisesRepository();

        $exercises  = [ $this->makeExercise( 'A' ), $this->makeExercise( 'B' ), $this->makeExercise( 'C' ) ];
        $principles = [ $this->makePrinciple( 'AO-01', 1 ), $this->makePrinciple( 'AO-02', 1 ) ];

        [ $status, $data ] = $this->call( '/exercises/principles/bulk', [
            'exercise_ids' => $exercises, 'principle_ids' => $principles,
        ] );

        $this->assertSame( 200, $status );
        $this->assertSame( 3, $data['data']['changed'] );

        foreach ( $exercises as $id ) {
            $this->assertEqualsCanonicalizing( $principles, $repo->principleIdsFor( $id ) );
        }
    }

    public function test_add_keeps_what_an_exercise_already_had(): void {
        $this->curator();
        $repo = new ExercisesRepository();

        $exercise = $this->makeExercise();
        $existing = $this->makePrinciple( 'AO-01', 1 );
        $extra    = $this->makePrinciple( 'AO-02', 1 );

        $repo->setPrincipleIds( $exercise, [ $existing ] );
        $this->call( '/exercises/principles/bulk', [
            'exercise_ids' => [ $exercise ], 'principle_ids' => [ $extra ], 'mode' => 'add',
        ] );

        $this->assertEqualsCanonicalizing(
            [ $existing, $extra ],
            $repo->principleIdsFor( $exercise ),
            'applying a principle to a batch must not wipe the ones already there'
        );
    }

    public function test_applying_the_same_set_twice_does_not_duplicate(): void {
        global $wpdb;

        $this->curator();
        $exercise  = $this->makeExercise();
        $principle = $this->makePrinciple( 'AO-01', 1 );

        $this->call( '/exercises/principles/bulk', [ 'exercise_ids' => [ $exercise ], 'principle_ids' => [ $principle ] ] );
        $this->call( '/exercises/principles/bulk', [ 'exercise_ids' => [ $exercise ], 'principle_ids' => [ $principle ] ] );

        $this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_exercise_principles WHERE exercise_id = %d",
            $exercise
        ) ) );
    }

    // ---- replace ----------------------------------------------------------

    /**
     * The one that would destroy data silently. An exercise can carry
     * principles from several methodologies; replacing within one must
     * leave the others exactly as they were.
     */
    public function test_replace_stays_inside_its_own_methodology(): void {
        $this->curator();
        $repo = new ExercisesRepository();

        $exercise = $this->makeExercise();
        $mine     = [ $this->makePrinciple( 'AO-01', 1 ), $this->makePrinciple( 'AO-02', 1 ) ];
        $theirs   = [ $this->makePrinciple( 'XX-01', 2 ), $this->makePrinciple( 'XX-02', 2 ) ];
        $newOne   = $this->makePrinciple( 'AO-03', 1 );

        $repo->setPrincipleIds( $exercise, array_merge( $mine, $theirs ) );

        $this->call( '/exercises/principles/bulk', [
            'exercise_ids' => [ $exercise ], 'principle_ids' => [ $newOne ], 'mode' => 'replace',
        ] );

        $after = $repo->principleIdsFor( $exercise );

        $this->assertEqualsCanonicalizing(
            array_merge( [ $newOne ], $theirs ),
            $after,
            'the other methodology must survive untouched'
        );
        foreach ( $mine as $id ) {
            $this->assertNotContains( $id, $after, 'the replaced set should be gone' );
        }
    }

    // ---- the reviewed state -----------------------------------------------

    public function test_none_apply_takes_an_exercise_out_of_the_work_list(): void {
        $this->curator();
        $repo = new ExercisesRepository();

        $exercise = $this->makeExercise( 'Warming-up' );

        $before = array_column( $repo->listAwaitingReview(), 'id' );
        $this->assertContainsEquals( (string) $exercise, array_map( 'strval', $before ) );

        [ $status, $data ] = $this->call( '/exercises/principles/bulk', [
            'exercise_ids' => [ $exercise ], 'principle_ids' => [],
        ] );

        $this->assertSame( 200, $status );
        $this->assertSame( 1, $data['data']['changed'], 'an empty set is a decision, not a no-op' );

        $after = array_map( 'strval', array_column( $repo->listAwaitingReview(), 'id' ) );
        $this->assertNotContains( (string) $exercise, $after, 'a warm-up nobody will tag must be able to leave the list' );
        $this->assertSame( [], $repo->principleIdsFor( $exercise ), 'and it gains no principles' );
    }

    public function test_progress_counts_up(): void {
        $this->curator();

        $this->makeExercise( 'One' );
        $second = $this->makeExercise( 'Two' );

        [ , $before ] = $this->call( '/exercises/awaiting-review', [], 'GET' );
        $this->call( '/exercises/principles/bulk', [ 'exercise_ids' => [ $second ], 'principle_ids' => [] ] );
        [ , $after ] = $this->call( '/exercises/awaiting-review', [], 'GET' );

        $this->assertSame(
            (int) $before['data']['progress']['reviewed'] + 1,
            (int) $after['data']['progress']['reviewed']
        );
    }

    // ---- gates ------------------------------------------------------------

    public function test_an_empty_selection_is_refused(): void {
        $this->curator();

        [ $status ] = $this->call( '/exercises/principles/bulk', [ 'exercise_ids' => [] ] );

        $this->assertSame( 400, $status );
    }

    public function test_someone_who_cannot_manage_exercises_cannot_tag(): void {
        $this->curator();
        $exercise = $this->makeExercise();

        wp_set_current_user( 0 );
        [ $status ] = $this->call( '/exercises/principles/bulk', [ 'exercise_ids' => [ $exercise ] ] );
        $this->assertContains( $status, [ 401, 403 ] );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_parent' ] ) );
        [ $status ] = $this->call( '/exercises/principles/bulk', [ 'exercise_ids' => [ $exercise ] ] );
        $this->assertContains( $status, [ 401, 403 ] );
    }

    /**
     * The point of the whole screen: a tagged exercise becomes visible
     * to the generator and countable towards exposure. Without this the
     * feature is a tidy list nobody benefits from.
     */
    public function test_tagging_makes_an_exercise_reachable_by_principle(): void {
        global $wpdb;

        $this->curator();
        $exercise  = $this->makeExercise();
        $principle = $this->makePrinciple( 'AO-01', 1 );

        $this->call( '/exercises/principles/bulk', [
            'exercise_ids' => [ $exercise ], 'principle_ids' => [ $principle ],
        ] );

        $found = $wpdb->get_col( $wpdb->prepare(
            "SELECT exercise_id FROM {$wpdb->prefix}tt_exercise_principles WHERE principle_id = %d",
            $principle
        ) );

        $this->assertContainsEquals( (string) $exercise, array_map( 'strval', $found ) );
    }
}
