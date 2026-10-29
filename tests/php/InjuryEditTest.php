<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Journey\Frontend\FrontendInjuriesView;

/**
 * #4255 — an injury recorded at the touchline can be completed later.
 *
 * The write side already existed (`PUT /player-injuries/{id}`); what was
 * missing was a screen that reached it, and the date rule the wizard
 * enforced but the PUT did not. These lock the partial-update contract
 * (CLAUDE.md §6), the date rule on the domain write path, the audit row,
 * and who is offered the form.
 */
final class InjuryEditTest extends WP_UnitTestCase {

    private int $teamId   = 0;
    private int $playerId = 0;
    private int $admin    = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Injury Edit XI' ] );
        $this->teamId = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => 'Edit',
            'last_name'  => 'Tester',
            'team_id'    => $this->teamId,
            'status'     => 'active',
        ] );
        $this->playerId = (int) $wpdb->insert_id;

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        unset( $_GET['action'], $_GET['id'] );
        parent::tear_down();
    }

    public function test_a_partial_put_leaves_the_notes_alone_and_is_audited(): void {
        $id = $this->injury( 'Fell on the touchline' );
        $body_part = $this->firstLookup( 'body_part' );

        $res = $this->put( $id, [ 'body_part_lookup_id' => $body_part ] );
        $this->assertSame( 200, $res->get_status() );

        $row = ( new InjuryRepository() )->find( $id );
        $this->assertNotNull( $row );
        $this->assertSame( $body_part, (int) $row->body_part_lookup_id );
        $this->assertSame( 'Fell on the touchline', (string) $row->notes, 'an omitted field is left alone' );
        $this->assertSame( '2026-09-20', (string) $row->started_on );

        global $wpdb;
        $payload = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log
              WHERE action = 'player.injury_updated' AND entity_type = 'player_injury' AND entity_id = %d
              ORDER BY id DESC LIMIT 1",
            $id
        ) );
        $decoded = json_decode( $payload, true );
        $this->assertIsArray( $decoded, 'the edit must be written to the audit log' );
        $this->assertSame( [ 'body_part_lookup_id' ], $decoded['fields'] );
    }

    public function test_an_expected_return_before_the_date_of_injury_is_refused(): void {
        $id = $this->injury( '' );

        $res = $this->put( $id, [ 'expected_return' => '2026-09-01' ] );
        $this->assertSame( 400, $res->get_status() );

        $row = ( new InjuryRepository() )->find( $id );
        $this->assertNotNull( $row );
        $this->assertEmpty( $row->expected_return, 'a refused edit writes nothing' );

        $ok = $this->put( $id, [ 'expected_return' => '2026-10-15' ] );
        $this->assertSame( 200, $ok->get_status(), 'a return after the injury is accepted' );
    }

    public function test_the_form_posting_empty_fields_clears_them_rather_than_storing_blanks(): void {
        $id = ( new InjuryRepository() )->create( [
            'player_id'          => $this->playerId,
            'started_on'         => '2026-09-20',
            'expected_return'    => '2026-10-15',
            'severity_lookup_id' => $this->firstLookup( 'injury_severity' ),
        ] );

        $res = $this->put( $id, [ 'expected_return' => '', 'severity_lookup_id' => '0', 'notes' => '' ] );
        $this->assertSame( 200, $res->get_status() );

        $row = ( new InjuryRepository() )->find( $id );
        $this->assertNotNull( $row );
        $this->assertNull( $row->expected_return );
        $this->assertNull( $row->severity_lookup_id );
    }

    public function test_the_edit_form_is_offered_to_a_writer_and_refused_to_anyone_else(): void {
        $id = $this->injury( 'Hamstring' );
        $_GET['action'] = 'edit';
        $_GET['id']     = (string) $id;

        ob_start();
        FrontendInjuriesView::render( $this->admin, true );
        $html = (string) ob_get_clean();
        $this->assertStringContainsString( 'data-rest-path="player-injuries/' . $id . '"', $html );
        $this->assertStringContainsString( 'data-rest-method="PUT"', $html );
        $this->assertStringContainsString( 'tt-form-actions', $html, 'Save + Cancel (CLAUDE.md §6)' );
        $this->assertStringNotContainsString( 'name="started_on"', $html, 'the day of injury is not edited' );

        $reader = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $reader );
        ob_start();
        FrontendInjuriesView::render( $reader, false );
        $refused = (string) ob_get_clean();
        $this->assertStringNotContainsString( 'player-injuries/', $refused );
        $this->assertStringContainsString( 'You cannot edit this injury.', $refused );
    }

    public function test_the_overview_row_links_the_edit_form_for_a_writer(): void {
        $id = $this->injury( '' );

        ob_start();
        FrontendInjuriesView::render( $this->admin, true );
        $html = (string) ob_get_clean();
        $this->assertStringContainsString( 'action=edit&#038;id=' . $id, $html );
    }

    private function injury( string $notes ): int {
        $id = ( new InjuryRepository() )->create( [
            'player_id'  => $this->playerId,
            'started_on' => '2026-09-20',
            'notes'      => $notes,
        ] );
        $this->assertGreaterThan( 0, $id, 'the fixture injury must be written' );
        return $id;
    }

    /** @param array<string, mixed> $body */
    private function put( int $id, array $body ): \WP_REST_Response {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/player-injuries/' . $id );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        return rest_do_request( $request );
    }

    private function firstLookup( string $type ): int {
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_lookups WHERE lookup_type = %s ORDER BY id ASC LIMIT 1",
            $type
        ) );
        $this->assertGreaterThan( 0, $id, "{$type} lookup must be seeded" );
        return $id;
    }
}
