<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Identity\OwnContactDetails;
use TT\Infrastructure\Identity\PhoneMeta;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\FrontendMySettingsView;

/**
 * #3691 — a parent can see every contact detail the academy holds about
 * them, and nothing it holds about anybody else.
 *
 * The regression that matters is #1725's: the guardian columns on a
 * child's file may describe the other parent, and the player detail
 * view's guardians card is staff-only for exactly that reason. So the
 * assertions here come in pairs — the caller's own value is returned,
 * the co-guardian's value sitting in the next column is not.
 */
final class MeContactDetailsTest extends WP_UnitTestCase {

    private const OWN_EMAIL = 'ada.vermeer@example.test';
    private const OWN_PHONE = '+31612345678';

    private int $club;
    private int $team;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        $this->club = (int) CurrentClub::id();
        $this->team = $this->team();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /* ---- REST ---------------------------------------------------------- */

    public function test_the_route_is_registered_and_refuses_a_logged_out_caller(): void {
        $this->assertArrayHasKey( '/talenttrack/v1/me/contact-details', rest_get_server()->get_routes() );

        wp_set_current_user( 0 );
        $this->assertContains( $this->get()->get_status(), [ 401, 403 ] );
    }

    public function test_a_parent_reads_their_account_and_their_person_row(): void {
        $parent = $this->parent();
        PhoneMeta::set( $parent, self::OWN_PHONE );
        $this->person( $parent );

        wp_set_current_user( $parent );
        $data = $this->payload();

        $this->assertSame( self::OWN_EMAIL, $data['account']['email'] );
        $this->assertSame( self::OWN_PHONE, $data['account']['phone'] );
        $this->assertNotNull( $data['person'] );
        $this->assertSame( 'Ada Vermeer', $data['person']['name'] );
        $this->assertSame( self::OWN_EMAIL, $data['person']['email'] );
    }

    public function test_an_account_with_no_person_row_still_answers(): void {
        wp_set_current_user( $this->parent() );

        $data = $this->payload();
        $this->assertNull( $data['person'] );
        $this->assertSame( [], $data['children'] );
    }

    public function test_only_the_callers_own_guardian_fields_come_back(): void {
        $parent = $this->parent();
        PhoneMeta::set( $parent, self::OWN_PHONE );

        // The file names the caller's email, and the OTHER parent's name
        // and number — the #1725 case.
        $this->child( $parent, [
            'guardian_name'  => 'Bram Vermeer',
            'guardian_email' => self::OWN_EMAIL,
            'guardian_phone' => '+31209876543',
        ] );

        wp_set_current_user( $parent );
        $fields = $this->payload()['children'][0]['fields'];

        $this->assertSame( [ 'guardian_email' => self::OWN_EMAIL ], $fields );
        $this->assertArrayNotHasKey( 'guardian_name', $fields );
        $this->assertArrayNotHasKey( 'guardian_phone', $fields );
    }

    public function test_a_file_holding_only_a_co_guardian_returns_no_fields_at_all(): void {
        $parent = $this->parent();
        PhoneMeta::set( $parent, self::OWN_PHONE );

        $this->child( $parent, [
            'guardian_name'  => 'Bram Vermeer',
            'guardian_email' => 'bram.vermeer@example.test',
            'guardian_phone' => '+31209876543',
        ] );

        wp_set_current_user( $parent );
        $child = $this->payload()['children'][0];

        $this->assertSame( [], $child['fields'], 'A co-guardian\'s details never leave the database.' );
        $this->assertNotSame( '', $child['player_name'] );
    }

    public function test_matching_ignores_case_whitespace_and_phone_formatting(): void {
        $parent = $this->parent();
        PhoneMeta::set( $parent, self::OWN_PHONE );

        $this->child( $parent, [
            'guardian_name'  => '  ADA   VERMEER ',
            'guardian_email' => 'Ada.Vermeer@Example.Test',
            // National form, as an admin types it at the gate.
            'guardian_phone' => '06 12 34 56 78',
        ] );

        wp_set_current_user( $parent );
        $fields = $this->payload()['children'][0]['fields'];

        $this->assertArrayHasKey( 'guardian_name', $fields );
        $this->assertArrayHasKey( 'guardian_email', $fields );
        $this->assertArrayHasKey( 'guardian_phone', $fields );
    }

    public function test_a_parent_sees_nothing_of_another_familys_record(): void {
        $parent = $this->parent();
        $other  = $this->parent( 'other.parent@example.test' );
        $this->child( $other, [ 'guardian_email' => 'other.parent@example.test' ] );

        wp_set_current_user( $parent );
        $this->assertSame( [], $this->payload()['children'] );
    }

    public function test_the_route_cannot_be_pointed_at_another_player(): void {
        $parent     = $this->parent();
        $other      = $this->parent( 'other.parent@example.test' );
        $other_kid  = $this->child( $other, [ 'guardian_email' => 'other.parent@example.test' ] );

        wp_set_current_user( $parent );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/me/contact-details' );
        $req->set_query_params( [ 'player_id' => (string) $other_kid, 'id' => (string) $other_kid ] );
        $res = rest_do_request( $req );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( [], ( (array) $res->get_data()['data'] )['children'] );
    }

    /* ---- My settings --------------------------------------------------- */

    public function test_my_settings_shows_the_details_and_commits_nothing(): void {
        $parent = $this->parent();
        PhoneMeta::set( $parent, self::OWN_PHONE );
        $this->child( $parent, [
            'guardian_email' => self::OWN_EMAIL,
            'guardian_phone' => '+31209876543',
        ] );

        wp_set_current_user( $parent );
        ob_start();
        FrontendMySettingsView::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'tt-msettings-held', $html );
        $this->assertStringContainsString( 'What the academy holds about you', $html );
        $this->assertStringContainsString( self::OWN_EMAIL, $html );
        $this->assertStringNotContainsString( '+31209876543', $html );
    }

    /* ---- the aggregator, directly -------------------------------------- */

    public function test_an_unknown_account_gets_an_empty_answer_rather_than_a_crash(): void {
        $held = OwnContactDetails::forUser( 0 );

        $this->assertNull( $held['person'] );
        $this->assertSame( [], $held['children'] );
        $this->assertSame( '', $held['account']['email'] );
    }

    /* ---- helpers ------------------------------------------------------- */

    private function parent( string $email = self::OWN_EMAIL ): int {
        return (int) self::factory()->user->create( [
            'role'         => 'tt_parent',
            'user_email'   => $email,
            'first_name'   => 'Ada',
            'last_name'    => 'Vermeer',
            'display_name' => 'Ada Vermeer',
        ] );
    }

    private function person( int $user_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Ada',
            'last_name'  => 'Vermeer',
            'email'      => self::OWN_EMAIL,
            'wp_user_id' => $user_id,
            'role_type'  => 'parent',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function team(): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [
            'club_id'   => (int) CurrentClub::id(),
            'name'      => 'JO11-1',
            'age_group' => 'U11',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,string> $guardian
     */
    private function child( int $parent_user_id, array $guardian ): int {
        global $wpdb;

        $wpdb->insert( "{$wpdb->prefix}tt_players", $guardian + [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Sem',
            'last_name'  => 'Vermeer',
            'status'     => 'active',
        ] );
        $player_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $player_id,
            'parent_user_id' => $parent_user_id,
        ] );

        return $player_id;
    }

    private function get(): \WP_REST_Response {
        return rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/me/contact-details' ) );
    }

    /** @return array<string,mixed> */
    private function payload(): array {
        $res = $this->get();
        $this->assertSame( 200, $res->get_status() );
        return (array) $res->get_data()['data'];
    }
}
