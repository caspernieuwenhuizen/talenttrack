<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerDates;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3590 — a player's missing dates are NULL, in the database and over REST.
 *
 * An empty string written to the nullable DATE columns became `0000-00-00`,
 * which `?: null` let through as a date, and an unlinked player read back
 * `wp_user_id: 0` although the column holds NULL for "no account" (#1772).
 */
final class PlayerNullDatesTest extends WP_UnitTestCase {

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

    public function test_a_player_created_without_dates_stores_and_returns_null(): void {
        [ $created, $status ] = $this->send( 'POST', 'players', [ 'first_name' => 'Zonder', 'last_name' => 'Datum' ] );
        $this->assertSame( 200, $status );
        $id = (int) $created['data']['id'];

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT date_of_birth, date_joined, wp_user_id FROM {$wpdb->prefix}tt_players WHERE id = %d", $id
        ), ARRAY_A );
        $this->assertNull( $row['date_of_birth'] );
        $this->assertNull( $row['date_joined'] );

        [ $got ] = $this->send( 'GET', 'players/' . $id );
        $this->assertNull( $got['data']['date_of_birth'] );
        $this->assertNull( $got['data']['date_joined'] );
        $this->assertNull( $got['data']['wp_user_id'] );
    }

    public function test_a_real_date_round_trips(): void {
        [ $created ] = $this->send( 'POST', 'players', [
            'first_name' => 'Met', 'last_name' => 'Datum', 'date_of_birth' => '2014-05-02', 'date_joined' => '2023-08-01',
        ] );
        [ $got ] = $this->send( 'GET', 'players/' . (int) $created['data']['id'] );

        $this->assertSame( '2014-05-02', $got['data']['date_of_birth'] );
        $this->assertSame( '2023-08-01', $got['data']['date_joined'] );
    }

    public function test_a_malformed_date_is_refused(): void {
        [ $data, $status ] = $this->send( 'POST', 'players', [ 'first_name' => 'Fout', 'last_name' => 'Datum', 'date_of_birth' => '02-05-2014' ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'bad_date', $data['errors'][0]['code'] ?? null );
        $this->assertSame( 'date_of_birth', $data['errors'][0]['details']['field'] ?? null );

        [ $created ] = $this->send( 'POST', 'players', [ 'first_name' => 'Goed', 'last_name' => 'Datum' ] );
        [ , $status ] = $this->send( 'PUT', 'players/' . (int) $created['data']['id'], [ 'date_joined' => '2023-02-30' ] );
        $this->assertSame( 400, $status, 'not a calendar date' );
    }

    public function test_a_blank_date_on_update_clears_it(): void {
        [ $created ] = $this->send( 'POST', 'players', [ 'first_name' => 'Wis', 'last_name' => 'Datum', 'date_of_birth' => '2014-05-02' ] );
        $id = (int) $created['data']['id'];

        [ $got, $status ] = $this->send( 'PUT', 'players/' . $id, [ 'date_of_birth' => '' ] );
        $this->assertSame( 200, $status );
        $this->assertNull( $got['data']['date_of_birth'] );
    }

    public function test_a_stored_zero_date_reads_as_null_and_the_migration_clears_it(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Oud', 'last_name' => 'Record', 'status' => 'active',
        ] );
        $id = (int) $wpdb->insert_id;

        // What an older write left: the zero date, which only a lenient SQL
        // mode accepts. The session mode is put back afterwards.
        $mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
        $wpdb->query( "SET SESSION sql_mode = ''" );
        try {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}tt_players SET date_of_birth = '0000-00-00', date_joined = '0000-00-00' WHERE id = %d", $id
            ) );
        } finally {
            $wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $mode ) );
        }
        $this->assertSame( '0000-00-00', (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT CAST(date_of_birth AS CHAR) FROM {$wpdb->prefix}tt_players WHERE id = %d", $id
        ) ), 'precondition: the zero date is stored' );

        [ $got ] = $this->send( 'GET', 'players/' . $id );
        $this->assertNull( $got['data']['date_of_birth'] );
        $this->assertNull( $got['data']['date_joined'] );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0271_null_zero_player_dates.php';
        $migration->up();
        $migration->up();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT date_of_birth, date_joined FROM {$wpdb->prefix}tt_players WHERE id = %d", $id
        ), ARRAY_A );
        $this->assertNull( $row['date_of_birth'] );
        $this->assertNull( $row['date_joined'] );
    }

    public function test_the_helper(): void {
        $this->assertNull( PlayerDates::fromInput( '' ) );
        $this->assertNull( PlayerDates::fromInput( '  ' ) );
        $this->assertNull( PlayerDates::fromInput( '0000-00-00' ) );
        $this->assertNull( PlayerDates::fromInput( null ) );
        $this->assertSame( '2014-05-02', PlayerDates::fromInput( ' 2014-05-02 ' ) );
        $this->assertTrue( PlayerDates::isValid( null ) );
        $this->assertFalse( PlayerDates::isValid( '2014-02-30' ) );
        $this->assertNull( PlayerDates::forOutput( '0000-00-00' ) );
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body = [] ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $body ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
