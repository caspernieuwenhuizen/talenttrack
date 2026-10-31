<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\AuthorizationModule;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Widgets\ActionCardWidget;

/**
 * #4348 — the dashboard action cards follow the matrix, as the views do.
 *
 * The cards asked `current_user_can()`. With the `user_has_cap` bridge
 * dormant, a head of development holding `team: create_delete` in the
 * matrix lost the "Add team" card while the teams view still offered
 * "+ New team".
 */
final class ActionCardMatrixFallbackTest extends WP_UnitTestCase {

    private const BRIDGE = [ AuthorizationModule::class, 'filterUserHasCap' ];

    /** @var int|false */
    private $bridge_priority = false;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        // A matrix-inactive install: the raw caps are all there is.
        $this->bridge_priority = has_filter( 'user_has_cap', self::BRIDGE );
        remove_filter( 'user_has_cap', self::BRIDGE, 10 );
    }

    public function tear_down(): void {
        remove_filter( 'user_has_cap', self::BRIDGE, 10 );
        if ( $this->bridge_priority !== false ) {
            add_filter( 'user_has_cap', self::BRIDGE, (int) $this->bridge_priority, 4 );
        }
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function renderAddTeamFor( int $user_id ): string {
        wp_set_current_user( $user_id );
        $html = ( new ActionCardWidget() )->render(
            new WidgetSlot( 'action_card', 'new_team' ),
            new RenderContext( $user_id, 1, 'head_of_development', home_url( '/' ) )
        );
        wp_set_current_user( 0 );
        return $html;
    }

    public function test_the_matrix_grant_shows_the_card_without_the_raw_cap(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        ( new \WP_User( $uid ) )->add_cap( 'tt_manage_teams', false );
        AuthorizationService::flushCache();

        $this->assertFalse( user_can( $uid, 'tt_manage_teams' ), 'fixture sanity: the raw cap is withheld' );
        $this->assertTrue(
            AuthorizationService::userCanOrMatrix( $uid, 'tt_manage_teams' ),
            'fixture sanity: the matrix grants team: create_delete'
        );

        $this->assertStringContainsString( 'tt-pd-action-link', $this->renderAddTeamFor( $uid ) );
    }

    public function test_with_neither_the_raw_cap_nor_the_matrix_grant_there_is_no_card(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        AuthorizationService::flushCache();

        $this->assertFalse( AuthorizationService::userCanOrMatrix( $uid, 'tt_manage_teams' ), 'fixture sanity' );

        $this->assertSame( '', $this->renderAddTeamFor( $uid ) );
    }

    /**
     * A coach with no team and the raw cap withheld: neither the cap nor
     * a matrix grant, so every destination a card links to refuses.
     */
    private function coachWithout( string ...$caps ): int {
        $uid  = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $user = new \WP_User( $uid );
        foreach ( $caps as $cap ) {
            $user->add_cap( $cap, false );
        }
        AuthorizationService::flushCache();
        foreach ( $caps as $cap ) {
            $this->assertFalse( AuthorizationService::userCanOrMatrix( $uid, $cap ), 'fixture sanity: no ' . $cap );
        }
        return $uid;
    }

    /** @param callable(int, bool):void $render */
    private function renderView( int $user_id, callable $render ): string {
        wp_set_current_user( $user_id );
        ob_start();
        $render( $user_id, false );
        $html = (string) ob_get_clean();
        wp_set_current_user( 0 );
        return $html;
    }

    public function test_scout_history_refuses_without_the_cap_or_the_grant(): void {
        $uid = $this->coachWithout( 'tt_generate_scout_report' );

        $this->assertStringContainsString(
            'You need scout-management permission',
            $this->renderView( $uid, [ \TT\Modules\Reports\Frontend\FrontendScoutHistoryView::class, 'render' ] )
        );
    }

    public function test_test_trainings_refuses_without_the_cap_or_the_grant(): void {
        $uid = $this->coachWithout( 'tt_edit_prospects', 'tt_manage_prospects' );

        $this->assertStringContainsString(
            'You do not have permission to create test trainings.',
            $this->renderView( $uid, [ \TT\Shared\Frontend\FrontendTestTrainingsView::class, 'render' ] )
        );
    }

    public function test_the_evaluations_list_offers_no_new_evaluation_without_the_cap_or_the_grant(): void {
        $uid = $this->coachWithout( 'tt_edit_evaluations' );

        $this->assertStringNotContainsString(
            'New evaluation',
            $this->renderView( $uid, [ \TT\Shared\Frontend\FrontendEvaluationsView::class, 'render' ] )
        );
    }

    public function test_the_goals_list_offers_no_add_goal_without_the_cap_or_the_grant(): void {
        $uid = $this->coachWithout( 'tt_edit_goals' );

        $this->assertStringNotContainsString(
            'Add goal',
            $this->renderView( $uid, [ \TT\Shared\Frontend\FrontendGoalsManageView::class, 'render' ] )
        );
    }

    public function test_the_administrator_still_gets_the_card(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $this->assertStringContainsString( 'tt-pd-action-link', $this->renderAddTeamFor( $uid ) );
    }
}
