<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Prospects\ProspectScope;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;
use TT\Modules\Wizards\Prospect\NewProspectWizard;

/**
 * #3600 — a prospect can be linked to the scouting visit they were found at.
 *
 * Nothing but the demo generator ever wrote `tt_prospects.scouting_visit_id`:
 * the wizard ignored `from_visit`, `create()` did not insert the column,
 * `update()` left it off its allow-list, and the visit routes described no
 * fields, so every real visit listed nobody.
 */
final class ProspectVisitLinkTest extends WP_UnitTestCase {

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

    public function test_the_visit_routes_declare_their_fields(): void {
        $routes = rest_get_server()->get_routes();
        $args   = [];
        foreach ( $routes['/talenttrack/v1/scouting-visits'] ?? [] as $handler ) {
            if ( ! empty( $handler['methods']['POST'] ) ) $args = $handler['args'];
        }

        foreach ( [ 'visit_date', 'location', 'visit_time', 'event_description', 'age_groups_csv', 'notes', 'status', 'scout_user_id' ] as $field ) {
            $this->assertArrayHasKey( $field, $args, "{$field} is not declared" );
        }
        $this->assertTrue( $args['visit_date']['required'] );
        $this->assertTrue( $args['location']['required'] );
    }

    public function test_age_groups_csv_is_stored(): void {
        [ $data, $status ] = $this->send( 'POST', 'scouting-visits', [
            'visit_date' => '2026-09-26', 'location' => 'Sportpark Zuid', 'age_groups_csv' => 'u13,u14',
        ] );
        $this->assertSame( 200, $status );

        $visit = ( new ScoutingVisitsRepository() )->find( (int) $data['data']['id'] );
        $this->assertSame( 'u13,u14', (string) ( (array) $visit )['age_groups_csv'] );
    }

    public function test_a_prospect_is_linked_to_a_visit_over_rest(): void {
        $visit    = $this->visit();
        $prospect = $this->prospect( $this->scout );

        [ $data, $status ] = $this->send( 'PATCH', 'prospects/' . $prospect, [ 'scouting_visit_id' => $visit ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['data']['changed'] );

        [ $got ] = $this->send( 'GET', 'prospects/' . $prospect );
        $this->assertSame( $visit, (int) $got['data']['prospect']['scouting_visit_id'] );
    }

    public function test_an_unknown_visit_is_refused_and_nothing_changes(): void {
        $prospect = $this->prospect( $this->scout );

        [ $data, $status ] = $this->send( 'PATCH', 'prospects/' . $prospect, [ 'scouting_visit_id' => 999999 ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'invalid_visit', $data['errors'][0]['code'] ?? null );
        $this->assertNull( ( (array) ( new ProspectsRepository() )->find( $prospect ) )['scouting_visit_id'] );
    }

    public function test_logging_a_find_from_a_visit_stores_the_visit(): void {
        $visit = $this->visit( 'District tournament O13' );

        $seed = ( new NewProspectWizard() )->initialState( [ 'from_visit' => (string) $visit ] );
        $this->assertSame( $visit, $seed['scouting_visit_id'] );
        $this->assertSame( 'District tournament O13', $seed['discovered_at_event'] );

        $this->assertSame( [], ( new NewProspectWizard() )->initialState( [ 'from_visit' => '999999' ] ), 'an unknown visit seeds nothing' );
        $this->assertSame( [], ( new NewProspectWizard() )->initialState( [] ), 'other entry points seed nothing' );

        $id = ( new ProspectsRepository() )->create( [ 'first_name' => 'Gevonden', 'last_name' => 'Talent' ] + $seed );
        $this->assertSame( $visit, (int) ( (array) ( new ProspectsRepository() )->find( $id ) )['scouting_visit_id'] );
    }

    public function test_a_prospect_outside_the_callers_scope_cannot_be_patched(): void {
        $other_scout = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $prospect    = $this->prospect( $other_scout );

        if ( ProspectScope::canSeeAll( $this->scout ) ) {
            $this->markTestSkipped( 'A scout reads every prospect on this install.' );
        }
        [ $data, $status ] = $this->send( 'GET', 'prospects/' . $prospect );
        $this->assertSame( 404, $status, 'precondition: the read is scoped' );

        [ , $status ] = $this->send( 'PATCH', 'prospects/' . $prospect, [ 'parent_name' => 'Iemand' ] );
        $this->assertSame( 404, $status, 'the write is scoped the same way' );
    }

    private function visit( string $event = 'Oefenwedstrijd' ): int {
        return ( new ScoutingVisitsRepository() )->create( [
            'scout_user_id' => $this->scout, 'visit_date' => '2026-09-12', 'location' => 'Sportpark Noord',
            'event_description' => $event, 'status' => 'completed',
        ] );
    }

    private function prospect( int $scout ): int {
        return ( new ProspectsRepository() )->create( [
            'first_name' => 'Nieuw', 'last_name' => 'Talent', 'discovered_by_user_id' => $scout,
        ] );
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
