<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Training\Repositories\TrainingObservationsRepository;
use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;

/**
 * #2552 — the offline write queue's server-side half.
 *
 * The queue can only promise at-least-once delivery: a request whose
 * response is lost in transit succeeded on the server and looks failed
 * on the phone, so it comes back. Everything it carries therefore has to
 * be safe to repeat, and these tests are about that rather than about
 * IndexedDB.
 *
 * It matters more than a duplicate row usually would. The run and its
 * blocks are what wave 7 computes per-player exposure from, so a
 * double-applied write becomes a wrong number on a child's development
 * record that a coach will read and believe.
 */
final class TrainingOfflineQueueTest extends WP_UnitTestCase {

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

    private function coach(): int {
        $id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $id );

        return $id;
    }

    private function makePlayer(): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'team_id' => 7, 'first_name' => 'Sem', 'last_name' => 'Bakker',
        ] );

        return (int) $wpdb->insert_id;
    }

    private function makeRun(): int {
        $plan_id = ( new TrainingPlansRepository() )->create( [
            'club_id' => 1, 'team_id' => 7, 'title' => 'Opbouw',
        ] );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id' => 1, 'team_id' => 7,
            'session_date' => '2026-08-23', 'activity_type_key' => 'training',
        ] );

        return ( new TrainingPlanRunsRepository() )->attach(
            (int) $plan_id,
            (int) $wpdb->insert_id,
            7,
            '2026-08-23'
        );
    }

    private function post( int $run_id, array $body ): array {
        $request = new WP_REST_Request( 'POST', self::BASE . "/training/runs/{$run_id}/observations" );
        foreach ( $body as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $response = rest_get_server()->dispatch( $request );

        return [ $response->get_status(), $response->get_data() ];
    }

    private function countObservations(): int {
        global $wpdb;

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_training_observations" );
    }

    // ---- the promise ------------------------------------------------------

    public function test_replaying_an_observation_does_not_record_it_twice(): void {
        $this->coach();
        $run_id = $this->makeRun();
        $player = $this->makePlayer();

        $body = [
            'player_id'   => $player,
            'note'        => 'Kwam goed uit de druk.',
            'client_uuid' => wp_generate_uuid4(),
        ];

        $before = $this->countObservations();

        [ $first_status, $first ] = $this->post( $run_id, $body );
        $this->assertSame( 201, $first_status );
        $this->assertFalse( $first['data']['replayed'] );

        // The phone never saw the response, so it sends again. Three
        // times, because a coach in a car park may reconnect and drop
        // more than once.
        [ $again_status, $again ] = $this->post( $run_id, $body );
        $this->post( $run_id, $body );
        [ , $last ] = $this->post( $run_id, $body );

        $this->assertSame( 1, $this->countObservations() - $before, 'the coach observed once' );
        $this->assertSame( $first['data']['observation_id'], $last['data']['observation_id'] );

        // 200-not-201 so a client can tell a replay from a first save,
        // matching what POST /training/runs already does on re-attach.
        $this->assertSame( 200, $again_status );
        $this->assertTrue( $again['data']['replayed'] );
    }

    public function test_a_genuinely_different_observation_still_lands(): void {
        $this->coach();
        $run_id = $this->makeRun();
        $player = $this->makePlayer();

        $before = $this->countObservations();

        $this->post( $run_id, [
            'player_id' => $player, 'note' => 'Eerste.', 'client_uuid' => wp_generate_uuid4(),
        ] );
        [ $status ] = $this->post( $run_id, [
            'player_id' => $player, 'note' => 'Tweede.', 'client_uuid' => wp_generate_uuid4(),
        ] );

        $this->assertSame( 201, $status );
        $this->assertSame( 2, $this->countObservations() - $before );
    }

    /**
     * The key goes into a UNIQUE column, so accepting arbitrary text
     * would let a caller collide with a row it does not own, or squat on
     * a key nobody else could then use.
     */
    public function test_a_key_that_is_not_a_uuid_is_ignored_rather_than_trusted(): void {
        global $wpdb;

        $this->coach();
        $run_id = $this->makeRun();
        $player = $this->makePlayer();

        foreach ( [ "' OR 1=1 --", 'not-a-uuid', '', str_repeat( 'a', 200 ) ] as $junk ) {
            [ $status ] = $this->post( $run_id, [
                'player_id'   => $player,
                'note'        => 'Notitie.',
                'client_uuid' => $junk,
            ] );

            $this->assertSame( 201, $status, 'a bad key must not break the write' );

            $stored = (string) $wpdb->get_var(
                "SELECT uuid FROM {$wpdb->prefix}tt_training_observations ORDER BY id DESC LIMIT 1"
            );
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $stored,
                'the server generated its own uuid rather than storing what was sent'
            );
        }
    }

    public function test_an_unseen_key_reports_itself_as_not_replayed(): void {
        $this->coach();
        $repo = new TrainingObservationsRepository();

        $this->assertNull( $repo->findByUuid( wp_generate_uuid4() ) );
        $this->assertNull( $repo->findByUuid( 'not-a-uuid' ) );
    }

    /**
     * The other three writes the sideline makes are PATCHes that set
     * absolute values, so replaying one lands in the same state. This
     * pins that: if someone ever makes a block write relative — adding
     * minutes rather than setting them — the queue silently starts
     * corrupting durations, and nothing else would catch it.
     */
    public function test_replaying_a_block_write_leaves_the_same_state(): void {
        $this->coach();
        $run_id = $this->makeRun();

        $runs   = new TrainingPlanRunsRepository();
        $blocks = $runs->listBlocks( $run_id );

        if ( $blocks === [] ) {
            $this->markTestSkipped( 'the seeded plan has no blocks to write to' );
        }

        $block_id = (int) $blocks[0]->id;

        $patch = function () use ( $run_id, $block_id ) {
            $request = new WP_REST_Request(
                'PATCH',
                self::BASE . "/training/runs/{$run_id}/blocks/{$block_id}"
            );
            $request->set_param( 'actual_duration_minutes', 17 );
            rest_get_server()->dispatch( $request );
        };

        $patch();
        $patch();
        $patch();

        $after = $runs->listBlocks( $run_id );
        $this->assertSame(
            17,
            (int) $after[0]->actual_duration_minutes,
            'three identical writes must leave 17 minutes, not 51'
        );
    }
}
