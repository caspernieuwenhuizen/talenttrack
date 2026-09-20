<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\REST\ActivitiesRestController;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3616 — the activity WRITE routes narrow to the caller's teams.
 *
 * The read side of `ActivitiesRestController` has always narrowed:
 * `list_sessions()` uses `get_teams_for_coach()`, the grids use
 * `gridAllowedTeamIds()`. The single-record writes checked the capability
 * and stopped — and `tt_coach` holds `tt_edit_activities` club-wide, so a
 * head coach of one team could create, edit, archive, restore or
 * permanently delete another team's fixtures.
 *
 * Every assertion here is about a team the coach does NOT hold. The
 * positive cases are equally load-bearing: a guard that refuses the
 * caller's own team would be a worse bug than the one it fixes.
 */
final class ActivityWriteTeamScopeTest extends WP_UnitTestCase {

    private int $mineTeamId     = 0;
    private int $otherTeamId    = 0;
    private int $mineActivity   = 0;
    private int $otherActivity  = 0;
    private int $coachUserId    = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        // TT roles install on activation, which the wp-env bootstrap does
        // not fire; without them tt_coach holds no tt_edit_activities and
        // every assertion below would pass for the wrong reason.
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Write Mine' ] );
        $this->mineTeamId = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Write Theirs' ] );
        $this->otherTeamId = (int) $wpdb->insert_id;

        $this->mineActivity  = $this->makeActivity( $this->mineTeamId, 'My training' );
        $this->otherActivity = $this->makeActivity( $this->otherTeamId, 'Their training' );

        $this->coachUserId = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->grantTeam( $this->coachUserId, $this->mineTeamId );
        wp_set_current_user( $this->coachUserId );
    }

    private function makeActivity( int $team_id, string $title ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'team_id'           => $team_id,
            'title'             => $title,
            'session_date'      => '2026-03-01',
            'activity_type_key' => 'training',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A tt_people row plus an active team scope — what get_teams_for_coach reads. */
    private function grantTeam( int $user_id, int $team_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Write',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $user_id,
            'status'     => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
    }

    private function req( array $params ): \WP_REST_Request {
        $r = new \WP_REST_Request();
        foreach ( $params as $k => $v ) $r->set_param( $k, $v );
        return $r;
    }

    private function assertForbiddenTeam( $response, string $why ): void {
        $this->assertInstanceOf( \WP_REST_Response::class, $response, $why );
        $this->assertSame( 403, $response->get_status(), $why );
        $data = (array) $response->get_data();
        $this->assertSame( 'forbidden_team', (string) ( $data['code'] ?? ( $data['error']['code'] ?? '' ) ), $why );
    }

    private function activityRow( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            $id
        ) );
    }

    // -----------------------------------------------------------------
    // create
    // -----------------------------------------------------------------

    public function test_create_on_another_team_is_refused_and_writes_nothing(): void {
        global $wpdb;
        $before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities" );

        $res = ActivitiesRestController::create_session( $this->req( [
            'team_id'           => $this->otherTeamId,
            'title'             => 'Not mine to plan',
            'session_date'      => '2026-04-01',
            'activity_type_key' => 'training',
        ] ) );

        $this->assertForbiddenTeam( $res, 'creating on another team must be refused' );
        $this->assertSame(
            $before,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities" ),
            'a refused create must leave no row behind'
        );
    }

    public function test_create_on_own_team_still_works(): void {
        $res = ActivitiesRestController::create_session( $this->req( [
            'team_id'           => $this->mineTeamId,
            'title'             => 'Mine to plan',
            'session_date'      => '2026-04-01',
            'activity_type_key' => 'training',
        ] ) );

        $this->assertInstanceOf( \WP_REST_Response::class, $res );
        $this->assertNotSame( 403, $res->get_status(), 'a coach must still create on their own team' );
    }

    // -----------------------------------------------------------------
    // update
    // -----------------------------------------------------------------

    public function test_update_of_another_teams_activity_is_refused_and_changes_nothing(): void {
        $res = ActivitiesRestController::update_session( $this->req( [
            'id'    => $this->otherActivity,
            'title' => 'Renamed by a stranger',
        ] ) );

        $this->assertForbiddenTeam( $res, 'editing another team\'s activity must be refused' );
        $this->assertSame(
            'Their training',
            (string) ( $this->activityRow( $this->otherActivity )->title ?? '' ),
            'a refused update must leave the row untouched'
        );
    }

    public function test_moving_an_activity_to_a_team_you_do_not_hold_is_refused(): void {
        $res = ActivitiesRestController::update_session( $this->req( [
            'id'      => $this->mineActivity,
            'team_id' => $this->otherTeamId,
        ] ) );

        $this->assertForbiddenTeam( $res, 'pushing your activity onto another squad must be refused' );
        $this->assertSame(
            $this->mineTeamId,
            (int) ( $this->activityRow( $this->mineActivity )->team_id ?? 0 ),
            'the activity must not have moved'
        );
    }

    public function test_update_of_own_activity_still_works(): void {
        $res = ActivitiesRestController::update_session( $this->req( [
            'id'    => $this->mineActivity,
            'title' => 'Renamed by its coach',
        ] ) );

        $this->assertInstanceOf( \WP_REST_Response::class, $res );
        $this->assertNotSame( 403, $res->get_status(), 'a coach must still edit their own team\'s activity' );
    }

    // -----------------------------------------------------------------
    // archive / restore / permanent delete
    // -----------------------------------------------------------------

    public function test_archive_of_another_teams_activity_is_refused(): void {
        $res = ActivitiesRestController::delete_session( $this->req( [ 'id' => $this->otherActivity ] ) );
        $this->assertForbiddenTeam( $res, 'archiving another team\'s activity must be refused' );
        $this->assertNotNull( $this->activityRow( $this->otherActivity ), 'the row must still be there' );
    }

    public function test_restore_of_another_teams_activity_is_refused(): void {
        $res = ActivitiesRestController::restore_session( $this->req( [ 'id' => $this->otherActivity ] ) );
        $this->assertForbiddenTeam( $res, 'restoring another team\'s activity must be refused' );
    }

    public function test_permanent_delete_of_another_teams_activity_is_refused(): void {
        $res = ActivitiesRestController::delete_session_permanently( $this->req( [ 'id' => $this->otherActivity ] ) );
        $this->assertForbiddenTeam( $res, 'permanently deleting another team\'s activity must be refused' );
        $this->assertNotNull( $this->activityRow( $this->otherActivity ), 'the row must still be there' );
    }

    // -----------------------------------------------------------------
    // the global-scope caller is unaffected
    // -----------------------------------------------------------------

    public function test_a_global_scope_caller_writes_any_team(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );

        $res = ActivitiesRestController::update_session( $this->req( [
            'id'    => $this->otherActivity,
            'title' => 'Renamed by an admin',
        ] ) );

        $this->assertInstanceOf( \WP_REST_Response::class, $res );
        $this->assertNotSame(
            403,
            $res->get_status(),
            'a caller who sees all teams must be unaffected by the narrowing'
        );
    }
}
