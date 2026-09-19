<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;

/**
 * #3694 — an observation can only be written about a player who was at
 * the training.
 *
 * The sideline sheet only ever offered the players marked present or
 * late, but `POST /training/runs/{id}/observations` took any player id.
 * A typo'd id, or a client that did not know the rule, put a development
 * note on a child who was not there, including one from another team,
 * and it then read as evidence on their record.
 *
 * The rule now lives in `TrainingPlanRunsRepository::squadForRun()`, and
 * both the sheet and the REST write read it from there.
 */
final class TrainingRunObservationSquadTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- fixtures ---------------------------------------------------------

    /** @return array{run_id:int, activity_id:int} */
    private function makeRun(): array {
        $plan_id = ( new TrainingPlansRepository() )->create( [
            'club_id' => 1, 'team_id' => 7, 'title' => 'Omschakelen',
        ] );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id' => 1, 'team_id' => 7,
            'session_date' => '2026-09-15', 'activity_type_key' => 'training',
        ] );
        $activity_id = (int) $wpdb->insert_id;

        $run_id = ( new TrainingPlanRunsRepository() )->attach(
            (int) $plan_id,
            $activity_id,
            7,
            '2026-09-15'
        );

        return [ 'run_id' => $run_id, 'activity_id' => $activity_id ];
    }

    private function makePlayer( string $first, string $last, int $team_id = 7 ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => $last,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function register( int $activity_id, int $player_id, string $status, string $record_type = 'actual' ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'     => 1,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'record_type' => $record_type,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function registerGuest( int $activity_id, int $player_id ): void {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'         => 1,
            'activity_id'     => $activity_id,
            'is_guest'        => 1,
            'guest_player_id' => $player_id,
            'status'          => 'present',
            'record_type'     => 'actual',
        ] );
    }

    /** @return array{0:int, 1:mixed} */
    private function post( int $run_id, array $body ): array {
        $request = new WP_REST_Request( 'POST', self::BASE . "/training/runs/{$run_id}/observations" );
        foreach ( $body as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $response = rest_get_server()->dispatch( $request );

        return [ $response->get_status(), $response->get_data() ];
    }

    private function countFor( int $player_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_training_observations WHERE player_id = %d",
            $player_id
        ) );
    }

    private function assertRefused( int $run_id, int $player_id, string $why ): void {
        [ $status, $data ] = $this->post( $run_id, [ 'player_id' => $player_id, 'note' => 'Was er niet.' ] );

        $this->assertSame( 400, $status, $why );
        $this->assertSame( 'player_not_in_squad', $data['errors'][0]['code'] ?? null, $why );
        $this->assertSame( 0, $this->countFor( $player_id ), $why . ' — and nothing was written' );
    }

    // ---- who may be observed ----------------------------------------------

    public function test_a_player_marked_present_can_be_observed(): void {
        $run    = $this->makeRun();
        $player = $this->makePlayer( 'Sem', 'Bakker' );
        $this->register( $run['activity_id'], $player, 'present' );

        [ $status, $data ] = $this->post( $run['run_id'], [ 'player_id' => $player, 'note' => 'Goed gescand.' ] );

        $this->assertSame( 201, $status );
        $this->assertGreaterThan( 0, (int) $data['data']['observation_id'] );
        $this->assertSame( 1, $this->countFor( $player ) );
    }

    public function test_a_player_marked_late_can_be_observed(): void {
        $run    = $this->makeRun();
        $player = $this->makePlayer( 'Daan', 'Visser' );
        $this->register( $run['activity_id'], $player, 'late' );

        [ $status ] = $this->post( $run['run_id'], [ 'player_id' => $player, 'note' => 'Kwam later, deed mee.' ] );

        $this->assertSame( 201, $status );
    }

    public function test_a_guest_player_marked_present_can_be_observed(): void {
        $run   = $this->makeRun();
        $guest = $this->makePlayer( 'Noah', 'Smit', 9 );
        $this->registerGuest( $run['activity_id'], $guest );

        [ $status ] = $this->post( $run['run_id'], [ 'player_id' => $guest, 'note' => 'Meegetraind als gast.' ] );

        $this->assertSame( 201, $status, 'a linked guest is on the pitch and on the sheet' );
    }

    public function test_a_player_with_no_register_row_is_refused(): void {
        $run    = $this->makeRun();
        $player = $this->makePlayer( 'Liam', 'de Vries' );

        $this->assertRefused( $run['run_id'], $player, 'no register row' );
    }

    public function test_a_player_marked_absent_is_refused(): void {
        $run    = $this->makeRun();
        $player = $this->makePlayer( 'Finn', 'Jansen' );
        $this->register( $run['activity_id'], $player, 'absent' );

        $this->assertRefused( $run['run_id'], $player, 'marked absent' );
    }

    public function test_a_player_from_another_team_is_refused(): void {
        $run    = $this->makeRun();
        $player = $this->makePlayer( 'Lucas', 'Mulder', 9 );

        $this->assertRefused( $run['run_id'], $player, 'another team, never at this training' );
    }

    public function test_a_planned_squad_row_is_not_attendance(): void {
        $run    = $this->makeRun();
        $player = $this->makePlayer( 'Milan', 'de Boer' );
        $this->register( $run['activity_id'], $player, 'present', 'expected' );

        $this->assertRefused( $run['run_id'], $player, 'an expected row is a plan, not a register' );
    }

    public function test_a_replay_still_answers_after_the_register_changes(): void {
        global $wpdb;

        $run    = $this->makeRun();
        $player = $this->makePlayer( 'Levi', 'Meijer' );
        $row    = $this->register( $run['activity_id'], $player, 'present' );

        $body = [ 'player_id' => $player, 'note' => 'Sterk in de duels.', 'client_uuid' => wp_generate_uuid4() ];

        [ $first_status, $first ] = $this->post( $run['run_id'], $body );
        $this->assertSame( 201, $first_status );

        // The register is corrected after the phone saved, but before the
        // offline queue heard back. The replay must not become a 400 that
        // makes the queue drop a write the server already has.
        $wpdb->update( $wpdb->prefix . 'tt_attendance', [ 'status' => 'absent' ], [ 'id' => $row ] );

        [ $again_status, $again ] = $this->post( $run['run_id'], $body );

        $this->assertSame( 200, $again_status );
        $this->assertTrue( $again['data']['replayed'] );
        $this->assertSame( $first['data']['observation_id'], $again['data']['observation_id'] );
        $this->assertSame( 1, $this->countFor( $player ) );
    }

    // ---- the sheet reads the same list ------------------------------------

    public function test_the_squad_is_present_and_late_players_sorted_by_name(): void {
        $run = $this->makeRun();

        $zoe    = $this->makePlayer( 'Zoe', 'Van Dijk' );
        $anna   = $this->makePlayer( 'Anna', 'Bos' );
        $absent = $this->makePlayer( 'Tim', 'Absent' );
        $guest  = $this->makePlayer( 'Gijs', 'Kok', 9 );

        $this->register( $run['activity_id'], $zoe, 'present' );
        $this->register( $run['activity_id'], $anna, 'late' );
        $this->register( $run['activity_id'], $absent, 'absent' );
        $this->registerGuest( $run['activity_id'], $guest );

        $squad = ( new TrainingPlanRunsRepository() )->squadForRun( $run['run_id'] );

        $this->assertSame(
            [
                [ 'id' => $anna, 'name' => 'Anna Bos' ],
                [ 'id' => $guest, 'name' => 'Gijs Kok' ],
                [ 'id' => $zoe, 'name' => 'Zoe Van Dijk' ],
            ],
            $squad
        );
    }

    public function test_an_unknown_run_has_no_squad(): void {
        $this->assertSame( [], ( new TrainingPlanRunsRepository() )->squadForRun( 999999 ) );
    }
}
