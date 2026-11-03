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
 * #4924 — `PUT /functional-roles/assignments/{id}` leaves a date the body
 * does not carry alone. The dates decide which teams the holder works with
 * today, so a role change sent on its own must not end or reopen the
 * assignment. A date sent empty still clears it.
 */
final class FunctionalRoleAssignmentPartialUpdateTest extends WP_UnitTestCase {

    private string $p      = '';
    private int $club      = 0;
    private int $role      = 0;
    private int $role_b    = 0;
    private int $assignment = 0;

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
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO14-2' ] );
        $team = (int) $wpdb->insert_id;
        foreach ( [ 'role' => 'staf_head_coach_4924', 'role_b' => 'staf_assistant_4924' ] as $slot => $key ) {
            $wpdb->insert( "{$this->p}tt_functional_roles", [ 'club_id' => $this->club, 'role_key' => $key, 'label' => $key ] );
            $this->{$slot} = (int) $wpdb->insert_id;
        }
        $wpdb->insert( "{$this->p}tt_people", [ 'club_id' => $this->club, 'first_name' => 'Ilse', 'last_name' => 'Staf' ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team,
            'person_id'          => $person,
            'functional_role_id' => $this->role,
            'start_date'         => '2026-08-01',
            'end_date'           => '2026-10-31',
        ] );
        $this->assignment = (int) $wpdb->insert_id;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_role_change_alone_leaves_both_dates(): void {
        $this->assertSame( 200, $this->put( [ 'functional_role_id' => $this->role_b ] )->get_status() );
        $row = $this->row();
        $this->assertSame( $this->role_b, (int) $row['functional_role_id'], 'the role changed' );
        $this->assertSame( '2026-08-01', $row['start_date'], 'the omitted start is left alone' );
        $this->assertSame( '2026-10-31', $row['end_date'], 'the omitted end is left alone' );
    }

    public function test_one_date_sent_leaves_the_other(): void {
        $this->assertSame( 200, $this->put( [ 'functional_role_id' => $this->role, 'start_date' => '2026-09-01' ] )->get_status() );
        $row = $this->row();
        $this->assertSame( '2026-09-01', $row['start_date'] );
        $this->assertSame( '2026-10-31', $row['end_date'], 'the omitted end is left alone' );

        $this->assertSame( 200, $this->put( [ 'functional_role_id' => $this->role, 'end_date' => '2027-06-30' ] )->get_status() );
        $row = $this->row();
        $this->assertSame( '2026-09-01', $row['start_date'], 'the omitted start is left alone' );
        $this->assertSame( '2027-06-30', $row['end_date'] );
    }

    public function test_a_date_sent_empty_is_cleared(): void {
        $this->assertSame( 200, $this->put( [ 'functional_role_id' => $this->role, 'end_date' => '' ] )->get_status() );
        $row = $this->row();
        $this->assertNull( $row['end_date'], 'sent empty: cleared' );
        $this->assertSame( '2026-08-01', $row['start_date'], 'the omitted start is left alone' );
    }

    // ---- fixtures ------------------------------------------------------

    /** @return array<string, mixed> */
    private function row(): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT functional_role_id, start_date, end_date FROM {$this->p}tt_team_people WHERE id = %d",
            $this->assignment
        ), ARRAY_A );
    }

    /** @param array<string, mixed> $body */
    private function put( array $body ): WP_REST_Response {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/functional-roles/assignments/' . $this->assignment );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        $response = rest_ensure_response( rest_get_server()->dispatch( $request ) );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        return $response;
    }
}
