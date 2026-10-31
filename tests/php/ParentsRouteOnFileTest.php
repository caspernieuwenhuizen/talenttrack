<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Invitations\PlayerParentsRepository;

/**
 * #4410 — `GET players/{id}/parents` returns the guardian recorded on the
 * player as `on_file`, the one the profile card shows as "Guardian on file",
 * so the API and the page give the same answer.
 *
 * Two conditions from the architect's review of #4409 are pinned here: the
 * on-file guardian is returned only to a caller who is staff for the player,
 * on top of the route's own `tt_manage_parent_accounts` gate, and a read of
 * it is audited as a read of one contact.
 */
final class ParentsRouteOnFileTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;

    /** @var callable|null */
    private $cap_filter = null;

    public function set_up(): void {
        parent::set_up();
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO12-1' ] );
        $this->team = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        if ( null !== $this->cap_filter ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_an_on_file_guardian_without_an_account_is_returned_and_counted(): void {
        $player = $this->player( true );
        $admin  = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

        [ $data, $status ] = $this->getParents( $admin, $player );

        $this->assertSame( 200, $status );
        $this->assertSame( [], $data['data']['parents'] );
        $this->assertSame(
            [ 'name' => 'Petra Jansen', 'email' => 'petra@demo.talenttrack.test', 'phone' => '06 1234' ],
            $data['data']['on_file']
        );
        $this->assertSame( 1, $this->lastCount( $player ), 'an on-file-only read is a read of one contact' );
    }

    public function test_a_player_with_neither_returns_null_and_count_zero(): void {
        $player = $this->player( false );
        $admin  = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

        [ $data, $status ] = $this->getParents( $admin, $player );

        $this->assertSame( 200, $status );
        $this->assertSame( [], $data['data']['parents'] );
        $this->assertArrayHasKey( 'on_file', $data['data'] );
        $this->assertNull( $data['data']['on_file'] );
        $this->assertSame( 0, $this->lastCount( $player ) );
    }

    public function test_a_linked_account_and_an_on_file_guardian_are_both_returned(): void {
        $player = $this->player( true );
        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent', 'user_email' => 'linked@example.org' ] );
        ( new PlayerParentsRepository() )->link( $player, $parent, true );
        $admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

        [ $data, $status ] = $this->getParents( $admin, $player );

        $this->assertSame( 200, $status );
        $this->assertCount( 1, $data['data']['parents'] );
        $this->assertSame( $parent, $data['data']['parents'][0]['wp_user_id'] );
        $this->assertSame( 'Petra Jansen', $data['data']['on_file']['name'] );
        $this->assertSame( 2, $this->lastCount( $player ) );
    }

    public function test_a_parent_account_manager_who_is_not_staff_for_the_player_gets_no_on_file(): void {
        $player  = $this->player( true );
        $manager = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->cap_filter = static function ( $allcaps, $caps, $args, $user ) use ( $manager ) {
            if ( (int) $user->ID === $manager ) {
                $allcaps['tt_manage_parent_accounts'] = true;
            }
            return $allcaps;
        };
        add_filter( 'user_has_cap', $this->cap_filter, 999, 4 );

        $this->assertFalse(
            AuthorizationService::isStaffForPlayer( $manager, $player ),
            'precondition: the caller is not staff for the player'
        );

        [ $data, $status ] = $this->getParents( $manager, $player );

        $this->assertSame( 200, $status, 'precondition: the caller passes the route gate' );
        $this->assertNull( $data['data']['on_file'], 'the on-file guardian reached a caller who is not staff for the player' );
        $this->assertStringNotContainsString( 'Petra', (string) wp_json_encode( $data ) );
        $this->assertSame( 0, $this->lastCount( $player ), 'nothing was read, so nothing is counted' );
    }

    // ---- fixtures -------------------------------------------------------

    private function player( bool $with_guardian ): int {
        global $wpdb;
        $row = [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Sem',
            'last_name'  => 'Jansen',
            'status'     => 'active',
        ];
        if ( $with_guardian ) {
            $row['guardian_name']  = 'Petra Jansen';
            $row['guardian_email'] = 'petra@demo.talenttrack.test';
            $row['guardian_phone'] = '06 1234';
        }
        $wpdb->insert( "{$wpdb->prefix}tt_players", $row );
        return (int) $wpdb->insert_id;
    }

    /** @return array{0:array<string,mixed>,1:int} */
    private function getParents( int $user_id, int $player_id ): array {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $player_id . '/parents' ) );
        $wp_rest_server = null;
        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }

    private function lastCount( int $player_id ): int {
        global $wpdb;
        $payload = $wpdb->get_var( $wpdb->prepare(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log
              WHERE action = 'player.guardian_contacts_viewed' AND entity_type = 'player' AND entity_id = %d
              ORDER BY id DESC LIMIT 1",
            $player_id
        ) );
        $this->assertNotNull( $payload, 'the read wrote an audit row' );
        $decoded = (array) json_decode( (string) $payload, true );
        $this->assertSame( 'rest_players_parents', $decoded['surface'] ?? null );
        return (int) ( $decoded['count'] ?? -1 );
    }
}
