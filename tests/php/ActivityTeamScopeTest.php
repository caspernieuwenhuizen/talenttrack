<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Authorization\ActivityTeamScope;
use TT\Modules\MatchAnalysis\Frontend\FrontendMatchAnalysisView;
use TT\Modules\MatchExecution\Frontend\FrontendMatchExecutionView;
use TT\Modules\MatchExecution\Rest\MatchExecutionRestController;
use TT\Modules\MatchPrep\Frontend\FrontendMatchPrepView;
use TT\Modules\MatchPrep\Rest\MatchPrepRestController;

/**
 * #3151 — the match-day detail surfaces take an `activity_id` from the
 * request. `tt_edit_activities` / `tt_view_activities` are held club-wide
 * by every coach, so on their own they answer "does this user run
 * matches?" and never "whose matches?".
 *
 * The sibling list view narrows to the viewer's teams; the three detail
 * views it links to, and the two REST controllers behind them, did not.
 * All five now ask `ActivityTeamScope`.
 *
 * This file was named `MatchDayActivityScopeTest` for one release so that it
 * would sort *after* `DemoRunChunkingTest`, whose generator comparison read
 * every activity in the club and so changed answer if a test file that wrote
 * to `tt_activities` ran first. #3184 scoped those generators to the batch
 * they write, so the ordering constraint is gone and the name is back to
 * describing the helper under test.
 */
final class ActivityTeamScopeTest extends WP_UnitTestCase {

    private int $mineTeamId    = 0;
    private int $otherTeamId   = 0;
    private int $otherActivity = 0;
    private int $coachUserId   = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        // TT roles install on activation, which the wp-env bootstrap does
        // not fire; without them `tt_coach` holds no `tt_edit_activities`
        // and every assertion below would pass for the wrong reason.
        ( new \TT\Infrastructure\Security\RolesService() )->installRoles();
        \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Scope Mine' ] );
        $this->mineTeamId = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Scope Theirs' ] );
        $this->otherTeamId = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'team_id'           => $this->otherTeamId,
            'title'             => 'Someone else’s game',
            'session_date'      => '2026-03-01',
            'activity_type_key' => 'game',
        ] );
        $this->otherActivity = (int) $wpdb->insert_id;

        $this->coachUserId = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->grantTeam( $this->coachUserId, $this->mineTeamId );
        wp_set_current_user( $this->coachUserId );
    }

    public function tear_down(): void {
        unset( $_GET['activity_id'] );
        parent::tear_down();
    }

    /**
     * Give the user a `tt_people` row and an active team-scope grant —
     * what `QueryHelpers::get_teams_for_coach()` reads.
     */
    private function grantTeam( int $user_id, int $team_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Scope',
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

    private function request( string $param, int $value ): \WP_REST_Request {
        $r = new \WP_REST_Request();
        $r->set_param( $param, $value );
        return $r;
    }

    private function renderWithActivity( callable $render ): string {
        $_GET['activity_id'] = (string) $this->otherActivity;
        ob_start();
        $render();
        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------
    // the helper itself
    // ---------------------------------------------------------------

    public function test_the_helper_covers_a_coached_team_and_not_a_sibling(): void {
        $this->assertTrue(
            ActivityTeamScope::coversTeam( $this->coachUserId, $this->mineTeamId, false )
        );
        $this->assertFalse(
            ActivityTeamScope::coversTeam( $this->coachUserId, $this->otherTeamId, false )
        );
    }

    public function test_the_admin_flag_and_an_unknown_activity(): void {
        $this->assertTrue(
            ActivityTeamScope::coversTeam( $this->coachUserId, $this->otherTeamId, true ),
            'the dispatcher admin flag still opens every team'
        );
        $this->assertNull( ActivityTeamScope::teamIdForActivity( 0 ) );
        $this->assertNull(
            ActivityTeamScope::teamIdForActivity( $this->otherActivity + 100000 ),
            'an activity that does not exist has no team, which is not the same as "not yours"'
        );
        $this->assertSame(
            $this->otherTeamId,
            ActivityTeamScope::teamIdForActivity( $this->otherActivity )
        );
    }

    // ---------------------------------------------------------------
    // the three views
    // ---------------------------------------------------------------

    public function test_match_prep_refuses_an_activity_on_another_team(): void {
        $html = $this->renderWithActivity( function () {
            FrontendMatchPrepView::render( $this->coachUserId, false );
        } );
        $this->assertStringContainsString( 'do not coach', $html );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html, 'CLAUDE.md §5 — the refusal still renders the chain' );
    }

    public function test_match_execution_refuses_an_activity_on_another_team(): void {
        $html = $this->renderWithActivity( function () {
            FrontendMatchExecutionView::render( $this->coachUserId, false );
        } );
        $this->assertStringContainsString( 'do not coach', $html );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html );
    }

    public function test_match_analysis_refuses_an_activity_on_another_team(): void {
        $html = $this->renderWithActivity( function () {
            FrontendMatchAnalysisView::render( $this->coachUserId, false );
        } );
        $this->assertStringContainsString( 'do not coach', $html );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html );
    }

    // ---------------------------------------------------------------
    // the two REST controllers
    // ---------------------------------------------------------------

    public function test_match_execution_rest_refuses_an_out_of_scope_activity(): void {
        $this->assertFalse( MatchExecutionRestController::can_edit(
            $this->request( 'activity_id', $this->otherActivity )
        ) );
    }

    public function test_match_prep_rest_refuses_an_out_of_scope_activity(): void {
        $this->assertFalse( MatchPrepRestController::can_edit(
            $this->request( 'activity_id', $this->otherActivity )
        ) );
    }

    public function test_the_prep_keyed_role_routes_resolve_the_activity_behind_the_prep(): void {
        $prep_id = ( new \TT\Modules\MatchPrep\Repositories\MatchPrepRepository() )
            ->ensureForActivity( $this->otherActivity );
        $this->assertGreaterThan( 0, $prep_id );

        $this->assertFalse( MatchPrepRestController::can_edit_role(
            $this->request( 'prep_id', $prep_id )
        ) );
        $this->assertFalse(
            MatchPrepRestController::can_edit_role( $this->request( 'prep_id', 0 ) ),
            'a prep that does not exist is not reachable either'
        );
    }

    public function test_a_coach_on_the_activitys_own_team_is_still_let_through(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'team_id'           => $this->mineTeamId,
            'title'             => 'My own game',
            'session_date'      => '2026-03-02',
            'activity_type_key' => 'game',
        ] );
        $mine = (int) $wpdb->insert_id;

        $this->assertTrue( MatchExecutionRestController::can_edit(
            $this->request( 'activity_id', $mine )
        ) );
        $this->assertTrue( MatchPrepRestController::can_edit(
            $this->request( 'activity_id', $mine )
        ) );
    }
}
