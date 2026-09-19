<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\ParentAccountService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3572 — one parent model: `tt_player_parents`.
 *
 * The players list read the parent from `tt_players.parent_person_id`,
 * which only the wp-admin picker wrote, while every access check read the
 * pivot — so a parent linked the supported way showed as "no parent" and an
 * admin concluded the link had failed. And the two write paths into the
 * pivot accepted opposite sets of accounts: one REQUIRED a people row of
 * type `parent`, the other REFUSED any people row.
 */
final class ParentModelTest extends WP_UnitTestCase {

    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->player = $this->makePlayer( 'Bas', 'Willems' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the players list reads the pivot ──────────────────────────────

    public function test_a_parent_linked_the_supported_way_shows_in_the_list(): void {
        $anna = self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Anna Willems' ] );

        $link = new WP_REST_Request( 'POST', '/talenttrack/v1/players/' . $this->player . '/parents' );
        $link->set_header( 'Content-Type', 'application/json' );
        $link->set_body( (string) wp_json_encode( [ 'wp_user_id' => $anna ] ) );
        $this->assertSame( 200, rest_do_request( $link )->get_status() );

        $row = $this->listRow();
        $this->assertSame( $anna, $row['parent_id'] );
        $this->assertSame( 'Anna Willems', $row['parent_name'] );
        $this->assertSame( 1, $row['parent_count'] );

        $unlink = new WP_REST_Request( 'DELETE', '/talenttrack/v1/players/' . $this->player . '/parents/' . $anna );
        $this->assertSame( 200, rest_do_request( $unlink )->get_status() );

        $row = $this->listRow();
        $this->assertSame( 0, $row['parent_id'] );
        $this->assertSame( '', $row['parent_name'] );
        $this->assertSame( '', $row['parent_link_html'] );
    }

    public function test_the_list_shows_the_primary_parent_plus_a_count(): void {
        $service = new ParentAccountService();
        $anna    = self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Anna Willems' ] );
        $mark    = self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Mark Willems' ] );
        $service->linkToPlayer( $this->player, $anna );
        $service->linkToPlayer( $this->player, $mark );

        $row = $this->listRow();
        $this->assertSame( 'Anna Willems', $row['parent_name'], 'the first link is the primary' );
        $this->assertSame( 2, $row['parent_count'] );
        $this->assertStringContainsString( 'Anna Willems +1', (string) $row['parent_link_html'] );
    }

    // ── one link rule ─────────────────────────────────────────────────

    /**
     * @dataProvider accounts
     */
    public function test_both_write_paths_accept_and_refuse_the_same_accounts( string $shape, bool $expected ): void {
        $via_service = $this->accountOfShape( $shape );
        $result      = ( new ParentAccountService() )->linkToPlayer( $this->player, $via_service );
        $this->assertSame( $expected, (bool) $result['ok'], "ParentAccountService::linkToPlayer on a {$shape} account" );

        $other_player = $this->makePlayer( 'Kai', 'Janssen' );
        $via_put      = $this->accountOfShape( $shape );
        $put          = new WP_REST_Request( 'PUT', '/talenttrack/v1/players/' . $other_player );
        $put->set_header( 'Content-Type', 'application/json' );
        $put->set_body( (string) wp_json_encode( [ 'link_parent_user_id' => $via_put ] ) );
        rest_do_request( $put );

        $linked = in_array(
            $via_put,
            ( new \TT\Modules\Invitations\PlayerParentsRepository() )->parentsForPlayer( $other_player ),
            true
        );
        $this->assertSame( $expected, $linked, "PUT players/{id} link_parent_user_id on a {$shape} account" );
    }

    /** @return array<string, array{0:string,1:bool}> */
    public function accounts(): array {
        return [
            'plain account'         => [ 'plain', true ],
            'parent people record'  => [ 'parent_person', true ],
            'staff people record'   => [ 'staff_person', false ],
            'player account'        => [ 'player', false ],
        ];
    }

    // ── the migration ─────────────────────────────────────────────────

    public function test_the_migration_carries_old_links_across_and_reports_the_rest(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $with_account = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Linda', 'last_name' => 'Willems',
            'role_type' => 'parent', 'wp_user_id' => $with_account, 'status' => 'active',
        ] );
        $person_with = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Opa', 'last_name' => 'Janssen',
            'role_type' => 'parent', 'wp_user_id' => null, 'status' => 'active',
        ] );
        $person_without = (int) $wpdb->insert_id;

        $second = $this->makePlayer( 'Sem', 'Janssen' );
        $wpdb->update( "{$p}tt_players", [ 'parent_person_id' => $person_with ], [ 'id' => $this->player ] );
        $wpdb->update( "{$p}tt_players", [ 'parent_person_id' => $person_without ], [ 'id' => $second ] );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0270_retire_parent_person_id.php';
        $migration->up();
        $migration->up();

        $repo = new \TT\Modules\Invitations\PlayerParentsRepository();
        $this->assertSame( [ $with_account ], $repo->parentsForPlayer( $this->player ), 'converted once, and only once' );
        $this->assertSame( [], $repo->parentsForPlayer( $second ), 'no account, nothing to link' );
        $this->assertSame(
            $person_without,
            (int) $wpdb->get_var( $wpdb->prepare( "SELECT parent_person_id FROM {$p}tt_players WHERE id = %d", $second ) ),
            'the unconverted link is left in place, not deleted'
        );
    }

    // ── fixtures ──────────────────────────────────────────────────────

    private function makePlayer( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => $first,
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function accountOfShape( string $shape ): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        if ( $shape === 'parent_person' || $shape === 'staff_person' ) {
            $wpdb->insert( "{$wpdb->prefix}tt_people", [
                'club_id'    => 1,
                'first_name' => 'Linked',
                'last_name'  => 'Person',
                'role_type'  => $shape === 'parent_person' ? 'parent' : 'staff',
                'wp_user_id' => $uid,
                'status'     => 'active',
            ] );
        }
        if ( $shape === 'player' ) {
            $wpdb->insert( "{$wpdb->prefix}tt_players", [
                'club_id' => 1, 'first_name' => 'Own', 'last_name' => 'Player',
                'status' => 'active', 'wp_user_id' => $uid,
            ] );
        }
        return $uid;
    }

    /** @return array<string,mixed> */
    private function listRow(): array {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/players' );
        $req->set_query_params( [ 'search' => 'Bas', 'per_page' => 100 ] );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );
        foreach ( (array) ( $res->get_data()['data']['rows'] ?? [] ) as $row ) {
            if ( (int) $row['id'] === $this->player ) return $row;
        }
        $this->fail( 'the player is not in the list' );
    }
}
