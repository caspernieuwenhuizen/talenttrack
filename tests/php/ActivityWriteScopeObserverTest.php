<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Admin\ActivitiesPage;
use TT\Modules\Activities\Frontend\FrontendOpponentBackfillView;
use TT\Modules\Authorization\ActivityTeamScope;
use TT\Modules\Authorization\AllTeamsScope;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\MatchAnalysis\Rest\MatchAnalysisRestController;
use TT\Modules\MatchExecution\Rest\MatchExecutionRestController;
use TT\Modules\MatchPrep\Rest\MatchPrepRestController;
use TT\Shared\Admin\BulkActionsHelper;
use TT\Shared\Frontend\FrontendActivitiesManageView;

/**
 * A coach who also holds the club-wide read-only observer seat reads every
 * team's matches and writes only their own. Every activity write outside the
 * activities controller asks the change grant, not the read scope: match
 * execution, match prep, match analysis, the opponent backfill and the
 * wp-admin activity screens.
 */
final class ActivityWriteScopeObserverTest extends WP_UnitTestCase {

    private int $own_match   = 0;
    private int $other_match = 0;
    private int $coach       = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Obswrite Own U16', 'age_group' => 'U16' ] );
        $own_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Obswrite Other U16', 'age_group' => 'U16' ] );
        $other_team = (int) $wpdb->insert_id;

        $this->own_match   = $this->match( $own_team );
        $this->other_match = $this->match( $other_team );

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $club, 'first_name' => 'Obswrite', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $club, 'team_id' => $own_team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $own_team,
        ] );
        ( new \WP_User( $this->coach ) )->add_role( 'tt_readonly_observer' );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( $this->coach );

        $this->assertTrue( AllTeamsScope::canSeeAllTeamsActivities( $this->coach ), 'precondition: the seat reads every team' );
        $this->assertTrue( ActivityTeamScope::coversActivityForWrite( $this->coach, $this->own_match ), 'precondition: their own team stays writable' );
    }

    public function tear_down(): void {
        $_GET = [];
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_match_execution_writes_are_refused_and_reads_kept(): void {
        $this->assertFalse( MatchExecutionRestController::can_edit( $this->request( 'POST', $this->other_match ) ) );
        $this->assertTrue( MatchExecutionRestController::can_edit( $this->request( 'POST', $this->own_match ) ), 'their own match still runs' );
        $this->assertTrue( MatchExecutionRestController::can_edit( $this->request( 'GET', $this->other_match ) ), 'the read seat still reads' );
    }

    public function test_match_prep_writes_are_refused_and_reads_kept(): void {
        $this->assertFalse( MatchPrepRestController::can_edit( $this->request( 'PUT', $this->other_match ) ) );
        $this->assertTrue( MatchPrepRestController::can_edit( $this->request( 'PUT', $this->own_match ) ), 'their own prep still saves' );
        $this->assertTrue( MatchPrepRestController::can_edit( $this->request( 'GET', $this->other_match ) ), 'the read seat still reads' );
    }

    public function test_match_analysis_writes_are_refused_and_reads_kept(): void {
        $this->assertSame( 403, MatchAnalysisRestController::put_section( $this->request( 'PUT', $this->other_match ) )->get_status() );
        $this->assertNull( $this->analysisRefusal( 'PUT', $this->own_match ), 'their own analysis still saves' );
        $this->assertNull( $this->analysisRefusal( 'GET', $this->other_match ), 'the read seat still reads' );
    }

    public function test_the_opponent_backfill_lists_only_matches_the_coach_may_write(): void {
        $ids = array_column( FrontendOpponentBackfillView::pending( $this->coach, false ), 'id' );
        $this->assertContains( $this->own_match, $ids, 'precondition: their own match is listed' );
        $this->assertNotContains( $this->other_match, $ids );
    }

    public function test_the_wp_admin_edit_form_and_bulk_actions_refuse_another_teams_match(): void {
        $_GET = [ 'action' => 'edit', 'id' => (string) $this->other_match ];
        ob_start();
        ActivitiesPage::render_page();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString( ActivityTeamScope::refusalMessage(), html_entity_decode( $html, ENT_QUOTES ) );

        $this->assertSame( [ $this->own_match ], BulkActionsHelper::reachableIds( 'activity', [ $this->own_match, $this->other_match ] ) );
    }

    public function test_the_activity_edit_form_opens_only_for_a_team_the_coach_may_write(): void {
        $this->assertStringNotContainsString( 'That activity no longer exists.', $this->activitiesView( 'edit', $this->own_match ), 'precondition: their own form opens' );
        $this->assertStringContainsString( 'That activity no longer exists.', $this->activitiesView( 'edit', $this->other_match ) );
    }

    public function test_the_activity_detail_still_opens_without_its_write_actions(): void {
        $own   = $this->activitiesView( '', $this->own_match );
        $other = $this->activitiesView( '', $this->other_match );

        $this->assertStringContainsString( 'tt_view=match-prep', $own, 'precondition: their own match offers prep' );
        $this->assertStringNotContainsString( 'That activity no longer exists.', $other, 'the read seat still reads the detail' );
        $this->assertStringContainsString( 'Obswrite match', $other );
        $this->assertStringNotContainsString( 'tt_view=match-prep', $other );

        $this->assertStringContainsString( 'tt-act-result__form', $own, 'precondition: their own result is editable' );
        $this->assertStringNotContainsString( 'tt-act-result__form', $other, 'the result is read, not offered for editing' );
        $this->assertStringNotContainsString( 'match-analysis', $other );
        $this->assertStringNotContainsString( 'data-tt-archive-rest-path', $other );
    }

    public function test_a_club_wide_change_grant_still_writes_every_team(): void {
        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        AuthorizationService::flushCache();
        $this->assertTrue( ActivityTeamScope::coversActivityForWrite( $hod, $this->other_match, false ) );
    }

    private function activitiesView( string $action, int $activity_id ): string {
        $_GET = [ 'tt_view' => 'activities', 'id' => (string) $activity_id ];
        if ( $action !== '' ) $_GET['action'] = $action;
        ob_start();
        FrontendActivitiesManageView::render( $this->coach, false );
        return (string) ob_get_clean();
    }

    private function request( string $method, int $activity_id ): \WP_REST_Request {
        $r = new \WP_REST_Request( $method, '/talenttrack/v1/activities/' . $activity_id );
        $r->set_param( 'activity_id', $activity_id );
        return $r;
    }

    private function analysisRefusal( string $method, int $activity_id ): ?\WP_REST_Response {
        $refuse = new \ReflectionMethod( MatchAnalysisRestController::class, 'refuseUnlessCoversActivity' );
        $refuse->setAccessible( true );
        $out = $refuse->invoke( null, $this->request( $method, $activity_id ) );
        return $out instanceof \WP_REST_Response ? $out : null;
    }

    private function match( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $team_id, 'title' => 'Obswrite match',
            'session_date' => gmdate( 'Y-m-d', (int) strtotime( '-3 days' ) ),
            'activity_type_key' => 'match', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the match row was written' );
        return $id;
    }
}
