<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;
use TT\Modules\Prospects\ScoutingVisitsAccess;

/**
 * #3604 — a scout can read back the visit they just wrote.
 *
 * Before this, `/scouting-visits` answered POST and DELETE only: a client
 * could create a visit, get `{id}` back, and have no way to see what was
 * actually stored — and a key the route did not take (`club`, `age_groups`)
 * disappeared behind a 200 rather than being refused.
 */
final class ScoutingVisitsReadRestTest extends WP_UnitTestCase {

    private int $scout = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->scout = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        wp_set_current_user( $this->scout );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- the routes --------------------------------------------------------

    public function test_both_read_routes_exist(): void {
        $routes = rest_get_server()->get_routes();

        $this->assertTrue( $this->handles( $routes, '/talenttrack/v1/scouting-visits', 'GET' ) );
        $this->assertTrue( $this->handles( $routes, '/talenttrack/v1/scouting-visits/(?P<id>\d+)', 'GET' ) );
    }

    public function test_the_list_route_declares_its_filters(): void {
        $args = $this->argsFor( '/talenttrack/v1/scouting-visits', 'GET' );

        foreach ( [ 'scout_user_id', 'status', 'date_from', 'date_to', 'include_archived' ] as $filter ) {
            $this->assertArrayHasKey( $filter, $args, "{$filter} is not declared" );
        }
    }

    // ---- create answers with what it stored --------------------------------

    public function test_create_answers_with_the_stored_visit(): void {
        [ $data, $status ] = $this->send( 'POST', 'scouting-visits', [
            'visit_date'        => '2026-10-03',
            'visit_time'        => '18:30',
            'location'          => 'Sportpark Zuid',
            'event_description' => 'Districtstoernooi',
            'age_groups_csv'    => 'u13,u14',
            'notes'             => 'Twee keepers bekijken.',
            'status'            => ScoutingVisitsRepository::STATUS_PLANNED,
        ] );

        $this->assertSame( 200, $status );
        $visit = $data['data'];
        $this->assertGreaterThan( 0, (int) $visit['id'] );
        $this->assertNotSame( '', (string) $visit['uuid'] );
        $this->assertSame( '2026-10-03', $visit['visit_date'] );
        $this->assertSame( '18:30', $visit['visit_time'], 'the seconds the column stores are not the caller\'s business' );
        $this->assertSame( 'Sportpark Zuid', $visit['location'] );
        $this->assertSame( 'u13,u14', $visit['age_groups_csv'] );
        $this->assertSame( ScoutingVisitsRepository::STATUS_PLANNED, $visit['status'] );
        $this->assertSame( $this->scout, (int) $visit['scout_user_id'] );
        $this->assertSame( 0, (int) $visit['prospect_count'] );
        $this->assertNull( $visit['archived_at'] );
    }

    public function test_the_owner_reads_the_visit_back(): void {
        $id = $this->visit();

        [ $data, $status ] = $this->send( 'GET', 'scouting-visits/' . $id );

        $this->assertSame( 200, $status );
        $this->assertSame( $id, (int) $data['data']['visit']['id'] );
        $this->assertSame( 'Sportpark Noord', $data['data']['visit']['location'] );
        $this->assertSame( [], $data['data']['visit']['prospects'] );
    }

    public function test_an_update_answers_with_the_visit_as_it_now_stands(): void {
        $id = $this->visit();

        [ $data, $status ] = $this->send( 'POST', 'scouting-visits/' . $id, [ 'location' => 'Sportpark West' ] );

        $this->assertSame( 200, $status );
        $this->assertTrue( $data['data']['changed'] );
        $this->assertSame( 'Sportpark West', $data['data']['visit']['location'] );
        $this->assertSame( 'Oefenwedstrijd', $data['data']['visit']['event_description'], 'a field that was not sent is left alone' );
    }

    public function test_an_unknown_visit_is_not_found(): void {
        [ $data, $status ] = $this->send( 'GET', 'scouting-visits/999999' );

        $this->assertSame( 404, $status );
        $this->assertSame( 'not_found', $data['errors'][0]['code'] ?? null );
    }

    // ---- the body contract -------------------------------------------------

    public function test_a_field_the_route_does_not_take_is_refused_and_nothing_is_written(): void {
        $before = count( ( new ScoutingVisitsRepository() )->search() );

        [ $data, $status ] = $this->send( 'POST', 'scouting-visits', [
            'visit_date' => '2026-10-03', 'location' => 'Sportpark Zuid', 'club' => 'Ajax',
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertSame( [ 'club' ], $data['errors'][0]['details']['fields'] ?? null );
        $this->assertContains( 'location', (array) ( $data['errors'][0]['details']['allowed'] ?? [] ) );
        $this->assertCount( $before, ( new ScoutingVisitsRepository() )->search(), 'no row was created' );
    }

    public function test_an_unknown_field_on_update_leaves_the_row_alone(): void {
        $id = $this->visit();

        [ $data, $status ] = $this->send( 'POST', 'scouting-visits/' . $id, [
            'location' => 'Sportpark West', 'age_groups' => 'u13',
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertSame( [ 'age_groups' ], $data['errors'][0]['details']['fields'] ?? null );

        $stored = (array) ( new ScoutingVisitsRepository() )->find( $id );
        $this->assertSame( 'Sportpark Noord', (string) $stored['location'] );
    }

    // ---- the listing -------------------------------------------------------

    public function test_the_listing_filters_on_date_status_and_archived(): void {
        $early = $this->visit( 'Oefenwedstrijd', '2026-09-01' );
        $late  = $this->visit( 'Oefenwedstrijd', '2026-11-01' );

        [ $data ] = $this->send( 'GET', 'scouting-visits', [], [ 'date_from' => '2026-10-01' ] );
        $this->assertSame( [ $late ], $this->ids( $data ) );

        [ $data ] = $this->send( 'GET', 'scouting-visits', [], [ 'date_to' => '2026-10-01' ] );
        $this->assertSame( [ $early ], $this->ids( $data ) );

        [ $data ] = $this->send( 'GET', 'scouting-visits', [], [ 'status' => ScoutingVisitsRepository::STATUS_COMPLETED ] );
        $this->assertSame( [ $early, $late ], array_reverse( $this->ids( $data ) ), 'the seed visits are completed' );

        ( new ScoutingVisitsRepository() )->archive( $early );
        [ $data ] = $this->send( 'GET', 'scouting-visits' );
        $this->assertSame( [ $late ], $this->ids( $data ) );

        [ $data ] = $this->send( 'GET', 'scouting-visits', [], [ 'include_archived' => '1' ] );
        $this->assertContains( $early, $this->ids( $data ) );
    }

    public function test_a_date_filter_that_is_not_a_date_is_refused(): void {
        [ $data, $status ] = $this->send( 'GET', 'scouting-visits', [], [ 'date_from' => '03-10-2026' ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'bad_date', $data['errors'][0]['code'] ?? null );
    }

    public function test_the_listing_carries_the_prospect_count(): void {
        $id = $this->visit();
        ( new ProspectsRepository() )->create( [
            'first_name' => 'Nieuw', 'last_name' => 'Talent',
            'discovered_by_user_id' => $this->scout, 'scouting_visit_id' => $id,
        ] );

        [ $data ] = $this->send( 'GET', 'scouting-visits' );
        $row = $this->rowFor( $data, $id );

        $this->assertSame( 1, (int) $row['prospect_count'] );
    }

    // ---- the prospects on a visit ------------------------------------------

    public function test_the_visit_carries_its_prospects_with_a_birth_year_and_no_dob(): void {
        $id = $this->visit();
        ( new ProspectsRepository() )->create( [
            'first_name' => 'Nieuw', 'last_name' => 'Talent', 'date_of_birth' => '2013-04-22',
            'current_club' => 'RKC',
            'discovered_by_user_id' => $this->scout, 'scouting_visit_id' => $id,
        ] );

        [ $data, $status ] = $this->send( 'GET', 'scouting-visits/' . $id );
        $this->assertSame( 200, $status );

        $prospects = $data['data']['visit']['prospects'];
        $this->assertCount( 1, $prospects );
        $this->assertSame( 'Nieuw Talent', $prospects[0]['name'] );
        $this->assertSame( 2013, (int) $prospects[0]['birth_year'] );
        $this->assertArrayNotHasKey( 'dob', $prospects[0], 'these are minors; the year is enough to say who was watched' );
        $this->assertArrayNotHasKey( 'date_of_birth', $prospects[0] );
        $this->assertSame( 'RKC', $prospects[0]['club'] );
        $this->assertSame( 'active', $prospects[0]['outcome'] );
    }

    // ---- who may read ------------------------------------------------------

    public function test_a_user_without_the_visit_panel_is_refused(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_player' ] ) );

        [ , $status ] = $this->send( 'GET', 'scouting-visits' );

        $this->assertContains( $status, [ 401, 403 ] );
    }

    public function test_another_scout_cannot_read_this_scouts_visit(): void {
        $id    = $this->visit();
        $other = self::factory()->user->create( [ 'role' => 'tt_scout' ] );

        if ( AuthorizationService::userCanOrMatrix( $other, 'tt_manage_prospects' ) ) {
            $this->markTestSkipped( 'A scout reaches every prospect record on this install.' );
        }

        wp_set_current_user( $other );
        [ $data, $status ] = $this->send( 'GET', 'scouting-visits/' . $id );

        $this->assertSame( 403, $status );
        $this->assertSame( 'forbidden', $data['errors'][0]['code'] ?? null );

        [ $list ] = $this->send( 'GET', 'scouting-visits', [], [ 'scout_user_id' => $this->scout ] );
        $this->assertNotContains( $id, $this->ids( $list ), 'a caller cannot ask for somebody else\'s visits' );
    }

    public function test_the_head_of_development_reads_another_scouts_visit(): void {
        $id  = $this->visit();
        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        if ( ! AuthorizationService::userCanOrMatrix( $hod, 'tt_manage_prospects' ) ) {
            $this->markTestSkipped( 'The head of development does not manage prospects on this install.' );
        }

        wp_set_current_user( $hod );
        [ $data, $status ] = $this->send( 'GET', 'scouting-visits/' . $id );

        $this->assertSame( 200, $status );
        $this->assertSame( $id, (int) $data['data']['visit']['id'] );
    }

    // ---- the rule itself ---------------------------------------------------

    public function test_the_owner_rule_needs_no_capability_lookup(): void {
        $visit = (object) [ 'id' => 1, 'scout_user_id' => $this->scout ];

        $this->assertTrue( ScoutingVisitsAccess::canReadVisit( $this->scout, $visit, false ) );
        $this->assertTrue( ScoutingVisitsAccess::canReadVisit( 0, $visit, true ), 'an administrator is never refused' );
        $this->assertFalse( ScoutingVisitsAccess::canReadVisit( 0, $visit, false ), 'a logged-out request has no visits' );
    }

    // ---- helpers -----------------------------------------------------------

    private function visit( string $event = 'Oefenwedstrijd', string $date = '2026-09-12' ): int {
        return ( new ScoutingVisitsRepository() )->create( [
            'scout_user_id' => $this->scout, 'visit_date' => $date, 'location' => 'Sportpark Noord',
            'event_description' => $event, 'status' => ScoutingVisitsRepository::STATUS_COMPLETED,
        ] );
    }

    /**
     * @param array<string,mixed> $data
     * @return list<int>
     */
    private function ids( array $data ): array {
        $rows = (array) ( $data['data']['rows'] ?? [] );

        return array_values( array_map( static fn( $row ) => (int) ( ( (array) $row )['id'] ?? 0 ), $rows ) );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function rowFor( array $data, int $id ): array {
        foreach ( (array) ( $data['data']['rows'] ?? [] ) as $row ) {
            $row = (array) $row;
            if ( (int) ( $row['id'] ?? 0 ) === $id ) return $row;
        }

        return [];
    }

    /**
     * @param array<string,mixed> $routes
     */
    private function handles( array $routes, string $route, string $method ): bool {
        foreach ( (array) ( $routes[ $route ] ?? [] ) as $handler ) {
            if ( ! empty( $handler['methods'][ $method ] ) ) return true;
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function argsFor( string $route, string $method ): array {
        foreach ( (array) ( rest_get_server()->get_routes()[ $route ] ?? [] ) as $handler ) {
            if ( ! empty( $handler['methods'][ $method ] ) ) return (array) ( $handler['args'] ?? [] );
        }

        return [];
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body = [], array $query = [] ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $body ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        if ( $query ) {
            $request->set_query_params( array_map( 'strval', $query ) );
        }
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
