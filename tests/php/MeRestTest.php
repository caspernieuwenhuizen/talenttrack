<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3568 — `GET /me` tells a logged-in account which player records are its
 * own.
 *
 * Every per-player route needs an id, and nothing gave a player or a parent
 * theirs: the collection routes are staff surfaces and `GET me` was a 404.
 * The assertions that matter most are the negative ones — the route must
 * never hand one account another account's player.
 */
final class MeRestTest extends WP_UnitTestCase {

    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => 'Hedel O11-1' ] );
        $this->team = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_linked_player_learns_their_own_id_and_only_that_one_opens(): void {
        $account = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $mine    = $this->player( 'Bas', $account );
        $theirs  = $this->player( 'Kai', 0 );

        wp_set_current_user( $account );
        $me = $this->me();

        $this->assertSame( $mine, $me['player']['id'] ?? null );
        $this->assertSame( 'Bas Willems', $me['player']['name'] ?? null );
        $this->assertSame( $this->team, $me['player']['team_id'] ?? null );
        $this->assertNull( $me['reason'] );

        $this->assertSame( 200, $this->status( '/talenttrack/v1/players/' . $mine ) );
        $this->assertSame( 403, $this->status( '/talenttrack/v1/players/' . $theirs ) );
    }

    public function test_two_linked_players_each_see_only_their_own(): void {
        $a = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $b = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $pa = $this->player( 'Anna', $a );
        $pb = $this->player( 'Bram', $b );

        wp_set_current_user( $a );
        $this->assertSame( $pa, $this->me()['player']['id'] ?? null );

        wp_set_current_user( $b );
        $this->assertSame( $pb, $this->me()['player']['id'] ?? null );
    }

    public function test_a_parent_gets_exactly_their_active_children(): void {
        $parent   = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $kid      = $this->player( 'Sem', 0 );
        $archived = $this->player( 'Noah', 0, [ 'archived_at' => '2026-01-01 00:00:00' ] );
        $released = $this->player( 'Luuk', 0, [ 'status' => 'released' ] );
        $stranger = $this->player( 'Finn', 0 );
        foreach ( [ $kid, $archived, $released ] as $child ) $this->linkParent( $parent, $child );

        wp_set_current_user( $parent );
        $me = $this->me();

        $this->assertNull( $me['player'] );
        $this->assertSame( [ $kid ], array_column( $me['children'], 'id' ) );
        $this->assertNotContains( $stranger, array_column( $me['children'], 'id' ) );
        $this->assertNull( $me['reason'] );
    }

    public function test_an_unlinked_account_is_told_so_not_refused(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_player' ] ) );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/me' );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );

        $me = $res->get_data()['data'];
        $this->assertNull( $me['player'] );
        $this->assertSame( [], $me['children'] );
        $this->assertSame( 'no_linked_player', $me['reason'] );
    }

    public function test_an_archived_or_other_club_record_is_not_returned(): void {
        $archived_owner = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $this->player( 'Old', $archived_owner, [ 'archived_at' => '2026-01-01 00:00:00' ] );
        wp_set_current_user( $archived_owner );
        $this->assertNull( $this->me()['player'] );

        $elsewhere = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $this->player( 'Away', $elsewhere, [ 'club_id' => 999 ] );
        wp_set_current_user( $elsewhere );
        $this->assertNull( $this->me()['player'] );
    }

    public function test_a_logged_out_request_is_refused(): void {
        wp_set_current_user( 0 );
        $this->assertContains( $this->status( '/talenttrack/v1/me' ), [ 401, 403 ] );
    }

    /** @param array<string,mixed> $extra */
    private function player( string $first, int $account, array $extra = [] ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", $extra + [
            'club_id'    => (int) CurrentClub::id(),
            'team_id'    => $this->team,
            'first_name' => $first,
            'last_name'  => 'Willems',
            'status'     => 'active',
            'wp_user_id' => $account > 0 ? $account : null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function linkParent( int $parent, int $player ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => (int) CurrentClub::id(),
            'player_id'      => $player,
            'parent_user_id' => $parent,
        ] );
    }

    /** @return array<string,mixed> */
    private function me(): array {
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/me' ) );
        $this->assertSame( 200, $res->get_status() );
        return (array) $res->get_data()['data'];
    }

    private function status( string $route ): int {
        return rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
    }
}
