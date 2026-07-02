<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * REST smoke suite for the methodology framework-family authoring
 * endpoints (#2229): phases, learning goals and influence factors. All
 * three live under /talenttrack/v1/methodology/* and gate on the
 * `tt_edit_methodology` capability via AbstractMethodologyRestController.
 * They are children of the active framework primer, so the suite seeds a
 * club-authored primer before exercising the happy paths.
 *
 *   (a) Denial path — an UNAUTHENTICATED caller is denied (401/403) on
 *       every verb of every entity, never 200 and never >=500.
 *   (b) Happy path + envelope — an administrator can create → get →
 *       update → delete a club-authored row for each entity, asserting
 *       status + the success / data / errors envelope.
 *   (c) Shipped rows refuse edit / delete with a 409.
 */
final class MethodologyFrameworkFamilyRestSmokeTest extends WP_UnitTestCase {

    private const PHASES  = '/talenttrack/v1/methodology/phases';
    private const GOALS   = '/talenttrack/v1/methodology/learning-goals';
    private const FACTORS = '/talenttrack/v1/methodology/influence-factors';

    private int $primer_id = 0;

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        // Seed a club-authored framework primer — the family hangs off it.
        $wpdb->insert( $wpdb->prefix . 'tt_methodology_framework_primers', [
            'club_id'    => 1,
            'club_scope' => 'site',
            'is_shipped' => 0,
        ] );
        $this->primer_id = (int) $wpdb->insert_id;

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public function provideDenialRoutes(): array {
        $out = [];
        foreach ( [
            'phases'  => self::PHASES,
            'goals'   => self::GOALS,
            'factors' => self::FACTORS,
        ] as $name => $base ) {
            $out[ "GET  list $name" ]   = [ 'GET',    $base ];
            $out[ "POST create $name" ] = [ 'POST',   $base ];
            $out[ "GET  one $name" ]    = [ 'GET',    $base . '/1' ];
            $out[ "PUT  update $name" ] = [ 'PUT',    $base . '/1' ];
            $out[ "DELETE one $name" ]  = [ 'DELETE', $base . '/1' ];
        }
        return $out;
    }

    /**
     * @dataProvider provideDenialRoutes
     */
    public function test_unauthenticated_request_is_denied( string $method, string $route ): void {
        wp_set_current_user( 0 );

        $response = rest_do_request( new WP_REST_Request( $method, $route ) );
        $status   = $response->get_status();
        $label    = "$method $route";

        $this->assertNotSame( 200, $status, "$label must NOT return 200 to an unauthenticated caller" );
        $this->assertLessThan( 500, $status, "$label must NOT 500 for an unauthenticated caller (got $status)" );
        $this->assertContains( $status, [ 401, 403 ], "$label must deny with 401 or 403 (got $status)" );
    }

    public function test_phase_crud_happy_path(): void {
        $this->asAdmin();

        $create = $this->json( 'POST', self::PHASES, [
            'side'         => 'attacking',
            'phase_number' => 1,
            'title'        => [ 'nl' => 'Opbouwfase', 'en' => 'Build-up phase' ],
            'goal'         => [ 'nl' => 'De bal veilig naar voren spelen', 'en' => 'Move the ball forward safely' ],
        ] );
        $this->assertContains( $create->get_status(), [ 200, 201 ], 'phase create succeeds' );
        $body = $create->get_data();
        $this->assertEnvelopeSuccess( $body );
        $id = (int) ( $body['data']['id'] ?? 0 );
        $this->assertGreaterThan( 0, $id, 'create returns a new phase id' );

        $get = rest_do_request( new WP_REST_Request( 'GET', self::PHASES . '/' . $id ) );
        $this->assertSame( 200, $get->get_status() );
        $get_body = $get->get_data();
        $this->assertEnvelopeSuccess( $get_body );
        $this->assertSame( 'attacking', $get_body['data']['side'] ?? null );
        $this->assertSame( 'Opbouwfase', $get_body['data']['title_i18n']['nl'] ?? null, 'Dutch title round-trips' );
        $this->assertSame( 'Build-up phase', $get_body['data']['title_i18n']['en'] ?? null, 'English title round-trips' );

        $update = $this->json( 'PUT', self::PHASES . '/' . $id, [ 'title' => [ 'nl' => 'Aangepast', 'en' => 'Updated' ] ] );
        $this->assertSame( 200, $update->get_status(), 'phase update succeeds' );
        $this->assertEnvelopeSuccess( $update->get_data() );

        $delete = rest_do_request( new WP_REST_Request( 'DELETE', self::PHASES . '/' . $id ) );
        $this->assertSame( 200, $delete->get_status(), 'phase delete succeeds' );
        $this->assertTrue( (bool) ( $delete->get_data()['data']['deleted'] ?? false ), 'delete removes the phase' );
    }

    public function test_learning_goal_crud_happy_path(): void {
        $this->asAdmin();

        $create = $this->json( 'POST', self::GOALS, [
            'slug'    => 'tt-test-positiespel',
            'side'    => 'attacking',
            'title'   => [ 'nl' => 'Positiespel', 'en' => 'Positional play' ],
            'bullets' => [ 'nl' => [ 'Vrijlopen' ], 'en' => [ 'Move to space' ] ],
        ] );
        $this->assertContains( $create->get_status(), [ 200, 201 ], 'learning-goal create succeeds' );
        $body = $create->get_data();
        $this->assertEnvelopeSuccess( $body );
        $id = (int) ( $body['data']['id'] ?? 0 );
        $this->assertGreaterThan( 0, $id, 'create returns a new learning-goal id' );

        $get = rest_do_request( new WP_REST_Request( 'GET', self::GOALS . '/' . $id ) );
        $this->assertSame( 200, $get->get_status() );
        $get_body = $get->get_data();
        $this->assertEnvelopeSuccess( $get_body );
        $this->assertSame( 'tt-test-positiespel', $get_body['data']['slug'] ?? null );
        $this->assertSame( [ 'Move to space' ], $get_body['data']['bullets_i18n']['en'] ?? null, 'English bullets round-trip' );

        $update = $this->json( 'PUT', self::GOALS . '/' . $id, [ 'title' => [ 'nl' => 'Aangepast', 'en' => 'Updated' ] ] );
        $this->assertSame( 200, $update->get_status(), 'learning-goal update succeeds' );
        $this->assertEnvelopeSuccess( $update->get_data() );

        $delete = rest_do_request( new WP_REST_Request( 'DELETE', self::GOALS . '/' . $id ) );
        $this->assertSame( 200, $delete->get_status(), 'learning-goal delete succeeds' );
        $this->assertTrue( (bool) ( $delete->get_data()['data']['deleted'] ?? false ), 'delete removes the learning goal' );
    }

    public function test_influence_factor_crud_happy_path(): void {
        $this->asAdmin();

        $create = $this->json( 'POST', self::FACTORS, [
            'slug'        => 'tt-test-spelers',
            'title'       => [ 'nl' => 'Spelers', 'en' => 'Players' ],
            'description' => [ 'nl' => 'De spelersgroep', 'en' => 'The squad' ],
            'sub_factors' => [
                [ 'slug' => 'motivatie', 'title' => [ 'nl' => 'Motivatie', 'en' => 'Motivation' ], 'description' => [ 'nl' => '...', 'en' => '...' ] ],
            ],
        ] );
        $this->assertContains( $create->get_status(), [ 200, 201 ], 'influence-factor create succeeds' );
        $body = $create->get_data();
        $this->assertEnvelopeSuccess( $body );
        $id = (int) ( $body['data']['id'] ?? 0 );
        $this->assertGreaterThan( 0, $id, 'create returns a new influence-factor id' );

        $get = rest_do_request( new WP_REST_Request( 'GET', self::FACTORS . '/' . $id ) );
        $this->assertSame( 200, $get->get_status() );
        $get_body = $get->get_data();
        $this->assertEnvelopeSuccess( $get_body );
        $this->assertSame( 'tt-test-spelers', $get_body['data']['slug'] ?? null );
        $this->assertSame( 'Spelers', $get_body['data']['title_i18n']['nl'] ?? null, 'Dutch title round-trips' );
        $this->assertSame( 'motivatie', $get_body['data']['sub_factors'][0]['slug'] ?? null, 'sub-factor round-trips' );

        $update = $this->json( 'PUT', self::FACTORS . '/' . $id, [ 'title' => [ 'nl' => 'Aangepast', 'en' => 'Updated' ] ] );
        $this->assertSame( 200, $update->get_status(), 'influence-factor update succeeds' );
        $this->assertEnvelopeSuccess( $update->get_data() );

        $delete = rest_do_request( new WP_REST_Request( 'DELETE', self::FACTORS . '/' . $id ) );
        $this->assertSame( 200, $delete->get_status(), 'influence-factor delete succeeds' );
        $this->assertTrue( (bool) ( $delete->get_data()['data']['deleted'] ?? false ), 'delete removes the influence factor' );
    }

    public function test_phase_create_invalid_side_is_bad_request(): void {
        $this->asAdmin();

        $res  = $this->json( 'POST', self::PHASES, [ 'side' => 'not_a_side', 'phase_number' => 1 ] );
        $this->assertSame( 400, $res->get_status(), 'invalid side is a 400' );
        $body = $res->get_data();
        $this->assertIsArray( $body );
        $this->assertFalse( (bool) ( $body['success'] ?? true ), 'malformed call is not a success' );
        $this->assertNotEmpty( $body['errors'] ?? [], 'malformed call carries an error entry' );
    }

    public function test_shipped_phase_refuses_edit_and_delete(): void {
        $this->asAdmin();

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_methodology_phases', [
            'club_id'      => 1,
            'primer_id'    => $this->primer_id,
            'side'         => 'attacking',
            'phase_number' => 1,
            'is_shipped'   => 1,
        ] );
        $shipped_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $shipped_id );

        $update = $this->json( 'PUT', self::PHASES . '/' . $shipped_id, [ 'title' => [ 'nl' => 'Poging', 'en' => 'Attempt' ] ] );
        $this->assertSame( 409, $update->get_status(), 'shipped phase update is refused' );

        $delete = rest_do_request( new WP_REST_Request( 'DELETE', self::PHASES . '/' . $shipped_id ) );
        $this->assertSame( 409, $delete->get_status(), 'shipped phase delete is refused' );
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function asAdmin(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function json( string $method, string $route, array $payload ): \WP_REST_Response {
        $req = new WP_REST_Request( $method, $route );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $payload ) );
        return rest_do_request( $req );
    }

    /**
     * @param mixed $body
     */
    private function assertEnvelopeSuccess( $body ): void {
        $this->assertIsArray( $body, 'response body is the envelope array' );
        $this->assertArrayHasKey( 'success', $body );
        $this->assertArrayHasKey( 'data', $body );
        $this->assertArrayHasKey( 'errors', $body );
        $this->assertTrue( (bool) $body['success'], 'envelope reports success' );
        $this->assertSame( [], $body['errors'], 'success envelope carries no errors' );
    }
}
