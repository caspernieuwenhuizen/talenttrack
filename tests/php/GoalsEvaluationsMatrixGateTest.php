<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\AuthorizationModule;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendEvaluationsView;
use TT\Shared\Frontend\FrontendGoalsManageView;

/**
 * #4488 — the goals and evaluations screens ask the matrix-aware check.
 *
 * With the `user_has_cap` bridge dormant, a raw-cap check and a matrix
 * check could answer differently for buttons on the same list. The goals
 * create form had no check at all. Every case runs with the bridge
 * unhooked, as on a matrix-inactive install.
 */
final class GoalsEvaluationsMatrixGateTest extends WP_UnitTestCase {

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

        $this->bridge_priority = has_filter( 'user_has_cap', self::BRIDGE );
        remove_filter( 'user_has_cap', self::BRIDGE, 10 );
    }

    public function tear_down(): void {
        remove_filter( 'user_has_cap', self::BRIDGE, 10 );
        if ( $this->bridge_priority !== false ) {
            add_filter( 'user_has_cap', self::BRIDGE, (int) $this->bridge_priority, 4 );
        }
        unset( $_GET['action'], $_GET['id'] );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** A coach with no team and the raw cap withheld: neither cap nor grant. */
    private function refusedCoach( string $cap ): int {
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        ( new \WP_User( $uid ) )->add_cap( $cap, false );
        AuthorizationService::flushCache();
        $this->assertFalse( AuthorizationService::userCanOrMatrix( $uid, $cap ), 'fixture sanity: no ' . $cap );
        return $uid;
    }

    /** A head of development with the raw cap withheld: the matrix alone grants it. */
    private function matrixOnlyHod( string $cap ): int {
        $uid = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        ( new \WP_User( $uid ) )->add_cap( $cap, false );
        AuthorizationService::flushCache();
        $this->assertFalse( user_can( $uid, $cap ), 'fixture sanity: the raw cap is withheld' );
        $this->assertTrue( AuthorizationService::userCanOrMatrix( $uid, $cap ), 'fixture sanity: the matrix grants ' . $cap );
        return $uid;
    }

    /** @param callable(int, bool):void $render */
    private function renderAs( int $user_id, callable $render, string $action = '', int $id = 0 ): string {
        wp_set_current_user( $user_id );
        if ( $action !== '' ) $_GET['action'] = $action;
        if ( $id > 0 ) $_GET['id'] = (string) $id;
        ob_start();
        $render( $user_id, false );
        $html = (string) ob_get_clean();
        unset( $_GET['action'], $_GET['id'] );
        wp_set_current_user( 0 );
        return $html;
    }

    // Goals: action=new

    public function test_the_goal_create_form_refuses_a_user_without_the_cap_or_the_grant(): void {
        $html = $this->renderAs( $this->refusedCoach( 'tt_edit_goals' ), [ FrontendGoalsManageView::class, 'render' ], 'new' );

        $this->assertStringContainsString( 'You do not have permission to do that.', $html );
        $this->assertStringContainsString( 'Not authorized', $html, 'the refusal keeps its breadcrumb chain' );
    }

    public function test_the_goal_create_form_opens_on_a_matrix_only_grant(): void {
        $html = $this->renderAs( $this->matrixOnlyHod( 'tt_edit_goals' ), [ FrontendGoalsManageView::class, 'render' ], 'new' );

        $this->assertStringNotContainsString( 'You do not have permission to do that.', $html );
        $this->assertStringContainsString( 'Add goal', $html );
    }

    // Empty-state buttons

    public function test_the_goals_empty_state_button_follows_the_matrix(): void {
        $refused = $this->renderAs( $this->refusedCoach( 'tt_edit_goals' ), [ FrontendGoalsManageView::class, 'render' ] );
        $this->assertStringContainsString( 'No goals yet', $refused, 'fixture sanity: the card is rendered' );
        $this->assertStringNotContainsString( 'Add first goal', $refused );

        $granted = $this->renderAs( $this->matrixOnlyHod( 'tt_edit_goals' ), [ FrontendGoalsManageView::class, 'render' ] );
        $this->assertStringContainsString( 'Add first goal', $granted );
    }

    public function test_the_evaluations_empty_state_button_follows_the_matrix(): void {
        $refused = $this->renderAs( $this->refusedCoach( 'tt_edit_evaluations' ), [ FrontendEvaluationsView::class, 'render' ] );
        $this->assertStringContainsString( 'No evaluations yet', $refused, 'fixture sanity: the card is rendered' );
        $this->assertStringNotContainsString( 'Record first evaluation', $refused );

        $granted = $this->renderAs( $this->matrixOnlyHod( 'tt_edit_evaluations' ), [ FrontendEvaluationsView::class, 'render' ] );
        $this->assertStringContainsString( 'Record first evaluation', $granted );
    }

    // Evaluations: action=edit

    public function test_the_evaluation_edit_branch_follows_the_matrix(): void {
        $eval = $this->evaluation();

        $refused = $this->renderAs( $this->refusedCoach( 'tt_edit_evaluations' ), [ FrontendEvaluationsView::class, 'render' ], 'edit', $eval );
        $this->assertStringNotContainsString( 'Edit evaluation', $refused );

        $granted = $this->renderAs( $this->matrixOnlyHod( 'tt_edit_evaluations' ), [ FrontendEvaluationsView::class, 'render' ], 'edit', $eval );
        $this->assertStringContainsString( 'Edit evaluation', $granted );
    }

    private function evaluation(): int {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Matrix U14' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $club,
            'first_name' => 'Matrix',
            'last_name'  => 'Speler',
            'team_id'    => $team,
            'status'     => 'active',
        ] );
        $player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_evaluations", [
            'club_id'   => $club,
            'player_id' => $player,
            'coach_id'  => 1,
            'eval_date' => gmdate( 'Y-m-d' ),
        ] );
        return (int) $wpdb->insert_id;
    }
}
