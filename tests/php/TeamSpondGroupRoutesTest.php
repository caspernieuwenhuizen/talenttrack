<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Spond\TeamSpondGroups;

/**
 * #2399 — the per-team Spond group routes.
 *
 * Authorization coverage per the #1388 Tier 2 mandate. These routes let a
 * head coach finish their own team's Spond setup, so the gate must be
 * `TeamSpondAccess::canManage()` for THAT team — the same authority the
 * credential routes use — and never the any-team `tt_edit_teams` the
 * team-edit form relies on (the hole #2388 closed).
 */
final class TeamSpondGroupRoutesTest extends WP_UnitTestCase {

    private int $team_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'name' => 'Spond FC', 'club_id' => 1 ] );
        $this->team_id = (int) $wpdb->insert_id;

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function dispatch( string $method ): int {
        $req = new WP_REST_Request( $method, '/talenttrack/v1/teams/' . $this->team_id . '/spond/group' );
        if ( $method === 'POST' ) $req->set_param( 'group_id', 'grp-123' );
        return rest_get_server()->dispatch( $req )->get_status();
    }

    public function test_routes_are_registered(): void {
        $found = false;
        foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
            if ( strpos( $route, '/spond/group' ) !== false ) { $found = true; break; }
        }
        $this->assertTrue( $found );
    }

    public function test_denies_an_unauthenticated_caller(): void {
        wp_set_current_user( 0 );
        $this->assertContains( $this->dispatch( 'GET' ), [ 401, 403 ] );
        $this->assertContains( $this->dispatch( 'POST' ), [ 401, 403 ] );
    }

    public function test_denies_a_logged_in_user_without_authority(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $this->assertContains( $this->dispatch( 'GET' ), [ 401, 403 ] );
        $this->assertContains( $this->dispatch( 'POST' ), [ 401, 403 ] );
    }

    /**
     * tt_edit_teams is the team-edit form's cap. It must NOT be what opens
     * these routes — that is exactly the any-team authority #2388 removed
     * from the per-team Spond surface.
     */
    public function test_edit_teams_alone_does_not_open_the_routes(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $grant = static function ( $caps ) { $caps['tt_edit_teams'] = true; return $caps; };
        add_filter( 'user_has_cap', $grant, 99 );
        $get  = $this->dispatch( 'GET' );
        $post = $this->dispatch( 'POST' );
        remove_filter( 'user_has_cap', $grant, 99 );

        $this->assertContains( $get, [ 401, 403 ] );
        $this->assertContains( $post, [ 401, 403 ] );
    }

    /** An administrator manages every team's Spond connection. */
    public function test_administrator_passes_the_gate(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        // GET reaches the handler; without live Spond credentials it fails
        // upstream (502), which is still past the permission gate.
        $this->assertNotContains( $this->dispatch( 'GET' ), [ 401, 403 ] );
        $this->assertSame( 200, $this->dispatch( 'POST' ) );
    }

    // ---- domain ---------------------------------------------------------

    public function test_setting_and_reading_back_the_group(): void {
        global $wpdb;
        $this->assertTrue( TeamSpondGroups::setGroup( $this->team_id, 'grp-abc' ) );

        $stored = $wpdb->get_var( $wpdb->prepare(
            "SELECT spond_group_id FROM {$wpdb->prefix}tt_teams WHERE id = %d",
            $this->team_id
        ) );
        $this->assertSame( 'grp-abc', (string) $stored );
    }

    public function test_other_teams_using_reports_a_shared_group_but_not_your_own(): void {
        global $wpdb;
        TeamSpondGroups::setGroup( $this->team_id, 'grp-shared' );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'name'           => 'Combined U17',
            'club_id'        => 1,
            'spond_group_id' => 'grp-shared',
        ] );

        $map = TeamSpondGroups::otherTeamsUsing( [ 'grp-shared' ], $this->team_id );
        $this->assertSame( 'Combined U17', $map['grp-shared'] ?? '' );

        // Asked from the OTHER team's perspective, this team is the sharer.
        $other = (int) $wpdb->insert_id;
        $map2  = TeamSpondGroups::otherTeamsUsing( [ 'grp-shared' ], $other );
        $this->assertSame( 'Spond FC', $map2['grp-shared'] ?? '' );

        // A group nobody else uses produces no warning.
        $this->assertSame( [], TeamSpondGroups::otherTeamsUsing( [ 'grp-unique' ], $this->team_id ) );
    }
}
