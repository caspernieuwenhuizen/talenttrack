<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Identity\ContactSync;
use TT\Infrastructure\Identity\PhoneMeta;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\FrontendMySettingsView;

/**
 * #3684 — a parent can give the academy their own phone number.
 *
 * Before this there was no field on My settings and no route, so an admin
 * typed every guardian's mobile in by hand. Two assertions carry the
 * weight:
 *
 *  - the write has no way to name anybody else. `PATCH me` only ever
 *    touches `get_current_user_id()`, so the test sends a user id both in
 *    the body and on the query string and shows neither lands;
 *  - a number that does not normalize is refused and the stored one is
 *    left alone. `PhoneMeta::set()` reads an empty normalization as
 *    "clear", and `isValid()` rejects a leading zero, so a Dutch mobile
 *    typed as `06 12345678` would otherwise wipe a working number.
 */
final class MeOwnPhoneTest extends WP_UnitTestCase {

    private const GOOD     = '+31 6 12345678';
    private const GOOD_E164 = '+31612345678';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        ContactSync::init();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $_POST = [];
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /* ---- REST ---------------------------------------------------------- */

    public function test_the_route_is_registered_and_refuses_a_logged_out_caller(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/talenttrack/v1/me', $routes );

        $methods = [];
        foreach ( $routes['/talenttrack/v1/me'] as $handler ) {
            $methods = array_merge( $methods, array_keys( (array) $handler['methods'] ) );
        }
        $this->assertContains( 'PATCH', $methods );

        wp_set_current_user( 0 );
        $this->assertContains( $this->patch( [ 'phone' => self::GOOD ] )->get_status(), [ 401, 403 ] );
    }

    public function test_a_parent_saves_their_own_number_and_reads_it_back(): void {
        $parent = $this->parent();
        wp_set_current_user( $parent );

        $res = $this->patch( [ 'phone' => self::GOOD ] );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( self::GOOD_E164, $res->get_data()['data']['phone'] );

        $this->assertSame( self::GOOD_E164, PhoneMeta::get( $parent ) );
        $this->assertSame( self::GOOD_E164, $this->getMe()['phone'] );
    }

    public function test_an_unusable_number_is_refused_and_leaves_the_stored_one_alone(): void {
        $parent = $this->parent();
        wp_set_current_user( $parent );
        PhoneMeta::set( $parent, self::GOOD_E164 );

        // A Dutch mobile without its country code: this is the input that
        // must never be read as "clear my number".
        $res = $this->patch( [ 'phone' => '06 12345678' ] );

        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'invalid_phone', $res->get_data()['errors'][0]['code'] );
        $this->assertSame( self::GOOD_E164, PhoneMeta::get( $parent ) );
    }

    public function test_null_or_blank_clears_the_number(): void {
        $parent = $this->parent();
        wp_set_current_user( $parent );

        PhoneMeta::set( $parent, self::GOOD_E164 );
        $this->assertSame( 200, $this->patch( [ 'phone' => null ] )->get_status() );
        $this->assertSame( '', PhoneMeta::get( $parent ) );

        PhoneMeta::set( $parent, self::GOOD_E164 );
        $res = $this->patch( [ 'phone' => '' ] );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( '', $res->get_data()['data']['phone'] );
        $this->assertFalse( PhoneMeta::exists( $parent ) );
    }

    public function test_a_body_with_no_phone_key_is_not_a_silent_success(): void {
        wp_set_current_user( $this->parent() );

        $res = $this->patch( [] );
        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'nothing_to_update', $res->get_data()['errors'][0]['code'] );
    }

    public function test_the_route_cannot_be_pointed_at_another_account(): void {
        $parent    = $this->parent();
        $other     = $this->parent();
        PhoneMeta::set( $other, '+31201234567' );

        wp_set_current_user( $parent );

        // In the body: refused outright by the #3689 body contract.
        $res = $this->patch( [ 'phone' => self::GOOD, 'user_id' => $other ] );
        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'unknown_field', $res->get_data()['errors'][0]['code'] );
        $this->assertSame( '', PhoneMeta::get( $parent ) );

        // On the query string, where an undeclared parameter is simply not
        // read: the write still lands on the caller and nowhere else.
        $req = new WP_REST_Request( 'PATCH', '/talenttrack/v1/me' );
        $req->set_query_params( [ 'user_id' => (string) $other, 'id' => (string) $other ] );
        $req->set_body_params( [ 'phone' => self::GOOD ] );
        $this->assertSame( 200, rest_do_request( $req )->get_status() );

        $this->assertSame( self::GOOD_E164, PhoneMeta::get( $parent ) );
        $this->assertSame( '+31201234567', PhoneMeta::get( $other ) );
    }

    public function test_the_number_reaches_a_linked_person_row(): void {
        $parent = $this->parent();
        $person = $this->person( $parent );

        wp_set_current_user( $parent );
        $this->assertSame( 200, $this->patch( [ 'phone' => self::GOOD ] )->get_status() );

        $this->assertSame( self::GOOD_E164, $this->personPhone( $person ) );
    }

    /* ---- My settings --------------------------------------------------- */

    public function test_my_settings_offers_the_field_prefilled(): void {
        $parent = $this->parent();
        PhoneMeta::set( $parent, self::GOOD_E164 );

        $html = $this->renderFor( $parent );

        $this->assertStringContainsString( 'name="tt_phone"', $html );
        $this->assertStringContainsString( 'type="tel"', $html );
        $this->assertStringContainsString( 'inputmode="tel"', $html );
        $this->assertStringContainsString( 'autocomplete="tel"', $html );
        $this->assertStringContainsString( 'value="' . self::GOOD_E164 . '"', $html );
    }

    public function test_saving_the_profile_stores_the_number_and_syncs_the_person_row(): void {
        $parent = $this->parent();
        $person = $this->person( $parent );

        $html = $this->postProfile( $parent, [ 'tt_phone' => self::GOOD ] );

        $this->assertStringContainsString( 'Profile saved.', $html );
        $this->assertSame( self::GOOD_E164, PhoneMeta::get( $parent ) );
        $this->assertSame( self::GOOD_E164, $this->personPhone( $person ) );
        // And the page shows it back.
        $this->assertStringContainsString( 'value="' . self::GOOD_E164 . '"', $html );
    }

    public function test_an_unusable_number_on_the_form_refuses_the_save(): void {
        $parent = $this->parent();
        PhoneMeta::set( $parent, self::GOOD_E164 );

        $html = $this->postProfile( $parent, [ 'tt_phone' => '06 12345678' ] );

        // The field's own hint also says "country code", so assert on the
        // error's opening words rather than a substring both share.
        $this->assertStringContainsString( 'Enter your phone number with its country code', $html );
        $this->assertStringNotContainsString( 'Profile saved.', $html );
        $this->assertSame( self::GOOD_E164, PhoneMeta::get( $parent ) );
    }

    public function test_an_empty_box_removes_the_number(): void {
        $parent = $this->parent();
        PhoneMeta::set( $parent, self::GOOD_E164 );

        $this->postProfile( $parent, [ 'tt_phone' => '' ] );

        $this->assertSame( '', PhoneMeta::get( $parent ) );
    }

    /* ---- helpers ------------------------------------------------------- */

    private function parent(): int {
        return (int) self::factory()->user->create( [
            'role'       => 'tt_parent',
            'user_email' => 'guardian' . wp_rand( 1000, 999999 ) . '@example.test',
        ] );
    }

    private function person( int $user_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Ada',
            'last_name'  => 'Vermeer',
            'wp_user_id' => $user_id,
            'role_type'  => 'parent',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function personPhone( int $person_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT phone FROM {$wpdb->prefix}tt_people WHERE id = %d",
            $person_id
        ) );
    }

    /** @param array<string,mixed> $body */
    private function patch( array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PATCH', '/talenttrack/v1/me' );
        $req->set_header( 'content-type', 'application/json' );
        // Cast so an empty body serializes as `{}` rather than `[]`.
        $req->set_body( (string) wp_json_encode( (object) $body ) );
        return rest_do_request( $req );
    }

    /** @return array<string,mixed> */
    private function getMe(): array {
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/me' ) );
        $this->assertSame( 200, $res->get_status() );
        return (array) $res->get_data()['data'];
    }

    private function renderFor( int $user_id ): string {
        wp_set_current_user( $user_id );
        ob_start();
        FrontendMySettingsView::render();
        return (string) ob_get_clean();
    }

    /**
     * `handlePost()` returns before reading the action unless the request is
     * a POST, so filling `$_POST` alone exercises nothing.
     *
     * @param array<string,mixed> $extra
     */
    private function postProfile( int $user_id, array $extra ): string {
        wp_set_current_user( $user_id );
        $user = get_userdata( $user_id );

        $was = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $extra + [
            'tt_my_settings_action'        => 'update_profile',
            'tt_my_settings_profile_nonce' => wp_create_nonce( 'tt_my_settings_profile' ),
            'first_name'                   => 'Ada',
            'last_name'                    => 'Vermeer',
            'user_email'                   => $user ? (string) $user->user_email : 'guardian@example.test',
        ];
        try {
            return $this->renderFor( $user_id );
        } finally {
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = $was;
        }
    }
}
