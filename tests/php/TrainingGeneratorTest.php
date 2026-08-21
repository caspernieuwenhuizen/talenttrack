<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Training\Rules\TrainingExerciseSelectionPass;
use TT\Modules\Training\Services\SquadSizeEstimator;
use TT\Modules\Training\Services\TrainingPlanComposer;
use TT\Modules\Training\Wizard\NewTrainingPlanWizard;
use TT\Modules\Training\Wizard\ProposalStep;
use TT\Modules\Vct\Rules\SessionPlanContext;

/**
 * #2497 — the training plan generator.
 *
 * The acceptance that matters is behavioural: the same inputs must give
 * the same plan, an exercise must never be proposed above the age
 * group's intensity ceiling, and selection must prefer a drill that
 * serves players' open goals over one that serves nobody. Those are
 * tested against the selection pass directly, with fakes, because the
 * full pipeline needs a seeded VCT catalogue that a unit test should not
 * depend on.
 */
final class TrainingGeneratorTest extends WP_UnitTestCase {

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

    // ---- wizard registration --------------------------------------------

    public function test_wizard_declares_its_five_steps(): void {
        $wizard = new NewTrainingPlanWizard();

        $this->assertSame( 'new-training-plan', $wizard->slug() );
        $this->assertSame( 'tt_training_plan', $wizard->requiredCap() );
        $this->assertSame( 'when', $wizard->firstStepSlug() );

        $slugs = array_map( static fn( $s ) => $s->slug(), $wizard->steps() );
        $this->assertSame( [ 'when', 'theme', 'shape', 'proposal', 'review' ], $slugs );
    }

    public function test_every_step_chains_to_the_next_and_review_ends(): void {
        $steps = [];
        foreach ( ( new NewTrainingPlanWizard() )->steps() as $step ) {
            $steps[ $step->slug() ] = $step;
        }

        $this->assertSame( 'theme',    $steps['when']->nextStep( [] ) );
        $this->assertSame( 'shape',    $steps['theme']->nextStep( [] ) );
        $this->assertSame( 'proposal', $steps['shape']->nextStep( [] ) );
        $this->assertSame( 'review',   $steps['proposal']->nextStep( [] ) );
        $this->assertNull( $steps['review']->nextStep( [] ), 'review is the final step' );
    }

    // ---- step validation -------------------------------------------------

    public function test_when_step_refuses_a_missing_team_or_bad_date(): void {
        $step = $this->step( 'when' );

        $this->assertWPError( $step->validate( [ 'session_date' => '2026-08-25' ], [] ) );
        $this->assertWPError( $step->validate( [ 'team_id' => 7, 'session_date' => 'soon' ], [] ) );

        $ok = $step->validate( [ 'team_id' => 7, 'session_date' => '2026-08-25' ], [] );
        $this->assertIsArray( $ok );
        $this->assertSame( 7, $ok['team_id'] );
        $this->assertMatchesRegularExpression( '/^U\d{1,2}$/', $ok['age_group'] );
    }

    public function test_shape_step_bounds_the_session(): void {
        $step = $this->step( 'shape' );

        $this->assertWPError( $step->validate( [ 'requested_duration_minutes' => 5,   'expected_players' => 12 ], [] ) );
        $this->assertWPError( $step->validate( [ 'requested_duration_minutes' => 400, 'expected_players' => 12 ], [] ) );
        $this->assertWPError( $step->validate( [ 'requested_duration_minutes' => 75,  'expected_players' => 0 ], [] ) );

        $ok = $step->validate( [ 'requested_duration_minutes' => 75, 'expected_players' => 12 ], [ 'team_id' => 0 ] );
        $this->assertIsArray( $ok );
        $this->assertSame( 75, $ok['requested_duration_minutes'] );
    }

    public function test_theme_step_refuses_an_unknown_theme(): void {
        $step = $this->step( 'theme' );

        $this->assertWPError( $step->validate( [ 'tactical_theme' => 'nonsense' ], [] ) );

        $ok = $step->validate( [ 'tactical_theme' => '' ], [] );
        $this->assertIsArray( $ok, 'no theme is a valid answer' );
        $this->assertSame( '', $ok['tactical_theme'] );
    }

    // ---- selection: the behaviour the wave exists for ---------------------

    public function test_selection_prefers_the_exercise_that_serves_open_goals(): void {
        // Two equally valid candidates. One trains a principle six
        // players have an open goal on; the other trains nothing anyone
        // is working on. The first must win.
        $pass = new TrainingExerciseSelectionPass(
            new FakeCandidateSource( [
                [ 'id' => 10, 'intensity_band' => 3, 'age_min' => 12, 'age_max' => 13 ],
                [ 'id' => 11, 'intensity_band' => 3, 'age_min' => 12, 'age_max' => 13 ],
            ] ),
            new FakeRecentPicks( [] ),
            [ 501 => 6 ],
            [ 11 => [ 501 ] ]
        );

        $picked = $this->firstPick( $pass );
        $this->assertSame( 11, $picked, 'coverage must beat the lower id' );
    }

    public function test_selection_penalises_a_recently_used_exercise(): void {
        $pass = new TrainingExerciseSelectionPass(
            new FakeCandidateSource( [
                [ 'id' => 10, 'intensity_band' => 3, 'age_min' => 12, 'age_max' => 13 ],
                [ 'id' => 11, 'intensity_band' => 3, 'age_min' => 12, 'age_max' => 13 ],
            ] ),
            new FakeRecentPicks( [ 10 ] ),
            [],
            []
        );

        $this->assertSame( 11, $this->firstPick( $pass ), 'the drill run last week loses to a fresh one' );
    }

    public function test_selection_is_deterministic_on_a_tie(): void {
        $make = fn() => new TrainingExerciseSelectionPass(
            new FakeCandidateSource( [
                [ 'id' => 42, 'intensity_band' => 3, 'age_min' => 12, 'age_max' => 13 ],
                [ 'id' => 17, 'intensity_band' => 3, 'age_min' => 12, 'age_max' => 13 ],
            ] ),
            new FakeRecentPicks( [] ),
            [],
            []
        );

        $this->assertSame( 17, $this->firstPick( $make() ) );
        $this->assertSame( 17, $this->firstPick( $make() ), 'same inputs, same plan' );
    }

    public function test_selection_never_repeats_a_drill_inside_one_session(): void {
        $pass = new TrainingExerciseSelectionPass(
            new FakeCandidateSource( [
                [ 'id' => 10, 'intensity_band' => 3, 'age_min' => 12, 'age_max' => 13 ],
                [ 'id' => 11, 'intensity_band' => 3, 'age_min' => 12, 'age_max' => 13 ],
            ] ),
            new FakeRecentPicks( [] ),
            [],
            []
        );

        $ctx = $this->contextWithSlots( 2 );
        $ctx = $pass->apply( $ctx );

        $this->assertCount( 2, $ctx->blocks );
        $this->assertNotSame(
            $ctx->blocks[0]['exercise_id'],
            $ctx->blocks[1]['exercise_id'],
            'the same drill twice reads as a bug, not as emphasis'
        );
    }

    public function test_an_empty_slot_warns_rather_than_failing_silently(): void {
        $pass = new TrainingExerciseSelectionPass(
            new FakeCandidateSource( [] ),
            new FakeRecentPicks( [] ),
            [],
            []
        );

        $ctx = $pass->apply( $this->contextWithSlots( 1 ) );

        $this->assertNull( $ctx->blocks[0]['exercise_id'] );
        $this->assertSame( 'no_candidate_for_slot', $ctx->warnings[0]['code'] );
        $this->assertSame( 'caution', $ctx->warnings[0]['severity'] );
    }

    public function test_an_unusable_age_group_blocks_rather_than_guessing(): void {
        $pass = new TrainingExerciseSelectionPass(
            new FakeCandidateSource( [] ),
            new FakeRecentPicks( [] ),
            [],
            []
        );

        $ctx            = $this->contextWithSlots( 1 );
        $ctx->age_group = 'seniors';
        $ctx            = $pass->apply( $ctx );

        $this->assertSame( 'block', $ctx->warnings[0]['severity'] );
        $this->assertSame( [], $ctx->blocks, 'nothing is composed when age safety cannot be judged' );
    }

    /**
     * The dead-end this closes: the proposal step renders "this training
     * cannot be drafted", but its Next button used to advance anyway, so
     * a coach would name the plan on the review step and only learn at
     * Save that it was never going to exist.
     */
    public function test_a_blocked_draft_holds_the_coach_on_the_proposal_step(): void {
        $step  = new ProposalStep();
        $state = [
            'team_id'                    => 0,
            'age_group'                  => 'seniors',
            'session_date'               => '2026-08-26',
            'tactical_theme'             => 'build_up',
            'requested_duration_minutes' => 75,
            'roster_player_ids'          => [],
        ];

        $result = $step->validate( [], $state );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'draft_blocked', $result->get_error_code() );
        $this->assertNotEmpty(
            $result->get_error_message(),
            'the reason the engine blocked is what the coach needs to read, not a bare code'
        );
    }

    /**
     * The shape step asks for a length, but the engine composes from the
     * age group's slot template and reports whatever the slots summed to
     * — ask for 75 and a U13 draft lands on 90. That is the engine's
     * behaviour, not a bug to paper over, so the draft says it out loud.
     */
    public function test_a_draft_that_misses_the_requested_length_says_so(): void {
        $warnings = $this->lengthNotice(
            [ [ 'duration_minutes' => 45 ], [ 'duration_minutes' => 45 ] ],
            [ 'requested_duration_minutes' => 75 ]
        );

        $this->assertCount( 1, $warnings );
        $this->assertSame( 'drafted_length_differs', $warnings[0]['code'] );
        $this->assertSame( 'caution', $warnings[0]['severity'], 'a length difference is the coach\'s call, not a block' );
        $this->assertSame( 75, $warnings[0]['requested'] );
        $this->assertSame( 90, $warnings[0]['drafted'] );

        $text = ProposalStep::warningText( 'drafted_length_differs', $warnings[0] );
        $this->assertStringContainsString( '75', $text );
        $this->assertStringContainsString( '90', $text );
    }

    public function test_a_few_minutes_of_slot_rounding_is_not_worth_a_notice(): void {
        $this->assertSame(
            [],
            $this->lengthNotice(
                [ [ 'duration_minutes' => 38 ], [ 'duration_minutes' => 40 ] ],
                [ 'requested_duration_minutes' => 75 ]
            ),
            'three minutes is rounding between slots, not something to plan around'
        );

        $this->assertSame(
            [],
            $this->lengthNotice(
                [ [ 'duration_minutes' => 90 ] ],
                [ 'requested_duration_minutes' => 0 ]
            ),
            'no requested length means nothing to compare against'
        );
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed>       $payload
     * @return list<array<string,mixed>>
     */
    private function lengthNotice( array $blocks, array $payload ): array {
        $method = new \ReflectionMethod( TrainingPlanComposer::class, 'withLengthNotice' );
        $method->setAccessible( true );

        return (array) $method->invoke( null, $blocks, [], $payload );
    }

    // ---- goals read API ---------------------------------------------------

    public function test_goals_read_api_returns_nothing_for_an_empty_squad(): void {
        $this->assertSame( [], ( new GoalsRepository() )->openPrincipleTargetsForPlayers( [] ) );
        $this->assertSame( [], ( new GoalsRepository() )->openPrincipleTargetsForPlayers( [ 0, -1 ] ) );
    }

    public function test_goals_read_api_finds_both_link_shapes(): void {
        global $wpdb;

        // A goal carrying its principle the old way (migration 0015).
        $wpdb->insert( $wpdb->prefix . 'tt_goals', [
            'player_id'           => 9001,
            'title'               => 'Direct principle',
            'status'              => 'In Progress',
            'created_by'          => 1,
            'club_id'             => 1,
            'linked_principle_id' => 777,
        ] );

        // And one carrying it the polymorphic way (migration 0031).
        $wpdb->insert( $wpdb->prefix . 'tt_goals', [
            'player_id'  => 9002,
            'title'      => 'Linked principle',
            'status'     => 'Pending',
            'created_by' => 1,
            'club_id'    => 1,
        ] );
        $goal_id = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_goal_links', [
            'goal_id'   => $goal_id,
            'link_type' => 'principle',
            'link_id'   => 888,
            'club_id'   => 1,
        ] );

        $targets = ( new GoalsRepository() )->openPrincipleTargetsForPlayers( [ 9001, 9002 ] );

        $this->assertSame( [ 777 ], $targets[9001] ?? [] );
        $this->assertSame( [ 888 ], $targets[9002] ?? [], 'the polymorphic link counts too' );
    }

    public function test_goals_read_api_ignores_finished_goals(): void {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_goals', [
            'player_id'           => 9003,
            'title'               => 'Done already',
            'status'              => 'Completed',
            'created_by'          => 1,
            'club_id'             => 1,
            'linked_principle_id' => 999,
        ] );

        $targets = ( new GoalsRepository() )->openPrincipleTargetsForPlayers( [ 9003 ] );

        $this->assertArrayNotHasKey(
            9003,
            $targets,
            'a completed goal is not something the session should still aim at'
        );
    }

    // ---- squad size -------------------------------------------------------

    public function test_squad_estimator_is_honest_about_where_the_number_came_from(): void {
        $suggest = ( new SquadSizeEstimator() )->suggest( 999999 );

        $this->assertSame( 'none', $suggest['source'], 'an unknown team must not present a guess as data' );
        $this->assertSame( 0, $suggest['value'] );
    }

    // ---- REST -------------------------------------------------------------

    public function test_generate_route_is_registered_and_gated(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/talenttrack/v1/training/plans/generate', $routes );
        $this->assertArrayHasKey( '/talenttrack/v1/training/plans/suggest', $routes );

        wp_set_current_user( 0 );
        foreach ( [ [ 'POST', 'generate' ], [ 'GET', 'suggest' ] ] as [ $method, $path ] ) {
            $response = rest_get_server()->dispatch(
                new WP_REST_Request( $method, '/talenttrack/v1/training/plans/' . $path )
            );
            $this->assertContains( $response->get_status(), [ 401, 403 ], "{$method} {$path} must deny anonymous" );
        }
    }

    public function test_generate_without_a_team_is_a_400(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'POST', '/talenttrack/v1/training/plans/generate' )
        );
        $body = $response->get_data();

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'team_id_required', $body['errors'][0]['code'] ?? '' );
    }

    // ---- helpers ----------------------------------------------------------

    private function step( string $slug ) {
        foreach ( ( new NewTrainingPlanWizard() )->steps() as $step ) {
            if ( $step->slug() === $slug ) return $step;
        }
        $this->fail( "no step {$slug}" );
    }

    private function contextWithSlots( int $count ): SessionPlanContext {
        $ctx            = new SessionPlanContext();
        $ctx->age_group = 'U13';
        $ctx->team_id   = 1;
        $ctx->md_context = 'NONE';

        for ( $i = 1; $i <= $count; $i++ ) {
            $ctx->slots[] = [
                'sequence'           => $i,
                'category'           => 'technical',
                'intensity_band_min' => 1,
                'intensity_band_max' => 7,
                'duration_target'    => 20,
                'effective_theme'    => null,
            ];
        }
        return $ctx;
    }

    private function firstPick( TrainingExerciseSelectionPass $pass ): ?int {
        $ctx = $pass->apply( $this->contextWithSlots( 1 ) );
        return $ctx->blocks[0]['exercise_id'] ?? null;
    }
}

/** Stands in for the exercise repository so selection can be tested without a seeded catalogue. */
final class FakeCandidateSource extends \TT\Modules\Vct\Repositories\VctExercisesRepository {
    /** @var list<array<string,mixed>> */
    private array $candidates;

    /** @param list<array<string,mixed>> $candidates */
    public function __construct( array $candidates ) { $this->candidates = $candidates; }

    public function findCandidates(
        string $category,
        int $intensity_band_min,
        int $intensity_band_max,
        int $age,
        string $md_context,
        ?string $tactical_theme
    ): array {
        return $this->candidates;
    }
}

final class FakeRecentPicks implements \TT\Modules\Vct\Rules\Providers\RecentPicksProvider {
    /** @var list<int> */
    private array $ids;

    /** @param list<int> $ids */
    public function __construct( array $ids ) { $this->ids = $ids; }

    public function recentExerciseIds( int $team_id, int $lookback_days ): array { return $this->ids; }
}
