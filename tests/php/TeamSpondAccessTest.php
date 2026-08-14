<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Spond\TeamSpondAccess;

/**
 * #2388 — a head coach may manage the Spond connection of THEIR team, and
 * only theirs. Before #2388 the per-team credential endpoints gated on the
 * any-scope `tt_edit_spond_credentials` cap, so a head coach passed the
 * gate for ANY team. TeamSpondAccess checks `spond_integration → change`
 * for the exact team, closing that hole. WP admins keep unconditional
 * access.
 *
 * Uses the deterministic `scout` persona (WP role tt_scout → scout, no
 * administrator short-circuit) as the "head-coach-like" team-scoped user,
 * mirroring MatrixGateScopeTest's setup.
 */
final class TeamSpondAccessTest extends WP_UnitTestCase {

    private const PERSONA   = 'scout';
    private const TEAM_A    = 4201;
    private const TEAM_B    = 4202;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb; $wpdb->hide_errors();
        MatrixRepository::clearCache();

        global $wp_rest_server; $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'id' => self::TEAM_A, 'club_id' => 1, 'name' => 'Team A' ] );
        $wpdb->insert( "{$p}tt_teams", [ 'id' => self::TEAM_B, 'club_id' => 1, 'name' => 'Team B' ] );
    }

    public function tear_down(): void {
        ( new MatrixRepository() )->removeRow( self::PERSONA, 'spond_integration', 'change', MatrixGate::SCOPE_TEAM );
        MatrixRepository::clearCache();
        global $wp_rest_server; $wp_rest_server = null;
        parent::tear_down();
    }

    /** Create a scout user scoped to $team_id, with a spond change@team grant. */
    private function seedTeamScopedManager( int $team_id ): int {
        global $wpdb; $p = $wpdb->prefix;

        $uid = self::factory()->user->create( [ 'role' => 'tt_scout' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Team',
            'last_name'  => 'Coach',
            'role_type'  => 'coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        ( new MatrixRepository() )->setRow( self::PERSONA, 'spond_integration', 'change', MatrixGate::SCOPE_TEAM, '' );
        MatrixRepository::clearCache();

        return $uid;
    }

    public function test_admin_can_manage_any_team(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertTrue( TeamSpondAccess::canManage( $admin, self::TEAM_A ) );
        $this->assertTrue( TeamSpondAccess::canManage( $admin, self::TEAM_B ) );
    }

    public function test_team_scoped_user_can_manage_only_their_own_team(): void {
        $uid = $this->seedTeamScopedManager( self::TEAM_A );

        $this->assertTrue(
            TeamSpondAccess::canManage( $uid, self::TEAM_A ),
            'head coach can manage the Spond connection of their own team'
        );
        $this->assertFalse(
            TeamSpondAccess::canManage( $uid, self::TEAM_B ),
            'the grant must NOT leak to a team the user does not head — the pre-#2388 hole'
        );
    }

    public function test_scoped_user_rest_denied_for_another_team(): void {
        $uid = $this->seedTeamScopedManager( self::TEAM_A );
        wp_set_current_user( $uid );

        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/teams/' . self::TEAM_B . '/spond/credentials' );
        $req->set_body_params( [ 'email' => 'x@y.io', 'password' => 'pw' ] );
        $res = rest_do_request( $req );

        $this->assertSame(
            403,
            $res->get_status(),
            'the per-team credential endpoint rejects a head coach acting on another team'
        );
    }

    public function test_subscriber_cannot_manage(): void {
        $sub = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->assertFalse( TeamSpondAccess::canManage( $sub, self::TEAM_A ) );
    }
}
