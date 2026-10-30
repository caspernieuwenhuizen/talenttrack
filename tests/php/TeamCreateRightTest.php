<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Wizards\Team\NewTeamWizard;

/**
 * #4286 — creating a team follows the matrix's create right.
 *
 * Creation was wired to `tt_edit_teams`, the change right every head coach
 * holds for their own team, so a head coach could add teams the matrix
 * never granted and saw the team-cap nudge as a data error. `team:
 * create_delete` is seeded to the head of development and the academy
 * admin only; every create path now asks that, and editing stays where it
 * was.
 */
final class TeamCreateRightTest extends WP_UnitTestCase {

    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => 'Create-right U13', 'age_group' => 'U13' ] );
        $this->team = (int) $wpdb->insert_id;

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_head_of_development_and_the_academy_admin_may_create_a_team(): void {
        foreach ( [ 'tt_head_dev', 'tt_club_admin' ] as $role ) {
            $uid = self::factory()->user->create( [ 'role' => $role ] );
            $this->assertTrue( AuthorizationService::canCreateTeam( $uid ), $role . ' holds team: create_delete' );
        }
    }

    public function test_a_head_coach_may_not_create_a_team_and_still_edits_their_own(): void {
        $coach = $this->makeHeadCoach( $this->team );

        $this->assertTrue(
            AuthorizationService::userCanOrMatrix( $coach, 'tt_edit_teams' ),
            'the head coach holds the edit right, or the refusal below proves nothing'
        );
        $this->assertFalse( AuthorizationService::canCreateTeam( $coach ) );
        $this->assertTrue( AuthorizationService::canManageTeam( $coach, $this->team ) );
    }

    public function test_post_teams_refuses_a_head_coach_and_put_on_their_own_team_does_not(): void {
        $coach = $this->makeHeadCoach( $this->team );
        wp_set_current_user( $coach );

        $post = new WP_REST_Request( 'POST', '/talenttrack/v1/teams' );
        $post->set_body_params( [ 'name' => 'Coach-made team' ] );
        $this->assertSame( 403, rest_do_request( $post )->get_status() );

        $put = new WP_REST_Request( 'PUT', '/talenttrack/v1/teams/' . $this->team );
        $put->set_body_params( [ 'notes' => 'Edited by the head coach' ] );
        $this->assertSame( 200, rest_do_request( $put )->get_status() );
    }

    public function test_post_teams_admits_the_head_of_development(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_head_dev' ] ) );

        $post = new WP_REST_Request( 'POST', '/talenttrack/v1/teams' );
        $post->set_body_params( [ 'name' => 'HoD-made team' ] );
        $status = rest_do_request( $post )->get_status();
        $this->assertContains( $status, [ 200, 201 ], 'the create right is admitted' );
    }

    public function test_the_new_team_wizard_asks_the_create_right(): void {
        $this->assertSame( 'tt_manage_teams', ( new NewTeamWizard() )->requiredCap() );
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Hoofd',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        $this->assertContains( 'head_coach', PersonaResolver::personasFor( $uid ) );
        AuthorizationService::flushCache();
        return $uid;
    }
}
