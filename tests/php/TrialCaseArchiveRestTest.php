<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3602 — archiving a trial case over REST archives it, and the list can be
 * narrowed to one player.
 *
 * `PUT trial-cases/{id}` with `status: archived` flipped the string and left
 * `archived_at` null, so the case stayed in the active list, which keys on
 * that column. Any other string was stored as a status too. `GET
 * trial-cases?player_id=N` returned every player's cases.
 */
final class TrialCaseArchiveRestTest extends WP_UnitTestCase {

    private int $track = 0;
    private int $mine = 0;
    private int $theirs = 0;
    private int $case = 0;
    private int $admin = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'slug' => 'std-' . uniqid(), 'name' => 'Standard' ] );
        $this->track = (int) $wpdb->insert_id;

        foreach ( [ 'mine', 'theirs' ] as $slot ) {
            $wpdb->insert( "{$p}tt_players", [ 'club_id' => $club, 'first_name' => 'Proef', 'last_name' => $slot, 'status' => 'trial' ] );
            $this->{$slot} = (int) $wpdb->insert_id;
        }

        $this->case = $this->insertCase( $this->mine );
        $this->insertCase( $this->theirs );

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_archiving_over_put_sets_archived_at_and_leaves_the_active_list(): void {
        [ , $status ] = $this->send( 'PUT', 'trial-cases/' . $this->case, [ 'status' => 'archived' ] );
        $this->assertSame( 200, $status );

        $row = $this->row( $this->case );
        $this->assertSame( 'archived', $row['status'] );
        $this->assertNotEmpty( $row['archived_at'] );
        $this->assertSame( $this->admin, (int) $row['archived_by'] );

        $this->assertNotContains( $this->case, $this->listIds( [] ) );
        $this->assertContains( $this->case, $this->listIds( [ 'include_archived' => 1 ] ) );
    }

    public function test_moving_an_archived_case_back_restores_it(): void {
        $this->send( 'PUT', 'trial-cases/' . $this->case, [ 'status' => 'archived' ] );
        [ , $status ] = $this->send( 'PUT', 'trial-cases/' . $this->case, [ 'status' => 'open' ] );
        $this->assertSame( 200, $status );

        $row = $this->row( $this->case );
        $this->assertSame( 'open', $row['status'] );
        $this->assertEmpty( $row['archived_at'] );
        $this->assertContains( $this->case, $this->listIds( [] ) );
    }

    public function test_an_unknown_status_is_refused_and_nothing_changes(): void {
        [ $data, $status ] = $this->send( 'PUT', 'trial-cases/' . $this->case, [ 'status' => 'paused' ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'bad_status', $data['errors'][0]['code'] ?? null );
        $this->assertSame( 'open', $this->row( $this->case )['status'] );
    }

    public function test_the_list_narrows_to_one_player(): void {
        $ids = $this->listIds( [ 'player_id' => $this->mine ] );
        $this->assertSame( [ $this->case ], $ids );

        $this->assertGreaterThanOrEqual( 2, count( $this->listIds( [] ) ), 'without player_id the list is unchanged' );
    }

    private function insertCase( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_trial_cases", [
            'club_id' => (int) CurrentClub::id(), 'player_id' => $player_id, 'track_id' => $this->track,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'open', 'created_by' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return array<string,mixed> */
    private function row( int $id ): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT status, archived_at, archived_by FROM {$wpdb->prefix}tt_trial_cases WHERE id = %d", $id
        ), ARRAY_A );
    }

    /**
     * @param array<string,mixed> $query
     * @return list<int>
     */
    private function listIds( array $query ): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/trial-cases' );
        $request->set_query_params( $query );
        $data = (array) rest_do_request( $request )->get_data();
        return array_values( array_map( static fn( $c ): int => (int) ( $c['id'] ?? 0 ), (array) ( $data['data']['cases'] ?? [] ) ) );
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
