<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #2496 — REST surface for training plans and runs.
 *
 * The new-route smoke-test mandate (#1388 Tier 2): every
 * `register_rest_route` gets an authorization-coverage check. These routes
 * expose a team's session planning and, through the run, the record every
 * later per-player training figure derives from — a misconfigured
 * `permission_callback` would leak or let an unprivileged user rewrite it.
 *
 * Asserted over the LIVE route table:
 *
 *   (a) every route registers on `rest_api_init`;
 *   (b) every route denies an unauthenticated caller — 401/403, never 200
 *       (a silent leak) and never >= 500 (a crashing permission_callback);
 *   (c) a logged-in subscriber without `tt_training_plan` is denied too;
 *   (d) the happy path works end to end for a user who holds the cap.
 */
final class TrainingPlansRestControllerTest extends WP_UnitTestCase {

    private const PLANS = '/talenttrack/v1/training/plans';
    private const RUNS  = '/talenttrack/v1/training/runs';

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

    /**
     * A user who may plan.
     *
     * `tt_training_plan` is matrix-only, so adding the raw capability to an
     * arbitrary user is not enough — MatrixGate resolves the cap through
     * the authorization matrix, and a user with no persona row is denied
     * regardless of what `add_cap()` says. WP administrators bypass every
     * tt_* cap unconditionally (LegacyCapMapper), which is the idiom the
     * other REST tests in this suite use for their happy paths.
     */
    private function planner(): int {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );
        return $user_id;
    }

    /** @return array{0:int,1:mixed} status + data */
    private function call( string $method, string $route, array $body = [] ): array {
        $request = new WP_REST_Request( $method, $route );
        foreach ( $body as $k => $v ) {
            $request->set_param( $k, $v );
        }
        $response = rest_get_server()->dispatch( $request );
        return [ $response->get_status(), $response->get_data() ];
    }

    // ---- (a) routes register --------------------------------------------

    public function test_routes_are_registered(): void {
        $routes = rest_get_server()->get_routes();

        foreach ( [
            self::PLANS,
            self::PLANS . '/(?P<id>\d+)',
            self::PLANS . '/(?P<id>\d+)/duplicate',
            self::PLANS . '/(?P<id>\d+)/blocks',
            self::RUNS,
            self::RUNS . '/(?P<id>\d+)',
            self::RUNS . '/(?P<id>\d+)/blocks/(?P<block>\d+)',
            '/talenttrack/v1/activities/(?P<id>\d+)/training-plan',
        ] as $route ) {
            $this->assertArrayHasKey( $route, $routes, "route not registered: {$route}" );
        }
    }

    // ---- (b) + (c) authorization ----------------------------------------

    public function test_anonymous_is_denied_on_every_write_route(): void {
        wp_set_current_user( 0 );

        foreach ( [
            [ 'GET',    self::PLANS ],
            [ 'POST',   self::PLANS ],
            [ 'GET',    self::PLANS . '/1' ],
            [ 'PATCH',  self::PLANS . '/1' ],
            [ 'DELETE', self::PLANS . '/1' ],
            [ 'POST',   self::PLANS . '/1/duplicate' ],
            [ 'GET',    self::PLANS . '/1/blocks' ],
            [ 'PUT',    self::PLANS . '/1/blocks' ],
            [ 'POST',   self::RUNS ],
            [ 'GET',    self::RUNS . '/1' ],
            [ 'PATCH',  self::RUNS . '/1' ],
            [ 'DELETE', self::RUNS . '/1' ],
            [ 'PATCH',  self::RUNS . '/1/blocks/1' ],
        ] as [ $method, $route ] ) {
            [ $status ] = $this->call( $method, $route );
            $this->assertContains(
                $status,
                [ 401, 403 ],
                "{$method} {$route} must deny an anonymous caller, got {$status}"
            );
        }
    }

    public function test_logged_in_user_without_the_cap_is_denied(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        [ $status ] = $this->call( 'GET', self::PLANS );
        $this->assertSame( 403, $status );

        [ $status ] = $this->call( 'POST', self::PLANS, [ 'title' => 'Nope' ] );
        $this->assertSame( 403, $status );
    }

    // ---- (d) happy path --------------------------------------------------

    public function test_create_read_update_and_archive_a_plan(): void {
        $this->planner();

        [ $status, $data ] = $this->call( 'POST', self::PLANS, [
            'title'     => 'Opbouwen van achteruit',
            'team_id'   => 7,
            'theme_key' => 'build_up',
        ] );
        $this->assertSame( 201, $status );
        $this->assertSame( 'Opbouwen van achteruit', $data['plan']['title'] );
        $this->assertNotEmpty( $data['plan']['uuid'] );
        $id = (int) $data['plan']['id'];

        [ $status, $data ] = $this->call( 'GET', self::PLANS . '/' . $id );
        $this->assertSame( 200, $status );
        $this->assertSame( [], $data['plan']['blocks'] );
        $this->assertSame( [], $data['plan']['principles'] );

        [ $status, $data ] = $this->call( 'PATCH', self::PLANS . '/' . $id, [ 'title' => 'Anders' ] );
        $this->assertSame( 200, $status );
        $this->assertSame( 'Anders', $data['plan']['title'] );

        [ $status, $data ] = $this->call( 'DELETE', self::PLANS . '/' . $id );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['archived'] );

        // Archived plans drop out of the default listing.
        [ , $data ] = $this->call( 'GET', self::PLANS );
        $this->assertNotContains( $id, array_column( $data['plans'], 'id' ) );
    }

    public function test_create_without_a_title_is_a_400_not_a_500(): void {
        $this->planner();

        [ $status, $data ] = $this->call( 'POST', self::PLANS, [ 'team_id' => 7 ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'title_required', $data['error'] );
    }

    public function test_missing_plan_is_a_404(): void {
        $this->planner();

        foreach ( [ 'GET', 'PATCH', 'DELETE' ] as $method ) {
            [ $status ] = $this->call( $method, self::PLANS . '/99999' );
            $this->assertSame( 404, $status, "{$method} on a missing plan must 404" );
        }
    }

    public function test_bulk_replace_blocks_and_recalculate_duration(): void {
        $this->planner();

        [ , $data ] = $this->call( 'POST', self::PLANS, [ 'title' => 'Blokken' ] );
        $id = (int) $data['plan']['id'];

        [ $status, $data ] = $this->call( 'PUT', self::PLANS . '/' . $id . '/blocks', [
            'blocks' => [
                [ 'block_type' => 'warmup', 'duration_minutes' => 12 ],
                [ 'block_type' => 'main',   'duration_minutes' => 22 ],
                [ 'block_type' => 'game',   'duration_minutes' => 20 ],
            ],
        ] );
        $this->assertSame( 200, $status );
        $this->assertCount( 3, $data['blocks'] );
        $this->assertSame( [ 0, 1, 2 ], array_column( $data['blocks'], 'order_index' ) );
        $this->assertSame( 54, $data['plan']['total_duration_minutes'] );

        [ $status, $data ] = $this->call( 'PUT', self::PLANS . '/' . $id . '/blocks', [ 'blocks' => [] ] );
        $this->assertSame( 200, $status );
        $this->assertSame( [], $data['blocks'] );
        $this->assertSame( 0, $data['plan']['total_duration_minutes'] );
    }

    public function test_replace_blocks_without_a_blocks_key_is_a_400(): void {
        $this->planner();
        [ , $data ] = $this->call( 'POST', self::PLANS, [ 'title' => 'Guard' ] );
        $id = (int) $data['plan']['id'];

        [ $status, $data ] = $this->call( 'PUT', self::PLANS . '/' . $id . '/blocks' );
        $this->assertSame( 400, $status );
        $this->assertSame( 'blocks_required', $data['error'] );
    }

    public function test_duplicate_as_template(): void {
        $this->planner();

        [ , $data ] = $this->call( 'POST', self::PLANS, [ 'title' => 'Bron', 'team_id' => 7 ] );
        $id = (int) $data['plan']['id'];

        [ $status, $data ] = $this->call( 'POST', self::PLANS . '/' . $id . '/duplicate', [
            'title'       => 'Standaard MD-3',
            'as_template' => true,
        ] );
        $this->assertSame( 201, $status );
        $this->assertSame( 'Standaard MD-3', $data['plan']['title'] );
        $this->assertTrue( $data['plan']['is_template'] );
        $this->assertNull( $data['plan']['team_id'] );
        $this->assertSame( 'duplicated', $data['plan']['source'] );
    }

    public function test_attach_a_run_and_record_what_happened(): void {
        $this->planner();

        [ , $data ] = $this->call( 'POST', self::PLANS, [ 'title' => 'Uitvoeren' ] );
        $plan_id = (int) $data['plan']['id'];
        $this->call( 'PUT', self::PLANS . '/' . $plan_id . '/blocks', [
            'blocks' => [
                [ 'block_type' => 'warmup', 'duration_minutes' => 12 ],
                [ 'block_type' => 'main',   'duration_minutes' => 22 ],
            ],
        ] );

        [ $status, $data ] = $this->call( 'POST', self::RUNS, [
            'plan_id'     => $plan_id,
            'activity_id' => 8801,
            'team_id'     => 7,
            'run_date'    => '2026-08-19',
        ] );
        $this->assertSame( 201, $status );
        $run_id = (int) $data['run']['id'];
        $this->assertSame( 'planned', $data['run']['status'] );
        $this->assertCount( 2, $data['run']['snapshot']['blocks'] );
        $this->assertCount( 2, $data['run']['blocks'] );

        // Re-attaching returns the existing run with a 200, not a duplicate.
        [ $status, $data ] = $this->call( 'POST', self::RUNS, [
            'plan_id'     => $plan_id,
            'activity_id' => 8801,
        ] );
        $this->assertSame( 200, $status );
        $this->assertSame( $run_id, (int) $data['run']['id'] );

        // Lifecycle.
        [ $status, $data ] = $this->call( 'PATCH', self::RUNS . '/' . $run_id, [ 'status' => 'completed' ] );
        $this->assertSame( 200, $status );
        $this->assertSame( 'completed', $data['run']['status'] );
        $this->assertNotEmpty( $data['run']['completed_at'] );

        // What actually happened, per block.
        $block_id = (int) $data['run']['blocks'][0]['id'];
        [ $status, $data ] = $this->call( 'PATCH', self::RUNS . '/' . $run_id . '/blocks/' . $block_id, [
            'actual_duration_minutes' => 17,
            'notes'                   => 'Liep uit.',
        ] );
        $this->assertSame( 200, $status );
        $this->assertSame( 17, $data['run']['blocks'][0]['actual_duration_minutes'] );
        $this->assertSame( 12, $data['run']['blocks'][0]['planned_duration_minutes'] );

        // The activity lookup finds it.
        [ $status, $data ] = $this->call( 'GET', '/talenttrack/v1/activities/8801/training-plan' );
        $this->assertSame( 200, $status );
        $this->assertSame( $run_id, (int) $data['run']['id'] );
    }

    public function test_activity_without_a_plan_returns_null_not_404(): void {
        $this->planner();

        [ $status, $data ] = $this->call( 'GET', '/talenttrack/v1/activities/7777/training-plan' );
        $this->assertSame( 200, $status, 'most activities have no plan — that is not an error' );
        $this->assertNull( $data['run'] );
    }

    public function test_invalid_run_status_is_rejected_with_the_allowed_set(): void {
        $this->planner();

        [ , $data ] = $this->call( 'POST', self::PLANS, [ 'title' => 'Status' ] );
        $plan_id = (int) $data['plan']['id'];
        [ , $data ] = $this->call( 'POST', self::RUNS, [ 'plan_id' => $plan_id, 'activity_id' => 8802 ] );
        $run_id = (int) $data['run']['id'];

        [ $status, $data ] = $this->call( 'PATCH', self::RUNS . '/' . $run_id, [ 'status' => 'nonsense' ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'invalid_status', $data['error'] );
        $this->assertContains( 'completed', $data['allowed'] );
    }

    public function test_a_block_from_another_run_cannot_be_written(): void {
        $this->planner();

        [ , $data ] = $this->call( 'POST', self::PLANS, [ 'title' => 'Isolatie' ] );
        $plan_id = (int) $data['plan']['id'];
        $this->call( 'PUT', self::PLANS . '/' . $plan_id . '/blocks', [
            'blocks' => [ [ 'block_type' => 'main', 'duration_minutes' => 20 ] ],
        ] );

        [ , $a ] = $this->call( 'POST', self::RUNS, [ 'plan_id' => $plan_id, 'activity_id' => 8803 ] );
        [ , $b ] = $this->call( 'POST', self::RUNS, [ 'plan_id' => $plan_id, 'activity_id' => 8804 ] );

        $foreign_block = (int) $b['run']['blocks'][0]['id'];

        [ $status, $data ] = $this->call(
            'PATCH',
            self::RUNS . '/' . (int) $a['run']['id'] . '/blocks/' . $foreign_block,
            [ 'was_skipped' => true ]
        );
        $this->assertSame( 404, $status, 'a run must not be able to write another run\'s block' );
        $this->assertSame( 'block_not_in_run', $data['error'] );
    }
}
