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
use TT\Modules\Threads\Adapters\GoalThreadAdapter;

/**
 * A goal's conversation is read and written by the people who may read the
 * goal. The team's staff read the player and not their goals, so they do
 * not reach the goal's conversation either.
 */
final class GoalThreadSectionTest extends WP_UnitTestCase {

    private int $team        = 0;
    private int $player      = 0;
    private int $player_user = 0;
    private int $goal        = 0;

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
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Thread U15', 'age_group' => 'U15' ] );
        $this->team        = (int) $wpdb->insert_id;
        $this->player_user = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => 1, 'first_name' => 'Thread', 'last_name' => 'Playerx',
            'team_id' => $this->team, 'status' => 'active', 'wp_user_id' => $this->player_user,
        ] );
        $this->player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_goals", [
            'club_id' => 1, 'player_id' => $this->player, 'title' => 'Scan before receiving', 'status' => 'in_progress', 'created_by' => 1,
        ] );
        $this->goal = (int) $wpdb->insert_id;
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

    public function test_the_teams_staff_member_neither_reads_nor_writes_the_goal_conversation(): void {
        $staff = $this->makeTeamPerson( $this->team, 'tt_staff', 'staff', false );
        $this->assertTrue( AuthorizationService::canViewPlayer( $staff, $this->player ), 'precondition: staff read the player' );
        $this->assertFalse( AuthorizationService::canReadPlayerSection( $staff, $this->player, 'goals' ), 'precondition: staff hold no goals read' );

        $adapter = new GoalThreadAdapter();
        $this->assertFalse( $adapter->canRead( $staff, $this->goal ) );
        $this->assertFalse( $adapter->canPost( $staff, $this->goal ) );

        wp_set_current_user( $staff );
        $res = $this->thread();
        $this->assertNotSame( 200, $res->get_status() );
        $this->assertStringNotContainsString( 'Scan before receiving', (string) wp_json_encode( $res->get_data() ) );
    }

    public function test_the_head_coach_the_player_and_the_parent_still_read_it(): void {
        $coach  = $this->makeTeamPerson( $this->team, 'tt_coach', 'head_coach', true );
        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new PlayerParentsRepository() )->link( $this->player, $parent, true );
        AuthorizationService::flushCache();

        $adapter = new GoalThreadAdapter();
        $this->assertTrue( $adapter->canRead( $coach, $this->goal ), 'head coach' );
        $this->assertTrue( $adapter->canRead( $this->player_user, $this->goal ), 'player' );
        $this->assertTrue( $adapter->canRead( $parent, $this->goal ), 'parent' );
        $this->assertTrue( $adapter->canPost( $coach, $this->goal ), 'the head coach still writes in it' );

        wp_set_current_user( $coach );
        $this->assertSame( 200, $this->thread()->get_status() );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function thread(): \WP_REST_Response {
        return rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/threads/goal/' . $this->goal ) );
    }

    private function makeTeamPerson( int $team_id, string $role, string $role_in_team, bool $head ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Team', 'last_name' => ucfirst( $role_in_team ),
            'role_type' => $role_in_team, 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $team_id, 'person_id' => $person_id,
            'role_in_team' => $role_in_team, 'is_head_coach' => $head ? 1 : 0,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person_id, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
        return $uid;
    }
}
