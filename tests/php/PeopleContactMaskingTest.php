<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\People\Admin\PeoplePage;
use TT\Shared\Frontend\FrontendPersonDetailView;

/**
 * #4715 — on a people list and a person's page, a guardian's e-mail and
 * phone follow the sensitive-field registry: the people around the child
 * see them; any other reader of people, the read-only observer first, sees
 * "Hidden", and the REST answer leaves them out.
 *
 * Every refusal is paired with a reader who keeps the contact.
 */
final class PeopleContactMaskingTest extends WP_UnitTestCase {

    private const EMAIL = 'ouder.4715@example.org';
    private const PHONE = '0687654321';

    private int $club     = 0;
    private int $team     = 0;
    private int $guardian = 0;
    private int $observer = 0;
    private int $hod      = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO12-2' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Tess', 'last_name' => 'Vos', 'status' => 'active',
        ] );
        $player = (int) $wpdb->insert_id;

        $parent_user = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$p}tt_player_parents", [ 'club_id' => $this->club, 'player_id' => $player, 'parent_user_id' => $parent_user ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Ouder', 'last_name' => 'Vos', 'email' => self::EMAIL, 'phone' => self::PHONE,
            'role_type' => 'parent', 'wp_user_id' => $parent_user, 'status' => 'active',
        ] );
        $this->guardian = (int) $wpdb->insert_id;

        $this->observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->hod      = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        AuthorizationService::flushCache();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_the_people_list_shows_hidden_and_leaves_the_contact_out(): void {
        $observer = $this->listRow( $this->observer );
        $this->assertSame( 'Ouder', $observer['first_name'] ?? null, 'the observer gets the person' );
        $this->assertArrayNotHasKey( 'email', $observer );
        $this->assertArrayNotHasKey( 'email_link_html', $observer );
        $this->assertArrayNotHasKey( 'phone', $observer );
        $this->assertStringContainsString( 'tt-masked-value', (string) ( $observer['email_cell_html'] ?? '' ), '"Hidden" in the e-mail cell' );
        $this->assertStringNotContainsString( self::EMAIL, (string) wp_json_encode( $observer ) );

        $hod = $this->listRow( $this->hod );
        $this->assertSame( self::EMAIL, $hod['email'] ?? null, 'HoD keeps the e-mail' );
        $this->assertStringContainsString( self::EMAIL, (string) ( $hod['email_cell_html'] ?? '' ) );
    }

    public function test_the_person_page_shows_hidden_for_the_observer(): void {
        $hod = $this->renderPerson( $this->hod );
        $this->assertStringContainsString( self::EMAIL, $hod );
        $this->assertStringContainsString( self::PHONE, $hod );

        $html = $this->renderPerson( $this->observer );
        $this->assertStringContainsString( 'Ouder', $html, 'the page renders for the observer' );
        $this->assertStringContainsString( 'tt-masked-value', $html );
        $this->assertStringNotContainsString( self::EMAIL, $html );
        $this->assertStringNotContainsString( self::PHONE, $html );
    }

    public function test_the_wp_admin_people_list_shows_hidden_for_the_observer(): void {
        $this->assertStringContainsString( self::EMAIL, $this->renderAdminList( $this->hod ) );
        $html = $this->renderAdminList( $this->observer );
        $this->assertStringNotContainsString( self::EMAIL, $html );
        $this->assertStringNotContainsString( self::PHONE, $html );
    }

    /**
     * Search and sort follow the same rule as the rows: a search on the
     * guardian's address does not find the guardian for the observer, and
     * sorting on e-mail falls back to the last name; HoD's search does.
     */
    public function test_search_and_sort_follow_the_contact_rule(): void {
        $this->assertNotContains( $this->guardian, $this->searchIds( $this->observer, 'ouder.4715' ), 'the observer finds nothing by the address' );
        $this->assertContains( $this->guardian, $this->searchIds( $this->hod, 'ouder.4715' ), 'HoD still does' );
        $this->assertContains( $this->guardian, $this->searchIds( $this->observer, 'Vos' ), 'a name search still works' );

        wp_set_current_user( $this->observer );
        AuthorizationService::flushCache();
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/people' );
        $request->set_param( 'orderby', 'email' );
        $this->assertSame( 200, $this->serve( $request )->get_status(), 'sorting on e-mail still answers' );

        $this->assertStringNotContainsString( 'Ouder Vos', $this->renderAdminList( $this->observer, 'ouder.4715' ), 'wp-admin: no match for the observer' );
        $this->assertStringContainsString( 'Ouder Vos', $this->renderAdminList( $this->hod, 'ouder.4715' ), 'wp-admin: HoD still finds them' );
    }

    /** D-48: a parent-type person with no linked child keeps their contact. */
    public function test_an_unlinked_parent_keeps_their_contact(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Los', 'last_name' => 'Ouder', 'email' => 'los.4715@example.org',
            'role_type' => 'parent', 'status' => 'active',
        ] );
        $unlinked = (int) $wpdb->insert_id;
        AuthorizationService::flushCache();

        $this->guardian = $unlinked;
        $this->assertSame( 'los.4715@example.org', $this->listRow( $this->observer )['email'] ?? null, 'on the list' );
        $this->assertStringContainsString( 'los.4715@example.org', $this->renderPerson( $this->observer ), 'on the person page' );
    }

    // ---- fixtures ------------------------------------------------------

    /** @return list<int> */
    private function searchIds( int $user_id, string $search ): array {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/people' );
        $request->set_param( 'search', $search );
        $data = (array) json_decode( (string) wp_json_encode( $this->serve( $request )->get_data() ), true );
        return array_map( static fn( $row ): int => (int) ( $row['id'] ?? 0 ), (array) ( $data['data']['rows'] ?? [] ) );
    }

    private function serve( WP_REST_Request $request ): WP_REST_Response {
        $server   = rest_get_server();
        $response = rest_ensure_response( $server->dispatch( $request ) );
        $response = apply_filters( 'rest_post_dispatch', $response, $server, $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        return $response;
    }

    /** @return array<string, mixed> */
    private function listRow( int $user_id ): array {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/people' );
        $request->set_param( 'per_page', 100 );
        $response = $this->serve( $request );
        $this->assertSame( 200, $response->get_status() );
        $data = (array) json_decode( (string) wp_json_encode( $response->get_data() ), true );
        foreach ( (array) ( $data['data']['rows'] ?? [] ) as $row ) {
            if ( (int) ( $row['id'] ?? 0 ) === $this->guardian ) return (array) $row;
        }
        $this->fail( 'the guardian is not on the list' );
    }

    private function renderPerson( int $user_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        ob_start();
        FrontendPersonDetailView::render( $this->guardian, $user_id, user_can( $user_id, 'administrator' ) );
        return (string) ob_get_clean();
    }

    private function renderAdminList( int $user_id, string $search = '' ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        if ( $search !== '' ) $_GET['s'] = $search;
        ob_start();
        PeoplePage::render();
        $html = (string) ob_get_clean();
        unset( $_GET['s'] );
        return $html;
    }
}
