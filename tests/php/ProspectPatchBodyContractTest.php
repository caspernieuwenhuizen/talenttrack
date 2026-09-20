<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Prospects\Repositories\ProspectsRepository;

/**
 * #3868 / #3844 — `PATCH /prospects/{id}` answered `200 changed: false`
 * for a body it had not understood, so a scout who had recorded where a
 * consent request went was told it was saved and it was not.
 *
 * The route now declares its args and refuses an undeclared key with
 * `400 unknown_field`, the way the sibling scouting-visit routes already
 * do, and `scouting_notes` — allowed by the repository all along — is one
 * of the keys it takes.
 */
final class ProspectPatchBodyContractTest extends WP_UnitTestCase {

    private int $scout = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->scout = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        wp_set_current_user( $this->scout );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_patch_route_declares_the_fields_it_accepts(): void {
        $args = [];
        foreach ( rest_get_server()->get_routes()['/talenttrack/v1/prospects/(?P<id>\d+)'] ?? [] as $handler ) {
            if ( ! empty( $handler['methods']['PATCH'] ) ) $args = $handler['args'];
        }

        foreach ( [ 'parent_name', 'parent_email', 'parent_phone', 'consent_given_at', 'scouting_visit_id', 'scouting_notes' ] as $field ) {
            $this->assertArrayHasKey( $field, $args, "{$field} is not declared" );
        }
    }

    public function test_an_undeclared_key_is_refused_and_nothing_is_written(): void {
        $id = $this->prospect();

        [ $data, $status ] = $this->send( 'PATCH', 'prospects/' . $id, [ 'notes' => 'Naar de jeugdcoordinator gemaild.' ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertSame( [ 'notes' ], $data['errors'][0]['details']['fields'] ?? null );
        $this->assertContains( 'scouting_notes', $data['errors'][0]['details']['allowed'] ?? [] );

        $row = (array) ( new ProspectsRepository() )->find( $id );
        $this->assertSame( 'Eerste indruk.', (string) $row['scouting_notes'] );
    }

    public function test_a_body_mixing_a_known_and_an_unknown_key_is_refused_whole(): void {
        $id = $this->prospect();

        [ , $status ] = $this->send( 'PATCH', 'prospects/' . $id, [
            'parent_name' => 'Ouder Van Dijk',
            'notes'       => 'Typefout in de veldnaam.',
        ] );

        $this->assertSame( 400, $status );
        $row = (array) ( new ProspectsRepository() )->find( $id );
        $this->assertNull( $row['parent_name'], 'the valid half of a refused body must not be written' );
    }

    public function test_an_omitted_field_is_left_alone(): void {
        $id = $this->prospect();

        [ , $status ] = $this->send( 'PATCH', 'prospects/' . $id, [ 'parent_name' => 'Ouder Van Dijk' ] );
        $this->assertSame( 200, $status );

        $row = (array) ( new ProspectsRepository() )->find( $id );
        $this->assertSame( 'Ouder Van Dijk', (string) $row['parent_name'] );
        $this->assertSame( 'Eerste indruk.', (string) $row['scouting_notes'], 'a field the body did not carry stays as it was' );
    }

    public function test_the_scouting_notes_can_be_updated_and_cleared(): void {
        $id   = $this->prospect();
        $note = '20-09-2026 (SV): toestemmingsverzoek per e-mail naar de jeugdcoordinator.';

        [ $data, $status ] = $this->send( 'PATCH', 'prospects/' . $id, [ 'scouting_notes' => $note ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['data']['changed'] );

        [ $got ] = $this->send( 'GET', 'prospects/' . $id );
        $this->assertSame( $note, (string) $got['data']['prospect']['scouting_notes'] );

        [ , $status ] = $this->send( 'PATCH', 'prospects/' . $id, [ 'scouting_notes' => '' ] );
        $this->assertSame( 200, $status );
        $this->assertNull( ( (array) ( new ProspectsRepository() )->find( $id ) )['scouting_notes'] );
    }

    public function test_the_note_change_is_audited(): void {
        global $wpdb;
        $id = $this->prospect();

        [ , $status ] = $this->send( 'PATCH', 'prospects/' . $id, [ 'scouting_notes' => 'Tweede keer gezien: zelfde beeld.' ] );
        $this->assertSame( 200, $status );

        $payload = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log
              WHERE action = 'prospect.updated' AND entity_type = 'prospect' AND entity_id = %d
              ORDER BY id DESC LIMIT 1",
            $id
        ) );
        $decoded = json_decode( $payload, true );

        $this->assertIsArray( $decoded );
        $this->assertContains( 'scouting_notes', (array) ( $decoded['fields'] ?? [] ) );
    }

    private function prospect(): int {
        return ( new ProspectsRepository() )->create( [
            'first_name'            => 'Nieuw',
            'last_name'             => 'Talent',
            'discovered_by_user_id' => $this->scout,
            'scouting_notes'        => 'Eerste indruk.',
        ] );
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body = [] ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $body ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
