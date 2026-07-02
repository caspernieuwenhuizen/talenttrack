<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * REST smoke suite for the methodology football-actions authoring
 * endpoints (#2230).
 *
 * The routes live at /talenttrack/v1/methodology/football-actions and gate
 * on the `tt_edit_methodology` capability via
 * AbstractMethodologyRestController. Checks:
 *
 *   (a) Denial path — an UNAUTHENTICATED caller must be denied (401/403)
 *       on every verb, never 200 and never >=500.
 *   (b) Happy path + envelope — an administrator can create → get → update
 *       → delete a club-authored action, asserting status + envelope.
 *   (c) Shipped rows refuse edit/delete with 409.
 *   (d) An action still referenced by a goal (`tt_goals.linked_action_id`)
 *       refuses delete with 409 so the link is never orphaned.
 */
final class MethodologyFootballActionsRestSmokeTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1/methodology/football-actions';

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

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
    public function test_football_action_crud_happy_path(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        // CREATE.
        $create = new WP_REST_Request( 'POST', self::BASE );
        $create->set_header( 'Content-Type', 'application/json' );
        $create->set_body( wp_json_encode( [
            'slug'         => 'smoke-aannemen',
            'category_key' => 'with_ball',
            'name'         => [ 'nl' => 'Aannemen', 'en' => 'First touch' ],
            'description'  => [ 'nl' => 'Bal controleren', 'en' => 'Control the ball' ],
        ] ) );
        $create_res = rest_do_request( $create );

        $this->assertContains( $create_res->get_status(), [ 200, 201 ], 'football action create succeeds' );
        $body = $create_res->get_data();
        $this->assertEnvelopeSuccess( $body );
        $id = (int) ( $body['data']['id'] ?? 0 );
        $this->assertGreaterThan( 0, $id, 'create returns a new action id' );

        // GET one — round-trips both languages under *_i18n.
        $get_res = rest_do_request( new WP_REST_Request( 'GET', self::BASE . '/' . $id ) );
        $this->assertSame( 200, $get_res->get_status() );
        $get_body = $get_res->get_data();
        $this->assertEnvelopeSuccess( $get_body );
        $this->assertSame( 'smoke-aannemen', $get_body['data']['slug'] ?? null );
        $this->assertSame( 'with_ball', $get_body['data']['category_key'] ?? null );
        $this->assertSame( 'Aannemen', $get_body['data']['name_i18n']['nl'] ?? null, 'Dutch name round-trips' );
        $this->assertSame( 'First touch', $get_body['data']['name_i18n']['en'] ?? null, 'English name round-trips' );

        // UPDATE.
        $update = new WP_REST_Request( 'PUT', self::BASE . '/' . $id );
        $update->set_header( 'Content-Type', 'application/json' );
        $update->set_body( wp_json_encode( [ 'category_key' => 'support', 'name' => [ 'nl' => 'Aangepast', 'en' => 'Updated' ] ] ) );
        $update_res = rest_do_request( $update );
        $this->assertSame( 200, $update_res->get_status(), 'football action update succeeds' );
        $this->assertEnvelopeSuccess( $update_res->get_data() );

        // DELETE.
        $delete_res = rest_do_request( new WP_REST_Request( 'DELETE', self::BASE . '/' . $id ) );
        $this->assertSame( 200, $delete_res->get_status(), 'football action delete succeeds' );
        $delete_body = $delete_res->get_data();
        $this->assertEnvelopeSuccess( $delete_body );
        $this->assertTrue( (bool) ( $delete_body['data']['deleted'] ?? false ), 'delete removes the action' );
    }

    /**
     * A malformed authorized create (invalid category) returns a 400 with
     * the error envelope, not a 500 or a silent success.
     */
    public function test_football_action_create_invalid_category_is_bad_request(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $req = new WP_REST_Request( 'POST', self::BASE );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [
            'slug'         => 'smoke-bad-cat',
            'category_key' => 'not_a_category',
        ] ) );
        $res = rest_do_request( $req );

        $this->assertSame( 400, $res->get_status(), 'invalid category is a 400' );
        $body = $res->get_data();
        $this->assertIsArray( $body );
        $this->assertFalse( (bool) ( $body['success'] ?? true ), 'malformed call is not a success' );
        $this->assertNotEmpty( $body['errors'] ?? [], 'malformed call carries an error entry' );
    }

    /**
     * Shipped football actions are read-only reference content: update and
     * delete both refuse with a 409, never mutating the row.
     */
    public function test_shipped_football_action_refuses_edit_and_delete(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_football_actions', [
            'club_id'      => 1,
            'slug'         => 'shipped-smoke',
            'category_key' => 'with_ball',
            'is_shipped'   => 1,
        ] );
        $shipped_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $shipped_id );

        $update = new WP_REST_Request( 'PUT', self::BASE . '/' . $shipped_id );
        $update->set_header( 'Content-Type', 'application/json' );
        $update->set_body( wp_json_encode( [ 'name' => [ 'nl' => 'Poging', 'en' => 'Attempt' ] ] ) );
        $this->assertSame( 409, rest_do_request( $update )->get_status(), 'shipped update is refused' );

        $this->assertSame(
            409,
            rest_do_request( new WP_REST_Request( 'DELETE', self::BASE . '/' . $shipped_id ) )->get_status(),
            'shipped delete is refused'
        );
    }

    /**
     * An action that a goal still links to via `linked_action_id` refuses
     * delete with a 409 so the reference is never orphaned.
     */
    public function test_linked_football_action_refuses_delete(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_football_actions', [
            'club_id'      => 1,
            'slug'         => 'linked-smoke',
            'category_key' => 'with_ball',
            'is_shipped'   => 0,
        ] );
        $action_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $action_id );

        $wpdb->insert( $wpdb->prefix . 'tt_goals', [
            'club_id'          => 1,
            'player_id'        => 1,
            'title'            => 'Smoke goal',
            'created_by'       => $uid,
            'linked_action_id' => $action_id,
        ] );

        $res = rest_do_request( new WP_REST_Request( 'DELETE', self::BASE . '/' . $action_id ) );
        $this->assertSame( 409, $res->get_status(), 'a linked action refuses delete with 409' );
        $body = $res->get_data();
        $this->assertIsArray( $body );
        $this->assertFalse( (bool) ( $body['success'] ?? true ), 'linked-delete is not a success' );

        // The row survives the refused delete.
        $still = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_football_actions WHERE id = %d",
            $action_id
        ) );
        $this->assertSame( 1, (int) $still, 'the referenced action is not deleted' );
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
