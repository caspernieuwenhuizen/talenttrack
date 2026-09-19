<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3571 — a REST client can find the account to link as a parent, link it,
 * and see the result.
 *
 * `POST players/{id}/parents` took a WordPress user id that no route could
 * look up, declared no arguments, answered a missing id with a message
 * naming no field, and nothing on the REST side showed which parents were
 * linked — so an admin re-linking got "noop" and could not tell it meant
 * "already done".
 */
final class ParentAccountRoutesTest extends WP_UnitTestCase {

    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Bas', 'last_name' => 'Willems', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_link_route_declares_its_args(): void {
        $routes  = rest_get_server()->get_routes();
        $handler = null;
        foreach ( $routes['/talenttrack/v1/players/(?P<id>\d+)/parents'] ?? [] as $h ) {
            if ( ! empty( $h['methods']['POST'] ) ) $handler = $h;
        }
        $this->assertNotNull( $handler );
        foreach ( [ 'wp_user_id', 'create', 'first_name', 'last_name', 'email', 'temp_password' ] as $arg ) {
            $this->assertArrayHasKey( $arg, $handler['args'], "{$arg} is not declared" );
        }
    }

    public function test_a_missing_account_names_the_field(): void {
        [ $data, $status ] = $this->send( 'POST', 'players/' . $this->player . '/parents', [] );

        $this->assertSame( 422, $status );
        $this->assertSame( 'wp_user_id', (string) ( ( (array) ( $data['errors'][0]['details'] ?? [] ) )['field'] ?? '' ) );
    }

    public function test_find_link_and_read_back(): void {
        $linda = self::factory()->user->create( [
            'role' => 'subscriber', 'display_name' => 'Linda Willems', 'user_email' => 'linda.willems@example.test',
        ] );

        [ $found, $status ] = $this->send( 'GET', 'parent-accounts/eligible?search=' . rawurlencode( 'linda.willems@' ) );
        $this->assertSame( 200, $status );
        $this->assertContains( $linda, array_column( $found['data']['accounts'], 'id' ) );

        [ $linked, $status ] = $this->send( 'POST', 'players/' . $this->player . '/parents', [ 'wp_user_id' => $linda ] );
        $this->assertSame( 200, $status );
        $this->assertSame( 'linked', $linked['data']['status'] );

        [ $list ] = $this->send( 'GET', 'players/' . $this->player . '/parents' );
        $this->assertSame( [ $linda ], array_column( $list['data']['parents'], 'wp_user_id' ) );
        $this->assertTrue( $list['data']['parents'][0]['is_primary'] );

        [ $again, $status ] = $this->send( 'POST', 'players/' . $this->player . '/parents', [ 'wp_user_id' => $linda ] );
        $this->assertSame( 200, $status );
        $this->assertSame( 'already_linked', $again['data']['status'] );
        $this->assertNotSame( '', (string) $again['data']['message'] );
    }

    public function test_the_lookup_needs_a_real_search_and_leaves_players_and_staff_out(): void {
        $this->assertSame( 400, $this->send( 'GET', 'parent-accounts/eligible?search=a' )[1] );
        $this->assertSame( 400, $this->send( 'GET', 'parent-accounts/eligible' )[1] );

        global $wpdb;
        $as_player = self::factory()->user->create( [ 'display_name' => 'Zeldzaam Speler' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => 1, 'first_name' => 'Own', 'last_name' => 'Player', 'status' => 'active', 'wp_user_id' => $as_player,
        ] );
        $as_staff = self::factory()->user->create( [ 'display_name' => 'Zeldzaam Staf' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => 1, 'first_name' => 'Staff', 'last_name' => 'Person', 'role_type' => 'staff', 'wp_user_id' => $as_staff, 'status' => 'active',
        ] );
        for ( $i = 0; $i < 25; $i++ ) {
            self::factory()->user->create( [ 'display_name' => 'Zeldzaam Ouder ' . $i ] );
        }

        [ $found ] = $this->send( 'GET', 'parent-accounts/eligible?search=Zeldzaam' );
        $ids = array_column( $found['data']['accounts'], 'id' );

        $this->assertLessThanOrEqual( 20, count( $ids ) );
        $this->assertNotContains( $as_player, $ids );
        $this->assertNotContains( $as_staff, $ids );
    }

    public function test_a_coach_or_a_parent_gets_neither_read_route(): void {
        foreach ( [ 'tt_coach', 'tt_parent' ] as $role ) {
            wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
            $this->assertSame( 403, $this->send( 'GET', 'players/' . $this->player . '/parents' )[1], "{$role} on the list" );
            $this->assertSame( 403, $this->send( 'GET', 'parent-accounts/eligible?search=Linda' )[1], "{$role} on the lookup" );
        }
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body = [] ): array {
        $path  = '/talenttrack/v1/' . $route;
        $query = [];
        if ( strpos( $path, '?' ) !== false ) {
            [ $path, $qs ] = explode( '?', $path, 2 );
            parse_str( $qs, $query );
        }
        $request = new WP_REST_Request( $method, $path );
        if ( $query ) $request->set_query_params( $query );
        if ( $body ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_do_request( $request );
        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }
}
