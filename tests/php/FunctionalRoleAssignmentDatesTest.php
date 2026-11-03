<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #4905 — a team assignment's start and end dates decide which teams its
 * holder works with today, so each is empty (stored as null) or a real
 * calendar date as `Y-m-d`. Anything else is `400 invalid_field` naming the
 * field, and nothing is written.
 */
final class FunctionalRoleAssignmentDatesTest extends WP_UnitTestCase {

    private const INVALID = [ '2026-02-30', '31-12-2026', '2026-9-1', 'tomorrow', '0000-00-00' ];

    private string $p    = '';
    private int $club    = 0;
    private int $team    = 0;
    private int $role    = 0;
    private int $role_b  = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO12-1' ] );
        $this->team = (int) $wpdb->insert_id;
        foreach ( [ 'role' => 'staf_head_coach_4905', 'role_b' => 'staf_assistant_4905' ] as $slot => $key ) {
            $wpdb->insert( "{$this->p}tt_functional_roles", [ 'club_id' => $this->club, 'role_key' => $key, 'label' => $key ] );
            $this->{$slot} = (int) $wpdb->insert_id;
        }

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_create_stores_a_real_date_and_null_for_empty(): void {
        $dated = $this->person( 'Dated' );
        $this->assertSame( 200, $this->create( $dated, [ 'start_date' => '2026-09-01', 'end_date' => '2027-06-30' ] )->get_status() );
        $row = $this->row( $dated );
        $this->assertSame( '2026-09-01', $row['start_date'] );
        $this->assertSame( '2027-06-30', $row['end_date'] );

        $open = $this->person( 'Open' );
        $this->assertSame( 200, $this->create( $open, [ 'start_date' => '', 'end_date' => '' ] )->get_status() );
        $row = $this->row( $open );
        $this->assertNull( $row['start_date'] );
        $this->assertNull( $row['end_date'] );

        $absent = $this->person( 'Absent' );
        $this->assertSame( 200, $this->create( $absent, [] )->get_status() );
        $this->assertNull( $this->row( $absent )['start_date'] );
    }

    public function test_create_refuses_anything_else_and_writes_nothing(): void {
        foreach ( [ 'start_date', 'end_date' ] as $field ) {
            foreach ( self::INVALID as $value ) {
                $person   = $this->person( $field . ' ' . $value );
                $response = $this->create( $person, [ $field => $value ] );
                $this->assertRefused( $response, $field, $value );
                $this->assertNull( $this->row( $person ), "no row for {$field} = {$value}" );
            }
        }
    }

    public function test_update_stores_a_real_date_and_null_for_empty(): void {
        $person = $this->person( 'Update' );
        $this->assertSame( 200, $this->create( $person, [ 'start_date' => '2026-09-01' ] )->get_status() );
        $id = (int) $this->row( $person )['id'];

        $this->assertSame( 200, $this->update( $id, [ 'start_date' => '2026-10-01', 'end_date' => '2027-05-31' ] )->get_status() );
        $row = $this->row( $person );
        $this->assertSame( '2026-10-01', $row['start_date'] );
        $this->assertSame( '2027-05-31', $row['end_date'] );

        $this->assertSame( 200, $this->update( $id, [ 'start_date' => '', 'end_date' => '' ] )->get_status() );
        $row = $this->row( $person );
        $this->assertNull( $row['start_date'] );
        $this->assertNull( $row['end_date'] );
    }

    public function test_update_refuses_anything_else_and_leaves_the_row_alone(): void {
        $person = $this->person( 'Kept' );
        $this->assertSame( 200, $this->create( $person, [ 'start_date' => '2026-09-01', 'end_date' => '2027-06-30' ] )->get_status() );
        $before = $this->row( $person );
        $id     = (int) $before['id'];

        foreach ( [ 'start_date', 'end_date' ] as $field ) {
            foreach ( self::INVALID as $value ) {
                // A role change rides along: it must not be written either.
                $this->assertRefused( $this->update( $id, [ $field => $value, 'functional_role_id' => $this->role_b ] ), $field, $value );
                $this->assertSame( $before, $this->row( $person ), "row unchanged for {$field} = {$value}" );
            }
        }
    }

    // ---- fixtures ------------------------------------------------------

    private function assertRefused( WP_REST_Response $response, string $field, string $value ): void {
        $this->assertSame( 400, $response->get_status(), "{$field} = {$value}" );
        $data  = (array) json_decode( (string) wp_json_encode( $response->get_data() ), true );
        $error = (array) ( $data['errors'][0] ?? [] );
        $this->assertSame( 'invalid_field', $error['code'] ?? null, "{$field} = {$value}" );
        $this->assertSame( $field, $error['details']['field'] ?? null, "{$field} = {$value}" );
    }

    private function person( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_people", [ 'club_id' => $this->club, 'first_name' => $name, 'last_name' => 'Staf' ] );
        return (int) $wpdb->insert_id;
    }

    /** @return array<string, mixed>|null */
    private function row( int $person_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, functional_role_id, start_date, end_date FROM {$this->p}tt_team_people WHERE person_id = %d AND team_id = %d",
            $person_id, $this->team
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** @param array<string, mixed> $dates */
    private function create( int $person_id, array $dates ): WP_REST_Response {
        return $this->send( 'POST', '/talenttrack/v1/functional-roles/assignments', array_merge( [
            'team_id'            => $this->team,
            'person_id'          => $person_id,
            'functional_role_id' => $this->role,
        ], $dates ) );
    }

    /** @param array<string, mixed> $body */
    private function update( int $assignment_id, array $body ): WP_REST_Response {
        return $this->send( 'PUT', '/talenttrack/v1/functional-roles/assignments/' . $assignment_id, array_merge( [
            'functional_role_id' => $this->role,
        ], $body ) );
    }

    /** @param array<string, mixed> $body */
    private function send( string $method, string $route, array $body ): WP_REST_Response {
        $request = new WP_REST_Request( $method, $route );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        $response = rest_ensure_response( rest_get_server()->dispatch( $request ) );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        return $response;
    }
}
