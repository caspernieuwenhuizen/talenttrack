<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Modules\Invitations\Frontend\AcceptanceView;
use TT\Modules\Invitations\Frontend\GuardianContactView;
use TT\Modules\Invitations\GuardianContact\GuardianContactAuditLogger;
use TT\Modules\Invitations\GuardianContact\GuardianContactRequest;
use TT\Modules\Invitations\InvitationService;
use TT\Modules\Invitations\InvitationStatus;
use TT\Modules\Invitations\InvitationsRepository;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3794 — a family fills in their own guardian contact on a secure link.
 *
 * The link is unauthenticated and it writes to a minor's record, so the
 * assertions that matter are the ones about what it refuses: it works
 * once, it stops working when it expires, it shows nothing about the
 * child but their name, and it cannot be redeemed as an account
 * invitation.
 */
final class GuardianContactRequestTest extends WP_UnitTestCase {

    private int $club;
    private int $team;
    private int $player;
    private int $admin;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        GuardianContactAuditLogger::register();

        $this->club = (int) CurrentClub::id();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO11-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Guus',
            'last_name'  => 'Hermans',
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

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

    /* ---- the request ---------------------------------------------------- */

    public function test_staff_send_a_request_and_it_resolves_to_the_player(): void {
        wp_set_current_user( $this->admin );

        $result = GuardianContactRequest::send( $this->player, 'ouder@example.test' );
        $this->assertTrue( $result['ok'] );

        $request = GuardianContactRequest::resolve( (string) $result['token'] );
        $this->assertNotNull( $request );
        $this->assertSame( $this->player, (int) $request->target_player_id );
        $this->assertSame( GuardianContactRequest::KIND, (string) $request->kind );
    }

    public function test_a_request_needs_a_usable_address_and_a_real_player(): void {
        wp_set_current_user( $this->admin );

        $this->assertFalse( GuardianContactRequest::send( $this->player, 'not-an-address' )['ok'] );
        $this->assertFalse( GuardianContactRequest::send( 99999, 'ouder@example.test' )['ok'] );
    }

    /* ---- the answer ----------------------------------------------------- */

    public function test_the_answer_lands_on_the_record_and_leaves_blanks_alone(): void {
        $this->setGuardian( [ 'guardian_name' => 'Oude Naam', 'guardian_phone' => '010 1111111' ] );
        $request = $this->request();

        $result = GuardianContactRequest::submit( $request, [
            'guardian_name'  => 'Linda Hermans',
            'guardian_email' => 'linda@example.test',
            'guardian_phone' => '',
            'consent'        => true,
        ] );

        $this->assertTrue( $result['ok'] );
        $this->assertSame( 'Linda Hermans', $this->guardian( 'guardian_name' ) );
        $this->assertSame( 'linda@example.test', $this->guardian( 'guardian_email' ) );
        // Left blank, so left exactly as it was.
        $this->assertSame( '010 1111111', $this->guardian( 'guardian_phone' ) );
    }

    public function test_the_link_works_once(): void {
        $request = $this->request();

        $first = GuardianContactRequest::submit( $request, $this->answer() );
        $this->assertTrue( $first['ok'] );

        // The row the second visitor would still be holding.
        $second = GuardianContactRequest::submit( $request, [
            'guardian_name'  => 'Iemand Anders',
            'guardian_email' => 'anders@example.test',
            'consent'        => true,
        ] );

        $this->assertFalse( $second['ok'] );
        $this->assertSame( 'Linda Hermans', $this->guardian( 'guardian_name' ) );
        $this->assertNull( GuardianContactRequest::resolve( (string) $request->token ) );
    }

    public function test_an_expired_link_stops_working(): void {
        $request = $this->request();
        $this->expire( (int) $request->id );

        $this->assertNull( GuardianContactRequest::resolve( (string) $request->token ) );
    }

    public function test_an_unknown_token_resolves_to_nothing(): void {
        $this->assertNull( GuardianContactRequest::resolve( 'nottherealtokenatall00' ) );
        $this->assertNull( GuardianContactRequest::resolve( '../../etc/passwd' ) );
    }

    public function test_consent_and_a_way_to_be_reached_are_both_required(): void {
        $request = $this->request();

        $no_consent = GuardianContactRequest::submit( $request, [
            'guardian_name'  => 'Linda Hermans',
            'guardian_email' => 'linda@example.test',
            'consent'        => false,
        ] );
        $this->assertFalse( $no_consent['ok'] );

        $nothing_to_call = GuardianContactRequest::submit( $request, [
            'guardian_name' => 'Linda Hermans',
            'consent'       => true,
        ] );
        $this->assertFalse( $nothing_to_call['ok'] );

        // Neither refusal spent the link.
        $this->assertNotNull( GuardianContactRequest::resolve( (string) $request->token ) );
    }

    /* ---- the audit trail ------------------------------------------------ */

    public function test_the_write_is_audited_with_the_previous_value(): void {
        $this->setGuardian( [ 'guardian_phone' => '010 1111111' ] );
        $request = $this->request();

        GuardianContactRequest::submit( $request, [
            'guardian_name'  => 'Linda Hermans',
            'guardian_email' => 'linda@example.test',
            'guardian_phone' => '06 12345678',
            'consent'        => true,
        ] );

        global $wpdb;
        $payload = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log
              WHERE action = 'guardian_contact.submitted' AND entity_type = 'player' AND entity_id = %d
              ORDER BY id DESC LIMIT 1",
            $this->player
        ) );
        $decoded = json_decode( $payload, true );

        $this->assertIsArray( $decoded );
        $this->assertSame( '010 1111111', $decoded['changes']['guardian_phone']['from'] );
        $this->assertSame( '06 12345678', $decoded['changes']['guardian_phone']['to'] );
    }

    /* ---- what the public page may show ---------------------------------- */

    public function test_the_page_shows_the_childs_name_and_nothing_else_about_them(): void {
        $this->setGuardian( [ 'guardian_email' => 'de.andere.ouder@example.test' ] );
        $request = $this->request();

        $_GET = [ 'tt_view' => GuardianContactRequest::VIEW_SLUG, 'token' => (string) $request->token ];
        ob_start();
        GuardianContactView::render();
        $html = (string) ob_get_clean();
        $_GET = [];

        $this->assertStringContainsString( 'Guus Hermans', $html );
        $this->assertStringContainsString( 'guardian_name', $html );
        // The details already on file may belong to the other parent.
        $this->assertStringNotContainsString( 'de.andere.ouder@example.test', $html );
        $this->assertStringNotContainsString( 'JO11-1', $html );
    }

    public function test_a_spent_link_renders_the_same_sentence_as_an_unknown_one(): void {
        $request = $this->request();
        GuardianContactRequest::submit( $request, $this->answer() );

        $spent   = $this->renderToken( (string) $request->token );
        $unknown = $this->renderToken( 'nottherealtokenatall00' );

        $this->assertStringContainsString( 'no longer valid', $spent );
        $this->assertSame( $spent, $unknown );
    }

    /* ---- it is not an invitation ---------------------------------------- */

    public function test_the_token_cannot_be_redeemed_as_an_account_invitation(): void {
        $request = $this->request();

        $accepted = ( new InvitationService() )->accept( $request, [
            'recovery_email' => 'someone@example.test',
            'password'       => 'longenoughpassword',
        ] );
        $this->assertFalse( $accepted['ok'] );

        $_GET = [ 'tt_view' => 'accept-invite', 'token' => (string) $request->token ];
        ob_start();
        AcceptanceView::render();
        $html = (string) ob_get_clean();
        $_GET = [];

        $this->assertStringContainsString( 'invalid', strtolower( $html ) );
        $this->assertStringNotContainsString( 'name="password"', $html );

        // And the request itself is untouched by the attempt.
        $this->assertNotNull( GuardianContactRequest::resolve( (string) $request->token ) );
    }

    public function test_the_request_does_not_show_up_in_the_invitations_list(): void {
        $this->request();
        foreach ( ( new InvitationsRepository() )->listAll() as $row ) {
            $this->assertNotSame( GuardianContactRequest::KIND, (string) $row->kind );
        }
    }

    /* ---- REST ----------------------------------------------------------- */

    public function test_the_staff_route_is_capability_gated(): void {
        wp_set_current_user( 0 );
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/players/' . $this->player . '/guardian-contact-request' );
        $req->set_body_params( [ 'email' => 'ouder@example.test' ] );
        $this->assertContains( rest_do_request( $req )->get_status(), [ 401, 403 ] );

        wp_set_current_user( $this->admin );
        $this->assertSame( 201, rest_do_request( $req )->get_status() );
    }

    public function test_the_public_read_answers_with_the_name_only(): void {
        $request = $this->request();

        wp_set_current_user( 0 );
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/guardian-contact/' . $request->token ) );
        $this->assertSame( 200, $res->get_status() );

        $data = (array) $res->get_data()['data'];
        $this->assertSame( 'Guus Hermans', $data['player_name'] );
        $this->assertArrayNotHasKey( 'player_id', $data );
        $this->assertArrayNotHasKey( 'team_id', $data );
    }

    public function test_an_invalid_token_answers_404_on_both_public_routes(): void {
        wp_set_current_user( 0 );

        $read = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/guardian-contact/nottherealtokenatall00' ) );
        $this->assertSame( 404, $read->get_status() );

        $write = new WP_REST_Request( 'POST', '/talenttrack/v1/guardian-contact/nottherealtokenatall00' );
        $write->set_body_params( $this->answer() );
        $this->assertSame( 404, rest_do_request( $write )->get_status() );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @return array<string,mixed> */
    private function answer(): array {
        return [
            'guardian_name'  => 'Linda Hermans',
            'guardian_email' => 'linda@example.test',
            'guardian_phone' => '06 12345678',
            'consent'        => true,
        ];
    }

    private function request(): object {
        wp_set_current_user( $this->admin );
        $result = GuardianContactRequest::send( $this->player, 'ouder@example.test' );
        $this->assertTrue( $result['ok'] );
        wp_set_current_user( 0 );

        $request = GuardianContactRequest::resolve( (string) $result['token'] );
        $this->assertNotNull( $request );
        return $request;
    }

    private function renderToken( string $token ): string {
        $_GET = [ 'tt_view' => GuardianContactRequest::VIEW_SLUG, 'token' => $token ];
        ob_start();
        GuardianContactView::render();
        $html = (string) ob_get_clean();
        $_GET = [];
        return $html;
    }

    /** @param array<string,string> $fields */
    private function setGuardian( array $fields ): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", $fields, [ 'id' => $this->player ] );
    }

    private function guardian( string $field ): string {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT {$field} FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $this->player
        ) );
    }

    private function expire( int $request_id ): void {
        ( new InvitationsRepository() )->update( $request_id, [
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ),
            'status'     => InvitationStatus::PENDING,
        ] );
    }
}
