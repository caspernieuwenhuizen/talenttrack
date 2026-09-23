<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendActivitiesManageView;
use TT\Shared\Frontend\FrontendEvaluationsView;
use TT\Shared\Frontend\FrontendPlayerStatusCaptureView;
use TT\Shared\Frontend\FrontendPlayersManageView;

/**
 * #4001 — a detail or edit view checks the record, not only the capability.
 *
 * `FrontendPlayerDetailView` has asked `canViewPlayer` since #3158 and the
 * evaluation detail has asked its per-player question since #3949. Their
 * siblings loaded a record by id and rendered whatever came back, on a
 * capability every coach holds club-wide.
 *
 * Each case asserts the grant and the refusal together, and that the refusal
 * reads as the not-found the view already had — including the breadcrumb
 * chain, which CLAUDE.md §5 requires on every code path.
 */
final class RecordScopeViewTest extends WP_UnitTestCase {

    private int $admin         = 0;
    private int $coach         = 0;
    private int $mineTeam      = 0;
    private int $otherTeam     = 0;
    private int $minePlayer    = 0;
    private int $otherPlayer   = 0;
    private int $mineActivity  = 0;
    private int $otherActivity = 0;
    private int $mineEval      = 0;
    private int $otherEval     = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'View team mine' ] );
        $this->mineTeam = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'View team theirs' ] );
        $this->otherTeam = (int) $wpdb->insert_id;

        $this->minePlayer  = $this->makePlayer( 'Viewalpha', $this->mineTeam );
        $this->otherPlayer = $this->makePlayer( 'Viewbravo', $this->otherTeam );

        $this->mineActivity  = $this->makeActivity( $this->mineTeam, 'View training mine' );
        $this->otherActivity = $this->makeActivity( $this->otherTeam, 'View training theirs' );

        $this->mineEval  = $this->makeEval( $this->minePlayer, 'View note mine' );
        $this->otherEval = $this->makeEval( $this->otherPlayer, 'View note theirs' );

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->coach = $this->makeHeadCoach( $this->mineTeam );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        $_GET = [];
        parent::tear_down();
    }

    public function test_the_player_detail_and_edit_routes_refuse_another_squads_player(): void {
        $this->assertTrue( $this->coachCan( 'tt_edit_players' ), 'the coach holds the club-wide capability, so the refusal comes from the per-record check' );

        $mine = $this->render( FrontendPlayersManageView::class, [ 'tt_view' => 'players', 'player_id' => (string) $this->minePlayer ] );
        $this->assertStringContainsString( 'Viewalpha', $mine, 'the grant: the squad coach opens their own player' );

        $theirs = $this->render( FrontendPlayersManageView::class, [ 'tt_view' => 'players', 'player_id' => (string) $this->otherPlayer ] );
        $this->assertStringNotContainsString( 'Viewbravo', $theirs );

        $mine_edit = $this->render( FrontendPlayersManageView::class, [ 'tt_view' => 'players', 'id' => (string) $this->minePlayer ] );
        $this->assertStringContainsString( 'Viewalpha', $mine_edit, 'the grant: the squad coach edits their own player' );

        $theirs_edit = $this->render( FrontendPlayersManageView::class, [ 'tt_view' => 'players', 'id' => (string) $this->otherPlayer ] );
        $this->assertStringNotContainsString( 'Viewbravo', $theirs_edit );
        $this->assertStringContainsString( 'That player no longer exists.', $theirs_edit, 'answered as not found' );
        $this->assertBreadcrumbed( $theirs_edit );
    }

    public function test_the_evaluation_edit_branch_refuses_exactly_as_the_detail_branch(): void {
        $detail = $this->render( FrontendEvaluationsView::class, [ 'tt_view' => 'evaluations', 'id' => (string) $this->otherEval ] );
        $edit   = $this->render( FrontendEvaluationsView::class, [ 'tt_view' => 'evaluations', 'id' => (string) $this->otherEval, 'action' => 'edit' ] );

        $this->assertStringNotContainsString( 'View note theirs', $detail, 'the detail branch has refused since #3949' );
        $this->assertStringNotContainsString( 'View note theirs', $edit, 'and the edit branch now refuses too' );
        $this->assertBreadcrumbed( $edit );

        $mine = $this->render( FrontendEvaluationsView::class, [ 'tt_view' => 'evaluations', 'id' => (string) $this->mineEval, 'action' => 'edit' ] );
        $this->assertStringContainsString( 'View note mine', $mine, 'the grant: the squad coach edits their own player\'s evaluation' );
    }

    public function test_the_activity_detail_and_edit_routes_refuse_another_teams_activity(): void {
        $mine = $this->render( FrontendActivitiesManageView::class, [ 'tt_view' => 'activities', 'id' => (string) $this->mineActivity ] );
        $this->assertStringContainsString( 'View training mine', $mine, 'the grant: the squad coach opens their own activity' );

        $theirs = $this->render( FrontendActivitiesManageView::class, [ 'tt_view' => 'activities', 'id' => (string) $this->otherActivity ] );
        $this->assertStringNotContainsString( 'View training theirs', $theirs );
        $this->assertStringContainsString( 'That activity no longer exists.', $theirs );
        $this->assertBreadcrumbed( $theirs );

        $theirs_edit = $this->render( FrontendActivitiesManageView::class, [ 'tt_view' => 'activities', 'id' => (string) $this->otherActivity, 'action' => 'edit' ] );
        $this->assertStringNotContainsString( 'View training theirs', $theirs_edit );
        $this->assertStringContainsString( 'That activity no longer exists.', $theirs_edit );
        $this->assertBreadcrumbed( $theirs_edit );
    }

    public function test_the_status_capture_screen_refuses_a_player_the_caller_is_not_staff_for(): void {
        $mine = $this->render( FrontendPlayerStatusCaptureView::class, [ 'tt_view' => 'player-status-capture', 'player_id' => (string) $this->minePlayer ] );
        $this->assertStringNotContainsString( 'Player not found', $mine, 'the grant: the squad coach reaches their own player' );

        $theirs = $this->render( FrontendPlayerStatusCaptureView::class, [ 'tt_view' => 'player-status-capture', 'player_id' => (string) $this->otherPlayer ] );
        $this->assertStringNotContainsString( 'Viewbravo', $theirs );
        $this->assertStringContainsString( 'Player not found', $theirs, 'answered as a player who is not there' );
        $this->assertBreadcrumbed( $theirs );
    }

    public function test_an_academy_admin_still_reaches_every_record(): void {
        $player = $this->render( FrontendPlayersManageView::class, [ 'tt_view' => 'players', 'id' => (string) $this->otherPlayer ], $this->admin );
        $this->assertStringContainsString( 'Viewbravo', $player );

        $activity = $this->render( FrontendActivitiesManageView::class, [ 'tt_view' => 'activities', 'id' => (string) $this->otherActivity ], $this->admin );
        $this->assertStringContainsString( 'View training theirs', $activity );
    }

    // Helpers

    /** Every refusal keeps the chain (CLAUDE.md §5). */
    private function assertBreadcrumbed( string $html ): void {
        $this->assertStringContainsString( 'tt-breadcrumbs', $html, 'a refusal keeps its breadcrumb chain' );
    }

    private function coachCan( string $cap ): bool {
        wp_set_current_user( $this->coach );
        AuthorizationService::flushCache();
        return current_user_can( $cap );
    }

    /**
     * @param class-string        $view
     * @param array<string,string> $get
     */
    private function render( string $view, array $get, ?int $user_id = null ): string {
        $user_id = $user_id ?? $this->coach;
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $_GET = $get;
        ob_start();
        $view::render( $user_id, user_can( $user_id, 'tt_edit_settings' ) );
        $html = (string) ob_get_clean();
        $_GET = [];
        return $html;
    }

    // Fixtures

    private function makePlayer( string $last, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'View',
            'last_name'     => $last,
            'team_id'       => $team_id,
            'status'        => 'active',
            'date_of_birth' => '2011-04-02',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write the player' );
        return $id;
    }

    private function makeActivity( int $team_id, string $title ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'           => (int) CurrentClub::id(),
            'team_id'           => $team_id,
            'title'             => $title,
            'session_date'      => '2026-03-01',
            'activity_type_key' => 'training',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write the activity' );
        return $id;
    }

    private function makeEval( int $player_id, string $notes ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id'   => (int) CurrentClub::id(),
            'player_id' => $player_id,
            'coach_id'  => 1,
            'eval_date' => '2026-03-02',
            'notes'     => $notes,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write the evaluation' );
        return $id;
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
        $this->assertGreaterThan( 0, $person_id, 'the fixture must write the person' );

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

        AuthorizationService::flushCache();
        $this->assertTrue( AuthorizationService::canEditPlayer( $uid, $this->minePlayer ), 'the coach must reach their own squad, or every refusal here is vacuous' );
        $this->assertFalse( AuthorizationService::canViewPlayer( $uid, $this->otherPlayer ), 'the other squad is outside the coach\'s scope' );
        return $uid;
    }
}
