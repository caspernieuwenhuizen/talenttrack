<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Invitations\PlayerParentsRepository;

/**
 * `GET players/{id}/goals` asks for the goals section, not only for the
 * player: the same pair `players/{id}/evaluations` asks. Somebody who reads
 * the player for another reason (the team's physio or kit manager) does
 * not read that player's development goals.
 */
final class PlayerGoalsRouteSectionTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $player = 0;
    private int $player_user = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();

        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Goals U15', 'age_group' => 'U15' ] );
        $this->team        = (int) $wpdb->insert_id;
        $this->player_user = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => 1, 'first_name' => 'Goal', 'last_name' => 'Setterx',
            'team_id' => $this->team, 'status' => 'active', 'wp_user_id' => $this->player_user,
        ] );
        $this->player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_goals", [
            'club_id' => 1, 'player_id' => $this->player, 'title' => 'Weak foot passing', 'status' => 'in_progress', 'created_by' => 1,
        ] );
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_teams_staff_member_reads_the_player_but_not_their_goals(): void {
        $staff = $this->makeStaff( $this->team );
        $this->assertTrue( AuthorizationService::canViewPlayer( $staff, $this->player ), 'precondition: staff read the player' );
        $this->assertFalse( AuthorizationService::canReadPlayerSection( $staff, $this->player, 'goals' ), 'precondition: staff hold no goals read' );

        wp_set_current_user( $staff );
        $res = $this->goals();
        $this->assertNotSame( 200, $res->get_status() );
        $this->assertStringNotContainsString( 'Weak foot passing', (string) wp_json_encode( $res->get_data() ) );
    }

    public function test_the_teams_head_coach_reads_the_goals(): void {
        wp_set_current_user( $this->makeHeadCoach( $this->team ) );
        $res = $this->goals();
        $this->assertSame( 200, $res->get_status() );
        $this->assertStringContainsString( 'Weak foot passing', (string) wp_json_encode( $res->get_data() ) );
    }

    public function test_the_player_reads_their_own_goals(): void {
        wp_set_current_user( $this->player_user );
        $this->assertSame( 200, $this->goals()->get_status() );
    }

    public function test_a_linked_parent_reads_their_childs_goals(): void {
        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new PlayerParentsRepository() )->link( $this->player, $parent, true );
        AuthorizationService::flushCache();

        wp_set_current_user( $parent );
        $this->assertSame( 200, $this->goals()->get_status() );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function goals(): \WP_REST_Response {
        return rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/goals' ) );
    }

    private function makeStaff( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Kit', 'last_name' => 'Manager',
            'role_type' => 'staff', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $team_id, 'person_id' => $person_id, 'role_in_team' => 'staff',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person_id, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
        return $uid;
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Hoofd', 'last_name' => 'Trainer',
            'role_type' => 'head_coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'team_id' => $team_id, 'person_id' => $person_id, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person_id, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }
}
