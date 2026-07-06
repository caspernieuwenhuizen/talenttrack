<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Spond\CredentialsManager;
use TT\Modules\Spond\TeamSpondAccount;

/**
 * #2286 — per-team Spond account override.
 *
 * A team can carry its OWN Spond login that overrules the club account for
 * that team's syncs. This covers the parts that don't need a live Spond
 * call: route registration + permission gate, the CredentialsManager
 * resolution between club fallback and team override, and the
 * blank-password-keeps-stored behaviour on TeamSpondAccount::save.
 */
final class TeamSpondCredentialsTest extends WP_UnitTestCase {

    private const CREDS_ROUTE = '/talenttrack/v1/teams/(?P<id>\d+)/spond/credentials';
    private const TEST_ROUTE  = '/talenttrack/v1/teams/(?P<id>\d+)/spond/test';

    private int $team_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'id'      => 1,
            'club_id' => 1,
            'name'    => 'Override team',
        ] );
        $this->team_id = 1;
    }

    public function tear_down(): void {
        // Clean up any override so tests don't bleed into each other.
        ( new TeamSpondAccount( $this->team_id ) )->clear();

        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function test_team_credentials_routes_are_registered_with_permission_callback(): void {
        $routes = rest_get_server()->get_routes();

        foreach ( [ self::CREDS_ROUTE, self::TEST_ROUTE ] as $route ) {
            $this->assertArrayHasKey( $route, $routes, $route . ' is registered' );

            $has_perm = false;
            foreach ( $routes[ $route ] as $endpoint ) {
                if ( ! empty( $endpoint['permission_callback'] ) ) {
                    $has_perm = true;
                    // Must NOT be __return_true.
                    $this->assertNotSame(
                        '__return_true',
                        $endpoint['permission_callback'],
                        $route . ' does not use __return_true'
                    );
                }
            }
            $this->assertTrue( $has_perm, $route . ' declares a permission_callback' );
        }
    }

    public function test_for_team_falls_back_to_club_account_with_no_override(): void {
        $acct = CredentialsManager::forTeam( $this->team_id );
        $this->assertFalse( $acct->isTeamOverride(), 'no override → club account' );
    }

    public function test_for_team_returns_override_after_save(): void {
        ( new TeamSpondAccount( $this->team_id ) )->save( 'team@x.io', 'pw' );

        $acct = CredentialsManager::forTeam( $this->team_id );
        $this->assertTrue( $acct->isTeamOverride(), 'saved creds → team override' );
        $this->assertSame( 'team@x.io', $acct->getEmail() );
    }

    public function test_clear_reverts_to_club_fallback(): void {
        $team = new TeamSpondAccount( $this->team_id );
        $team->save( 'team@x.io', 'pw' );
        $this->assertTrue( CredentialsManager::forTeam( $this->team_id )->isTeamOverride() );

        $team->clear();
        $this->assertFalse(
            CredentialsManager::forTeam( $this->team_id )->isTeamOverride(),
            'cleared override → club fallback'
        );
    }

    public function test_blank_password_keeps_stored_password(): void {
        $team = new TeamSpondAccount( $this->team_id );
        $team->save( 'team@x.io', 'secret-pw' );
        $this->assertTrue( $team->hasCredentials() );

        // Re-save with the SAME email and a BLANK password — the stored
        // password must be preserved.
        $team->save( 'team@x.io', '' );
        $this->assertTrue(
            $team->hasCredentials(),
            'blank password on re-save keeps the stored password'
        );
    }

    public function test_subscriber_is_forbidden_from_saving_team_credentials(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/teams/' . $this->team_id . '/spond/credentials' );
        $req->set_body_params( [ 'email' => 'team@x.io', 'password' => 'pw' ] );
        $res = rest_do_request( $req );

        $this->assertSame(
            403,
            $res->get_status(),
            'a subscriber lacking tt_edit_spond_credentials is Forbidden'
        );
    }
}
