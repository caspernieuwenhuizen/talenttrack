<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3744 — creating an activity as "completed" seeded the whole roster as
 * present without looking at the date, so an activity eleven weeks away
 * read as a fully attended session that nobody had ever registered.
 *
 * The #1636 seed still runs for an activity dated today or earlier (that is
 * what makes a just-played game rateable); it declines a date in the future,
 * where the same claim is refused from every user-supplied write path.
 */
final class CompletedActivityFutureSeedTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;

    /** @var list<int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'O13-1' ] );
        $this->team = (int) $wpdb->insert_id;

        foreach ( [ 'Bram', 'Sanne', 'Tess' ] as $name ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id'    => $this->club,
                'team_id'    => $this->team,
                'first_name' => $name,
                'last_name'  => 'De Wit',
                'status'     => 'active',
            ] );
            $this->players[] = (int) $wpdb->insert_id;
        }

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_completed_activity_in_the_future_is_created_with_an_empty_register(): void {
        $activity_id = $this->createCompleted( $this->offsetDate( '+30 days' ) );

        $this->assertGreaterThan( 0, $activity_id, 'the activity itself is still created' );
        $this->assertSame( 0, $this->countAttendance( $activity_id ) );

        [ $data ] = $this->send( 'GET', 'activities?filter[team_id]=' . $this->team );
        $row = $this->rowFor( $data, $activity_id );
        $this->assertSame( 0, (int) ( $row['attendance_count'] ?? -1 ) );
        $this->assertSame( 0, (int) ( $row['present_count'] ?? -1 ) );

        [ $grid ] = $this->send( 'GET', 'activities/attendance-grid?team_id=' . $this->team );
        $this->assertNotContains(
            $activity_id,
            array_column( $grid['data']['activities'] ?? [], 'activity_id' ),
            'a register nobody took is not a column on the grid'
        );
    }

    public function test_a_completed_activity_dated_yesterday_still_seeds_the_roster(): void {
        $activity_id = $this->createCompleted( $this->offsetDate( '-1 day' ) );

        $this->assertSame(
            count( $this->players ),
            $this->countAttendance( $activity_id ),
            '#1636 — a just-played activity is rateable straight away'
        );
    }

    private function offsetDate( string $offset ): string {
        return gmdate( 'Y-m-d', (int) strtotime( current_time( 'Y-m-d' ) . ' ' . $offset ) );
    }

    private function createCompleted( string $date ): int {
        [ $data, $status ] = $this->send( 'POST', 'activities', [
            'title'               => 'Friendly ' . $date,
            'session_date'        => $date,
            'team_id'             => $this->team,
            'activity_type_key'   => 'game',
            'activity_status_key' => 'completed',
        ] );
        $this->assertSame( 200, $status );
        return (int) ( $data['data']['id'] ?? 0 );
    }

    private function countAttendance( int $activity_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id = %d AND record_type = 'actual' AND is_guest = 0",
            $activity_id
        ) );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function rowFor( array $payload, int $activity_id ): array {
        $rows = $payload['data']['rows'] ?? $payload['data'] ?? [];
        foreach ( (array) $rows as $row ) {
            if ( (int) ( $row['id'] ?? 0 ) === $activity_id ) return (array) $row;
        }
        $this->fail( 'activity ' . $activity_id . ' was not in the list response' );
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
