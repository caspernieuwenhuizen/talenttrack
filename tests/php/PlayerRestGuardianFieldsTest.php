<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * `GET players/{id}` admits more readers than staff: a linked parent, the
 * player, a linked scout. Guardian contact and the club's own player fields
 * are family data of a minor, so they come back only for staff of the
 * player, and every time they do an audit row records it.
 */
final class PlayerRestGuardianFieldsTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $player = 0;
    private int $playerUser = 0;
    private int $parent = 0;
    private int $coach = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Guardian JO12-1' ] );
        $team = (int) $wpdb->insert_id;

        $this->playerUser = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id'        => $this->club,
            'team_id'        => $team,
            'first_name'     => 'Sem',
            'last_name'      => 'Guardian',
            'status'         => 'active',
            'wp_user_id'     => $this->playerUser,
            'guardian_name'  => 'Other Guardian',
            'guardian_email' => 'other.guardian@example.test',
            'guardian_phone' => '0612345678',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$p}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $this->player,
            'parent_user_id' => $this->parent,
        ] );

        $this->coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Guardian',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $this->coach,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team,
        ] );
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_linked_scout_gets_the_record_without_family_fields(): void {
        $scout = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        update_user_meta( $scout, 'tt_scout_player_ids', wp_json_encode( [ $this->player ] ) );
        AuthorizationService::flushCache();

        $this->assertRecordWithoutFamilyFields( $scout );
    }

    public function test_parent_gets_the_record_without_family_fields(): void {
        $this->assertRecordWithoutFamilyFields( $this->parent );
    }

    public function test_player_gets_their_own_record_without_family_fields(): void {
        $this->assertRecordWithoutFamilyFields( $this->playerUser );
    }

    public function test_coach_gets_family_fields_and_the_read_is_audited(): void {
        $before = $this->auditRows();

        $data = $this->getPlayer( $this->coach );
        $this->assertSame( 'other.guardian@example.test', $data['guardian_email'] ?? null );
        $this->assertSame( 'Other Guardian', $data['guardian_name'] ?? null );
        $this->assertSame( '0612345678', $data['guardian_phone'] ?? null );
        $this->assertArrayHasKey( 'custom_fields', $data );

        $this->assertSame( $before + 1, $this->auditRows() );
    }

    private function assertRecordWithoutFamilyFields( int $user ): void {
        $before = $this->auditRows();

        $data = $this->getPlayer( $user );
        $this->assertSame( $this->player, (int) ( $data['id'] ?? 0 ) );
        foreach ( [ 'guardian_name', 'guardian_email', 'guardian_phone', 'custom_fields' ] as $field ) {
            $this->assertArrayNotHasKey( $field, $data, "{$field} must not reach user {$user}" );
        }

        $this->assertSame( $before, $this->auditRows() );
    }

    /** @return array<string, mixed> */
    private function getPlayer( int $user ): array {
        wp_set_current_user( $user );
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player ) );
        $this->assertSame( 200, $response->get_status(), "user {$user} passes the route gate" );
        $body = (array) $response->get_data();
        return (array) ( $body['data'] ?? [] );
    }

    private function auditRows(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log
              WHERE action = %s AND entity_type = 'player' AND entity_id = %d",
            'player.guardian_contact.read',
            $this->player
        ) );
    }
}
