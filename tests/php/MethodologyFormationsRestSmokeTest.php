<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * REST smoke suite for the methodology formations authoring endpoints
 * (#2227). Parallels MethodologySetPiecesRestSmokeTest — routes live at
 * /talenttrack/v1/methodology/formations (+ nested /{id}/positions) and
 * gate on `tt_edit_methodology` via AbstractMethodologyRestController.
 */
final class MethodologyFormationsRestSmokeTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1/methodology/formations';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb; $wpdb->hide_errors();
        global $wp_rest_server; $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server; $wp_rest_server = null;
        parent::tear_down();
    }

    /** @return array<string, array{0:string,1:string}> */
    public function provideDenialRoutes(): array {
        return [
            'GET  list'       => [ 'GET',    self::BASE ],
            'POST create'     => [ 'POST',   self::BASE ],
            'GET  one'        => [ 'GET',    self::BASE . '/1' ],
            'PUT  update'     => [ 'PUT',    self::BASE . '/1' ],
            'DELETE one'      => [ 'DELETE', self::BASE . '/1' ],
            'GET  positions'  => [ 'GET',    self::BASE . '/1/positions' ],
            'POST position'   => [ 'POST',   self::BASE . '/1/positions' ],
        ];
    }

    /** @dataProvider provideDenialRoutes */
    public function test_unauthenticated_request_is_denied( string $method, string $route ): void {
        wp_set_current_user( 0 );
        $status = rest_do_request( new WP_REST_Request( $method, $route ) )->get_status();
        $label  = "$method $route";
        $this->assertNotSame( 200, $status, "$label must NOT return 200 unauthenticated" );
        $this->assertLessThan( 500, $status, "$label must NOT 500 unauthenticated (got $status)" );
        $this->assertContains( $status, [ 401, 403 ], "$label denies with 401/403 (got $status)" );
    }

    /** Full CRUD round-trip incl. a nested position, as an administrator. */
    public function test_formation_crud_happy_path(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        // CREATE formation.
        $create = new WP_REST_Request( 'POST', self::BASE );
        $create->set_header( 'Content-Type', 'application/json' );
        $create->set_body( wp_json_encode( [
            'slug'        => 'tt-test-433-smoke',
            'name'        => [ 'nl' => '4-3-3 test', 'en' => '4-3-3 test' ],
            'description' => [ 'nl' => 'Testopstelling', 'en' => 'Test formation' ],
        ] ) );
        $create_res = rest_do_request( $create );
        $this->assertContains( $create_res->get_status(), [ 200, 201 ], 'formation create succeeds' );
        $body = $create_res->get_data();
        $this->assertEnvelopeSuccess( $body );
        $id = (int) ( $body['data']['id'] ?? 0 );
        $this->assertGreaterThan( 0, $id, 'create returns a new formation id' );

        // GET one — slug round-trips; positions array present.
        $get_res = rest_do_request( new WP_REST_Request( 'GET', self::BASE . '/' . $id ) );
        $this->assertSame( 200, $get_res->get_status() );
        $get_body = $get_res->get_data();
        $this->assertEnvelopeSuccess( $get_body );
        $this->assertSame( 'tt-test-433-smoke', $get_body['data']['slug'] ?? null );
        $this->assertArrayHasKey( 'positions', $get_body['data'], 'formation carries a positions array' );

        // CREATE a nested position.
        $pos = new WP_REST_Request( 'POST', self::BASE . '/' . $id . '/positions' );
        $pos->set_header( 'Content-Type', 'application/json' );
        $pos->set_body( wp_json_encode( [
            'slot_number' => 9,
            'short_name'  => [ 'nl' => 'ST', 'en' => 'ST' ],
            'long_name'   => [ 'nl' => 'Spits', 'en' => 'Striker' ],
        ] ) );
        $pos_res = rest_do_request( $pos );
        $this->assertContains( $pos_res->get_status(), [ 200, 201 ], 'position create succeeds' );
        $this->assertEnvelopeSuccess( $pos_res->get_data() );

        // LIST positions.
        $list_pos = rest_do_request( new WP_REST_Request( 'GET', self::BASE . '/' . $id . '/positions' ) );
        $this->assertSame( 200, $list_pos->get_status(), 'position list succeeds' );

        // UPDATE formation.
        $update = new WP_REST_Request( 'PUT', self::BASE . '/' . $id );
        $update->set_header( 'Content-Type', 'application/json' );
        $update->set_body( wp_json_encode( [ 'name' => [ 'nl' => 'Aangepast', 'en' => 'Updated' ] ] ) );
        $this->assertSame( 200, rest_do_request( $update )->get_status(), 'formation update succeeds' );

        // DELETE formation.
        $del = rest_do_request( new WP_REST_Request( 'DELETE', self::BASE . '/' . $id ) );
        $this->assertSame( 200, $del->get_status(), 'formation delete succeeds' );
        $this->assertEnvelopeSuccess( $del->get_data() );
    }

    /** @param mixed $body */
    private function assertEnvelopeSuccess( $body ): void {
        $this->assertIsArray( $body, 'response body is the envelope array' );
        $this->assertArrayHasKey( 'success', $body );
        $this->assertArrayHasKey( 'data', $body );
        $this->assertArrayHasKey( 'errors', $body );
        $this->assertTrue( (bool) $body['success'], 'envelope reports success' );
        $this->assertSame( [], $body['errors'], 'success envelope carries no errors' );
    }
}
