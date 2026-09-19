<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3601 — `GET teams/{id}` carries the squad size and upcoming count the
 * list does.
 *
 * The detail query selected no player count and passed no upcoming count,
 * so it answered `player_count: null, upcoming_count: 0` beside a list row
 * of 16 and 6, and its card was built from the same nothing.
 */
final class TeamDetailCountsTest extends WP_UnitTestCase {

    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Telling O12' ] );
        $this->team = (int) $wpdb->insert_id;

        for ( $i = 1; $i <= 3; $i++ ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => $club, 'team_id' => $this->team, 'first_name' => 'Speler', 'last_name' => (string) $i, 'status' => 'active',
            ] );
        }
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $this->team, 'first_name' => 'Oud', 'last_name' => 'Lid',
            'status' => 'active', 'archived_at' => current_time( 'mysql' ),
        ] );

        foreach ( [ '+2 days', '+9 days', '+30 days' ] as $when ) {
            $wpdb->insert( "{$p}tt_activities", [
                'club_id' => $club, 'team_id' => $this->team, 'title' => 'Training', 'session_date' => gmdate( 'Y-m-d', strtotime( $when ) ),
                'activity_type_key' => 'training', 'activity_status_key' => 'planned', 'plan_state' => 'scheduled',
            ] );
        }

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_detail_counts_match_the_list(): void {
        $detail = $this->get( 'teams/' . $this->team, [] )['data'];

        $list_row = null;
        foreach ( $this->get( 'teams', [ 'per_page' => 100, 'search' => 'Telling' ] )['data']['rows'] as $row ) {
            if ( (int) $row['id'] === $this->team ) $list_row = $row;
        }
        $this->assertNotNull( $list_row );

        $this->assertSame( 3, $detail['player_count'], 'active players only' );
        $this->assertSame( 2, $detail['upcoming_count'], 'the next fourteen days' );
        $this->assertSame( $list_row['player_count'], $detail['player_count'] );
        $this->assertSame( $list_row['upcoming_count'], $detail['upcoming_count'] );
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function get( string $route, array $query ): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/' . $route );
        if ( $query ) $request->set_query_params( $query );
        $response = rest_do_request( $request );
        $this->assertSame( 200, $response->get_status() );
        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return is_array( $data ) ? $data : [];
    }
}
