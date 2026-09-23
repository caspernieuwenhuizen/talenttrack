<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Prospects\Domain\ProspectCreationService;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Wizards\Prospect\IdentityStep;
use TT\Modules\Workflow\Forms\LogProspectForm;

/**
 * #4015 — a scout can record the player they saw, over the API.
 *
 * `POST prospects/log` was the only prospect write route and it creates no
 * prospect: it opens a task and answers with a `task_id`. So the first entry
 * in the recruitment journey — "where does this player come from" — had
 * nowhere to go for anything that is not the WordPress front end.
 *
 * The substance of the fix is not the route. The field map and the duplicate
 * check existed twice, in the wizard and in the legacy workflow form, and a
 * third copy behind a route would have guaranteed they drifted — so these
 * tests pin that the three surfaces share one create path and answer the
 * same way about a duplicate.
 */
final class ProspectCreateRestTest extends WP_UnitTestCase {

    private int $scout = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        // DELETE, not TRUNCATE: TRUNCATE commits and breaks the rollback.
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_prospects" );

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->scout = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        wp_set_current_user( $this->scout );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_collection_answers_post(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/talenttrack/v1/prospects', $routes );

        $methods = [];
        foreach ( $routes['/talenttrack/v1/prospects'] as $handler ) {
            foreach ( array_keys( (array) ( $handler['methods'] ?? [] ) ) as $method ) {
                $methods[ $method ] = true;
            }
        }

        $this->assertArrayHasKey( 'POST', $methods );
        $this->assertArrayHasKey( 'GET', $methods, 'the list route must survive the second handler' );
    }

    /** The reported case, end to end. */
    public function test_a_scout_records_a_prospect_and_reads_it_back(): void {
        $visit = $this->seedVisit();

        $response = $this->post( [
            'first_name'        => 'Joep',
            'last_name'         => 'Bakker',
            'date_of_birth'     => '2013-04-02',
            'current_club'      => 'VV Rijnstreek',
            'scouting_notes'    => 'Left-footed, reads the game early.',
            'scouting_visit_id' => $visit,
        ] );

        $this->assertSame( 201, $response->get_status() );

        $body        = $response->get_data();
        $prospect_id = (int) ( $body['data']['prospect_id'] ?? 0 );
        $this->assertGreaterThan( 0, $prospect_id );

        $row = ( new ProspectsRepository() )->find( $prospect_id );
        $this->assertNotNull( $row );
        $this->assertSame( 'Joep', (string) ( ( (array) $row )['first_name'] ?? '' ) );
        $this->assertSame( $visit, (int) ( ( (array) $row )['scouting_visit_id'] ?? 0 ) );

        // And it is in the list the same caller reads.
        $list = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/prospects' ) );
        $this->assertSame( 200, $list->get_status() );
        $ids = array_map(
            static fn ( array $r ): int => (int) ( $r['id'] ?? 0 ),
            (array) ( $list->get_data()['data']['rows'] ?? [] )
        );
        $this->assertContains( $prospect_id, $ids );
    }

    public function test_an_unknown_field_is_refused_with_the_allowed_list(): void {
        $response = $this->post( [
            'first_name' => 'Joep',
            'last_name'  => 'Bakker',
            'shoe_size'  => 42,
        ] );

        $this->assertSame( 400, $response->get_status() );

        $body = $response->get_data();
        $this->assertSame( 'unknown_field', (string) ( $body['errors'][0]['code'] ?? '' ) );

        $details = (array) ( $body['errors'][0]['details'] ?? [] );
        $allowed = (array) ( $details['allowed'] ?? [] );
        $this->assertContains( 'first_name', $allowed );
        $this->assertContains( 'scouting_visit_id', $allowed );
    }

    public function test_a_missing_name_is_refused(): void {
        $response = $this->post( [ 'first_name' => 'Joep' ] );

        $this->assertContains( $response->get_status(), [ 400 ] );
    }

    /**
     * The locked decision: mirror the wizard rather than refusing outright.
     * The candidates come back so a client can show the same choice the
     * wizard shows.
     */
    public function test_a_duplicate_returns_the_candidates_and_the_override_flag(): void {
        $first = $this->post( [ 'first_name' => 'Joep', 'last_name' => 'Bakker', 'current_club' => 'VV Rijnstreek' ] );
        $this->assertSame( 201, $first->get_status() );

        $again = $this->post( [ 'first_name' => 'Joep', 'last_name' => 'Bakker', 'current_club' => 'VV Rijnstreek' ] );
        $this->assertSame( 409, $again->get_status() );

        $error = (array) ( $again->get_data()['errors'][0] ?? [] );
        $this->assertSame( ProspectCreationService::ERR_DUPLICATE, (string) ( $error['code'] ?? '' ) );

        $details = (array) ( $error['details'] ?? [] );
        $this->assertFalse( $details['duplicate_override'] ?? null );
        $this->assertNotEmpty( $details['candidates'] ?? [] );
        $this->assertSame( 'Bakker', (string) ( $details['candidates'][0]['last_name'] ?? '' ) );
    }

    public function test_the_override_repost_succeeds(): void {
        $this->post( [ 'first_name' => 'Joep', 'last_name' => 'Bakker' ] );

        $override = $this->post( [
            'first_name'         => 'Joep',
            'last_name'          => 'Bakker',
            'duplicate_override' => true,
        ] );

        $this->assertSame( 201, $override->get_status() );
    }

    public function test_a_caller_without_the_capability_is_refused(): void {
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $response = $this->post( [ 'first_name' => 'Joep', 'last_name' => 'Bakker' ] );

        $this->assertContains( $response->get_status(), [ 401, 403 ] );
    }

    // One create path, three surfaces

    /** The wizard's duplicate step asks the shared rule, and says the same thing. */
    public function test_the_wizard_step_and_the_route_agree_about_a_duplicate(): void {
        $this->post( [ 'first_name' => 'Joep', 'last_name' => 'Bakker' ] );

        $result = ( new IdentityStep() )->validate(
            [ 'first_name' => 'Joep', 'last_name' => 'Bakker' ],
            []
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( ProspectCreationService::ERR_DUPLICATE, $result->get_error_code() );

        $again = $this->post( [ 'first_name' => 'Joep', 'last_name' => 'Bakker' ] );
        $this->assertSame(
            $result->get_error_message(),
            (string) ( $again->get_data()['errors'][0]['message'] ?? '' ),
            'one duplicate rule means one sentence about it'
        );
    }

    /** The legacy workflow form writes through the same service. */
    public function test_the_legacy_workflow_form_creates_through_the_service(): void {
        $visit = $this->seedVisit();

        $payload = ( new LogProspectForm() )->serializeResponse( [
            'first_name'        => 'Sem',
            'last_name'         => 'de Vries',
            'current_club'      => 'VV Voorbeeld',
            'scouting_visit_id' => $visit,
        ], [ 'assignee_user_id' => $this->scout ] );

        $prospect_id = (int) ( $payload['prospect_id'] ?? 0 );
        $this->assertGreaterThan( 0, $prospect_id );

        $row = (array) ( new ProspectsRepository() )->find( $prospect_id );
        $this->assertSame( $this->scout, (int) ( $row['discovered_by_user_id'] ?? 0 ) );
        // The drift the shared service closed: only the wizard's copy of the
        // field map passed the visit, so a prospect logged through this task
        // counted on nobody's visit.
        $this->assertSame( $visit, (int) ( $row['scouting_visit_id'] ?? 0 ) );
    }

    /**
     * `prospects/log` is kept on purpose — external integrations and the
     * parent self-confirmation flow call it — and it still creates no
     * prospect. That is the honest behaviour, not a regression.
     */
    public function test_prospects_log_is_still_there_and_still_creates_nothing(): void {
        $before = $this->countProspects();

        $response = rest_do_request( new WP_REST_Request( 'POST', '/talenttrack/v1/prospects/log' ) );

        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( $before, $this->countProspects() );
    }

    // Helpers

    /** @param array<string,mixed> $body */
    private function post( array $body ): \WP_REST_Response {
        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/prospects' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        return rest_do_request( $request );
    }

    private function countProspects(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_prospects" );
    }

    private function seedVisit(): int {
        return ( new \TT\Modules\Prospects\Repositories\ScoutingVisitsRepository() )->create( [
            'scout_user_id' => $this->scout,
            'visit_date'    => '2026-04-01',
            'location'      => 'VV Rijnstreek',
        ] );
    }
}
