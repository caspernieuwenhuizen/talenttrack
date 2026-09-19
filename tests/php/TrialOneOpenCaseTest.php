<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Rest\TrialsRestController;
use TT\Modules\Trials\Services\TrialCaseOpener;

/**
 * #3577 — one open trial per player.
 *
 * Nothing stopped a second case being opened for a player whose first was
 * still open, so the decision, the staff inputs and the journey entry could
 * split across two records. The guard lives in the repository, so every
 * caller meets it; the REST route now goes through `TrialCaseOpener` like
 * the form and the wizard, which also gives it the cross-club check and
 * the status flip it was missing.
 */
final class TrialOneOpenCaseTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $track = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        ( new RolesService() )->installRoles();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $wpdb->insert( "{$this->p}tt_trial_tracks", [ 'club_id' => $this->club, 'name' => 'Standard' ] );
        $this->track = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_second_open_case_is_refused_with_the_open_ones_id(): void {
        $player = $this->player();

        $first = $this->createCase( $player );
        $this->assertSame( 200, $first->get_status() );
        $first_id = (int) $first->get_data()['data']['case']['id'];

        $second = $this->createCase( $player );
        $this->assertSame( 409, $second->get_status() );
        $error = $second->get_data()['errors'][0] ?? [];
        $this->assertSame( TrialCaseOpener::ERROR_ALREADY_OPEN, $error['code'] ?? null );
        $this->assertSame( $first_id, (int) ( ( (array) ( $error['details'] ?? [] ) )['existing_case_id'] ?? 0 ) );

        $this->assertSame( 1, $this->caseCount( $player ), 'nothing was inserted' );
    }

    public function test_an_extended_case_blocks_too(): void {
        global $wpdb;
        $player = $this->player();
        $first  = (int) $this->createCase( $player )->get_data()['data']['case']['id'];
        $wpdb->update( "{$this->p}tt_trial_cases", [ 'status' => 'extended' ], [ 'id' => $first ] );

        $this->assertSame( 409, $this->createCase( $player )->get_status() );
    }

    public function test_once_the_first_is_decided_a_new_case_opens(): void {
        $player = $this->player();
        $first  = (int) $this->createCase( $player )->get_data()['data']['case']['id'];

        ( new TrialCasesRepository() )->recordDecision( $first, 'admit', get_current_user_id(), 'Consistent across the whole trial period, offered a place.' );

        $this->assertSame( 200, $this->createCase( $player )->get_status() );
        $this->assertSame( 2, $this->caseCount( $player ) );
    }

    public function test_the_rest_route_flips_the_player_to_trial_and_refuses_another_clubs_player(): void {
        global $wpdb;
        $player = $this->player( 'active' );

        $this->assertSame( 200, $this->createCase( $player )->get_status() );
        $this->assertSame( 'trial', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$this->p}tt_players WHERE id = %d", $player ) ) );

        $foreign = $this->player( 'active', 999 );
        $res     = $this->createCase( $foreign );
        $this->assertGreaterThanOrEqual( 400, $res->get_status() );
        $this->assertSame( 0, $this->caseCount( $foreign ) );
    }

    public function test_list_and_detail_name_the_player(): void {
        $player = $this->player( 'active', 0, 'Bas', 'Willems' );
        $id     = (int) $this->createCase( $player )->get_data()['data']['case']['id'];

        $list = TrialsRestController::list_cases( new WP_REST_Request( 'GET', '/talenttrack/v1/trial-cases' ) );
        $row  = array_values( array_filter(
            (array) $list->get_data()['data']['cases'],
            static fn( $c ): bool => (int) $c['id'] === $id
        ) );
        $this->assertSame( 'Bas Willems', $row[0]['player_name'] ?? null );

        $get = new WP_REST_Request( 'GET', '/talenttrack/v1/trial-cases/' . $id );
        $get->set_param( 'id', $id );
        $this->assertSame( 'Bas Willems', TrialsRestController::get_case( $get )->get_data()['data']['case']['player_name'] ?? null );
    }

    private function createCase( int $player ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/trial-cases' );
        $req->set_header( 'content-type', 'application/json' );
        $req->set_body( (string) wp_json_encode( [
            'player_id'  => $player,
            'track_id'   => $this->track,
            'start_date' => '2026-09-14',
            'end_date'   => '2026-10-14',
        ] ) );
        return TrialsRestController::create_case( $req );
    }

    private function player( string $status = 'active', int $club = 0, string $first = 'Trial', string $last = 'Player' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $club > 0 ? $club : $this->club,
            'first_name' => $first,
            'last_name'  => $last,
            'status'     => $status,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function caseCount( int $player ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_trial_cases WHERE player_id = %d",
            $player
        ) );
    }
}
