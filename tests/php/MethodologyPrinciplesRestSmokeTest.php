<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * REST smoke suite for the methodology-principles authoring endpoints
 * (#2225, mandate #1388).
 *
 * The routes live at /talenttrack/v1/methodology/principles and gate on
 * the `tt_edit_methodology` capability via
 * AbstractMethodologyRestController. Two checks:
 *
 *   (a) Denial path — an UNAUTHENTICATED caller must be denied (401/403)
 *       on every verb, never 200 (silent leak) and never >=500 (a crashing
 *       permission_callback).
 *   (b) Happy path + envelope — an administrator can create → get → update
 *       → delete a club-authored principle, asserting status + the
 *       `success` / `data` / `errors` envelope at each step, plus that
 *       shipped rows refuse edit/delete with a 409.
 */
final class MethodologyPrinciplesRestSmokeTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1/methodology/principles';

    public function set_up(): void {
        parent::set_up();

        // The plugin grants tt_* caps via ensureCapabilities() on
        // activation / admin_init, neither of which fires in the wp-env test
        // bootstrap. Grant them here so the admin passes the cap-level
        // permission_callback. Idempotent.
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        // Rebuild the REST server so every plugin route registers freshly.
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
        return [
            'GET  list'   => [ 'GET',    self::BASE ],
            'POST create' => [ 'POST',   self::BASE ],
            'GET  one'    => [ 'GET',    self::BASE . '/1' ],
            'PUT  update' => [ 'PUT',    self::BASE . '/1' ],
            'DELETE one'  => [ 'DELETE', self::BASE . '/1' ],
        ];
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
        $this->assertContains(
            $status,
            [ 401, 403 ],
            "$label must deny an unauthenticated caller with 401 or 403 (got $status)"
        );
    }

    /**
     * Full CRUD round-trip as an administrator: create → get → update →
     * delete, asserting status + envelope at each step.
     */
    public function test_principle_crud_happy_path(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        // CREATE.
        $create = new WP_REST_Request( 'POST', self::BASE );
        $create->set_header( 'Content-Type', 'application/json' );
        $create->set_body( wp_json_encode( [
            'code'              => 'SMK-01',
            'team_function_key' => 'aanvallen',
            'team_task_key'     => 'opbouwen',
            'title'             => [ 'nl' => 'Rugdekking', 'en' => 'Cover' ],
            'explanation'       => [ 'nl' => 'Toelichting NL', 'en' => 'Explanation EN' ],
        ] ) );
        $create_res = rest_do_request( $create );

        $this->assertContains( $create_res->get_status(), [ 200, 201 ], 'principle create succeeds' );
        $body = $create_res->get_data();
        $this->assertEnvelopeSuccess( $body );
        $id = (int) ( $body['data']['id'] ?? 0 );
        $this->assertGreaterThan( 0, $id, 'create returns a new principle id' );

        // GET one — round-trips both languages under *_i18n.
        $get_res = rest_do_request( new WP_REST_Request( 'GET', self::BASE . '/' . $id ) );
        $this->assertSame( 200, $get_res->get_status() );
        $get_body = $get_res->get_data();
        $this->assertEnvelopeSuccess( $get_body );
        $this->assertSame( 'SMK-01', $get_body['data']['code'] ?? null );
        $this->assertSame( 'Rugdekking', $get_body['data']['title_i18n']['nl'] ?? null, 'Dutch title round-trips' );
        $this->assertSame( 'Cover', $get_body['data']['title_i18n']['en'] ?? null, 'English title round-trips' );

        // UPDATE.
        $update = new WP_REST_Request( 'PUT', self::BASE . '/' . $id );
        $update->set_header( 'Content-Type', 'application/json' );
        $update->set_body( wp_json_encode( [ 'title' => [ 'nl' => 'Aangepast', 'en' => 'Updated' ] ] ) );
        $update_res = rest_do_request( $update );
        $this->assertSame( 200, $update_res->get_status(), 'principle update succeeds' );
        $this->assertEnvelopeSuccess( $update_res->get_data() );

        // DELETE.
        $delete_res = rest_do_request( new WP_REST_Request( 'DELETE', self::BASE . '/' . $id ) );
        $this->assertSame( 200, $delete_res->get_status(), 'principle delete succeeds' );
        $delete_body = $delete_res->get_data();
        $this->assertEnvelopeSuccess( $delete_body );
        $this->assertTrue( (bool) ( $delete_body['data']['deleted'] ?? false ), 'delete removes the principle' );
    }

    /**
     * A malformed authorized create (invalid taxonomy) returns a 400 with
     * the error envelope, not a 500 or a silent success.
     */
    public function test_principle_create_invalid_taxonomy_is_bad_request(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $req = new WP_REST_Request( 'POST', self::BASE );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [
            'code'              => 'SMK-02',
            'team_function_key' => 'not_a_function',
            'team_task_key'     => 'opbouwen',
        ] ) );
        $res = rest_do_request( $req );

        $this->assertSame( 400, $res->get_status(), 'invalid taxonomy is a 400' );
        $body = $res->get_data();
        $this->assertIsArray( $body );
        $this->assertFalse( (bool) ( $body['success'] ?? true ), 'malformed call is not a success' );
        $this->assertNotEmpty( $body['errors'] ?? [], 'malformed call carries an error entry' );
    }

    /**
     * Shipped principles are read-only reference content: update and delete
     * both refuse with a 409, never mutating the row.
     */
    public function test_shipped_principle_refuses_edit_and_delete(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_principles', [
            'club_id'           => 1,
            'code'              => 'SHP-01',
            'team_function_key' => 'aanvallen',
            'team_task_key'     => 'opbouwen',
            'is_shipped'        => 1,
        ] );
        $shipped_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $shipped_id );

        $update = new WP_REST_Request( 'PUT', self::BASE . '/' . $shipped_id );
        $update->set_header( 'Content-Type', 'application/json' );
        $update->set_body( wp_json_encode( [ 'title' => [ 'nl' => 'Poging', 'en' => 'Attempt' ] ] ) );
        $this->assertSame( 409, rest_do_request( $update )->get_status(), 'shipped update is refused' );

        $this->assertSame(
            409,
            rest_do_request( new WP_REST_Request( 'DELETE', self::BASE . '/' . $shipped_id ) )->get_status(),
            'shipped delete is refused'
        );
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
