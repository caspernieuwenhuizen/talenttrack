<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * REST smoke suite for the methodology Vision + Framework-primer authoring
 * endpoints (#2226, mandate #1388).
 *
 * Both are SINGLETONS: read + update only, no create, no delete. The routes
 * gate on `tt_edit_methodology` via AbstractMethodologyRestController.
 * Checks:
 *
 *   (a) Denial path — an UNAUTHENTICATED caller must be denied (401/403) on
 *       the collection GET, item GET and item PUT; never 200, never >=500.
 *   (b) Happy path + envelope — an administrator can GET the active record
 *       and PUT an update, with both languages round-tripping under *_i18n.
 *   (c) Shipped rows refuse edit with a 409.
 */
final class MethodologyVisionPrimerRestSmokeTest extends WP_UnitTestCase {

    private const VISION = '/talenttrack/v1/methodology/vision';
    private const PRIMER = '/talenttrack/v1/methodology/framework-primer';

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
            'vision GET  list' => [ 'GET', self::VISION ],
            'vision GET  one'  => [ 'GET', self::VISION . '/1' ],
            'vision PUT  one'  => [ 'PUT', self::VISION . '/1' ],
            'primer GET  list' => [ 'GET', self::PRIMER ],
            'primer GET  one'  => [ 'GET', self::PRIMER . '/1' ],
            'primer PUT  one'  => [ 'PUT', self::PRIMER . '/1' ],
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
     * The singletons expose no create / delete routes — an authorized POST
     * to the collection or DELETE on an item is a 404 (route not found),
     * not a silent success.
     */
    public function test_singletons_have_no_create_or_delete_routes(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        foreach ( [ self::VISION, self::PRIMER ] as $base ) {
            $this->assertSame( 404, rest_do_request( new WP_REST_Request( 'POST', $base ) )->get_status(), "$base has no create route" );
            $this->assertSame( 404, rest_do_request( new WP_REST_Request( 'DELETE', $base . '/1' ) )->get_status(), "$base has no delete route" );
        }
    }

    public function test_vision_read_and_update_happy_path(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_methodology_visions', [
            'club_id'             => 1,
            'club_scope'          => 'site',
            'is_shipped'          => 0,
            'way_of_playing_json' => wp_json_encode( [ 'nl' => 'Balbezit', 'en' => 'Possession' ] ),
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id );

        // Collection GET returns the active vision.
        $list = rest_do_request( new WP_REST_Request( 'GET', self::VISION ) );
        $this->assertSame( 200, $list->get_status() );
        $list_body = $list->get_data();
        $this->assertEnvelopeSuccess( $list_body );
        $this->assertSame( $id, (int) ( $list_body['data']['vision']['id'] ?? 0 ), 'active vision is returned' );

        // Item GET round-trips both languages.
        $get = rest_do_request( new WP_REST_Request( 'GET', self::VISION . '/' . $id ) );
        $this->assertSame( 200, $get->get_status() );
        $get_body = $get->get_data();
        $this->assertEnvelopeSuccess( $get_body );
        $this->assertSame( 'Balbezit', $get_body['data']['way_of_playing_i18n']['nl'] ?? null );
        $this->assertSame( 'Possession', $get_body['data']['way_of_playing_i18n']['en'] ?? null );

        // PUT update.
        $update = new WP_REST_Request( 'PUT', self::VISION . '/' . $id );
        $update->set_header( 'Content-Type', 'application/json' );
        $update->set_body( wp_json_encode( [
            'style_of_play_key' => 'high_press',
            'important_traits'  => [ 'nl' => [ 'Snel', 'Slim' ], 'en' => [ 'Fast', 'Smart' ] ],
        ] ) );
        $update_res = rest_do_request( $update );
        $this->assertSame( 200, $update_res->get_status(), 'vision update succeeds' );
        $this->assertEnvelopeSuccess( $update_res->get_data() );

        $reread = rest_do_request( new WP_REST_Request( 'GET', self::VISION . '/' . $id ) )->get_data();
        $this->assertSame( 'high_press', $reread['data']['style_of_play_key'] ?? null, 'style round-trips' );
        // Raw *_i18n is locale-independent: assert both languages survived.
        $this->assertSame( [ 'Snel', 'Slim' ], $reread['data']['important_traits_i18n']['nl'] ?? null, 'Dutch traits round-trip' );
        $this->assertSame( [ 'Fast', 'Smart' ], $reread['data']['important_traits_i18n']['en'] ?? null, 'English traits round-trip' );
    }

    public function test_vision_update_invalid_style_is_bad_request(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_methodology_visions', [
            'club_id' => 1, 'club_scope' => 'site', 'is_shipped' => 0,
        ] );
        $id = (int) $wpdb->insert_id;

        $req = new WP_REST_Request( 'PUT', self::VISION . '/' . $id );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [ 'style_of_play_key' => 'not_a_style' ] ) );
        $res = rest_do_request( $req );

        $this->assertSame( 400, $res->get_status(), 'invalid style is a 400' );
        $body = $res->get_data();
        $this->assertFalse( (bool) ( $body['success'] ?? true ) );
    }

    public function test_shipped_vision_refuses_update(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_methodology_visions', [
            'club_id' => 1, 'is_shipped' => 1,
        ] );
        $shipped_id = (int) $wpdb->insert_id;

        $update = new WP_REST_Request( 'PUT', self::VISION . '/' . $shipped_id );
        $update->set_header( 'Content-Type', 'application/json' );
        $update->set_body( wp_json_encode( [ 'notes' => [ 'nl' => 'Poging', 'en' => 'Attempt' ] ] ) );
        $this->assertSame( 409, rest_do_request( $update )->get_status(), 'shipped vision update is refused' );
    }

    public function test_primer_read_and_update_happy_path(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_methodology_framework_primers', [
            'club_id'    => 1,
            'club_scope' => 'site',
            'is_shipped' => 0,
            'title_json' => wp_json_encode( [ 'nl' => 'Raamwerk', 'en' => 'Framework' ] ),
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id );

        $list = rest_do_request( new WP_REST_Request( 'GET', self::PRIMER ) );
        $this->assertSame( 200, $list->get_status() );
        $list_body = $list->get_data();
        $this->assertEnvelopeSuccess( $list_body );
        $this->assertSame( $id, (int) ( $list_body['data']['framework_primer']['id'] ?? 0 ) );

        $get = rest_do_request( new WP_REST_Request( 'GET', self::PRIMER . '/' . $id ) );
        $this->assertSame( 200, $get->get_status() );
        $get_body = $get->get_data();
        $this->assertEnvelopeSuccess( $get_body );
        $this->assertSame( 'Raamwerk', $get_body['data']['title_i18n']['nl'] ?? null );
        $this->assertSame( 'Framework', $get_body['data']['title_i18n']['en'] ?? null );

        $update = new WP_REST_Request( 'PUT', self::PRIMER . '/' . $id );
        $update->set_header( 'Content-Type', 'application/json' );
        $update->set_body( wp_json_encode( [
            'intro'        => [ 'nl' => 'Inleiding NL', 'en' => 'Intro EN' ],
            'phases_intro' => [ 'nl' => 'Fasen NL', 'en' => 'Phases EN' ],
        ] ) );
        $update_res = rest_do_request( $update );
        $this->assertSame( 200, $update_res->get_status(), 'primer update succeeds' );
        $this->assertEnvelopeSuccess( $update_res->get_data() );

        $reread = rest_do_request( new WP_REST_Request( 'GET', self::PRIMER . '/' . $id ) )->get_data();
        $this->assertSame( 'Inleiding NL', $reread['data']['intro_i18n']['nl'] ?? null, 'intro round-trips' );
        $this->assertSame( 'Phases EN', $reread['data']['phases_intro_i18n']['en'] ?? null, 'phases intro round-trips' );
    }

    public function test_shipped_primer_refuses_update(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_methodology_framework_primers', [
            'club_id' => 1, 'is_shipped' => 1,
        ] );
        $shipped_id = (int) $wpdb->insert_id;

        $update = new WP_REST_Request( 'PUT', self::PRIMER . '/' . $shipped_id );
        $update->set_header( 'Content-Type', 'application/json' );
        $update->set_body( wp_json_encode( [ 'intro' => [ 'nl' => 'Poging', 'en' => 'Attempt' ] ] ) );
        $this->assertSame( 409, rest_do_request( $update )->get_status(), 'shipped primer update is refused' );
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
