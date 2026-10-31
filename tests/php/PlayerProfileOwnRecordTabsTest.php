<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Query\PlayerFileCounts;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;
use TT\Shared\Frontend\FrontendPlayerDetailView;

/**
 * #4256 — a `my_*` grant covers the viewer's own record or their child's,
 * never someone else's.
 *
 * The assistant coach holds `my_evaluations` at `self` for the evaluations
 * they authored, and no `evaluations` at all (#1060). The profile asked for
 * either half at any scope, so the assistant coach got the Evaluations tab
 * and every score on every player of their team, and then a refusal on each
 * row they tapped.
 */
final class PlayerProfileOwnRecordTabsTest extends WP_UnitTestCase {

    private int $team_id    = 0;
    private int $player_id  = 0;
    private int $player_uid = 0;
    private int $parent_uid = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p = $wpdb->prefix;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Own Record U14', 'age_group' => 'U14' ] );
        $this->team_id = (int) $wpdb->insert_id;

        $this->player_uid = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Own',
            'last_name'  => 'Record',
            'team_id'    => $this->team_id,
            'wp_user_id' => $this->player_uid,
            'status'     => 'active',
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $this->parent_uid = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$p}tt_player_parents", [
            'club_id'        => 1,
            'player_id'      => $this->player_id,
            'parent_user_id' => $this->parent_uid,
            'is_primary'     => 1,
        ] );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_an_assistant_coach_gets_no_evaluations_tab_but_keeps_goals_and_activities(): void {
        $uid = $this->makeCoach( false );
        $this->assertFalse(
            MatrixGate::canAnyScope( $uid, 'evaluations', MatrixGate::READ ),
            'Precondition: the assistant coach holds no evaluations read (#1060).'
        );
        $this->assertTrue(
            MatrixGate::canAnyScope( $uid, 'my_evaluations', MatrixGate::READ ),
            'Precondition: the assistant coach holds my_evaluations for their own authored evaluations.'
        );

        $tabs = $this->tabsFor( $uid );

        $this->assertArrayNotHasKey( 'evaluations', $tabs );
        $this->assertArrayHasKey( 'goals', $tabs );
        $this->assertArrayHasKey( 'activities', $tabs );
    }

    public function test_the_evaluations_tab_url_shows_an_assistant_coach_no_scores(): void {
        $uid  = $this->makeCoach( false );
        $html = $this->evaluationsTabFor( $uid );

        $this->assertStringContainsString( 'You do not have permission to view evaluations for this player.', $html );
    }

    public function test_a_head_coach_keeps_all_three_tabs(): void {
        $uid  = $this->makeCoach( true );
        $tabs = $this->tabsFor( $uid );

        $this->assertArrayHasKey( 'evaluations', $tabs );
        $this->assertArrayHasKey( 'goals', $tabs );
        $this->assertArrayHasKey( 'activities', $tabs );
        $this->assertStringNotContainsString(
            'You do not have permission to view evaluations for this player.',
            $this->evaluationsTabFor( $uid )
        );
    }

    public function test_a_player_keeps_all_three_tabs_on_their_own_profile(): void {
        $tabs = $this->tabsFor( $this->player_uid );

        $this->assertArrayHasKey( 'evaluations', $tabs );
        $this->assertArrayHasKey( 'goals', $tabs );
        $this->assertArrayHasKey( 'activities', $tabs );
        $this->assertStringNotContainsString(
            'You do not have permission to view evaluations for this player.',
            $this->evaluationsTabFor( $this->player_uid )
        );
    }

    public function test_a_parent_keeps_all_three_tabs_on_their_childs_profile(): void {
        $tabs = $this->tabsFor( $this->parent_uid );

        $this->assertArrayHasKey( 'evaluations', $tabs );
        $this->assertArrayHasKey( 'goals', $tabs );
        $this->assertArrayHasKey( 'activities', $tabs );
        $this->assertStringNotContainsString(
            'You do not have permission to view evaluations for this player.',
            $this->evaluationsTabFor( $this->parent_uid )
        );
    }

    public function test_a_parent_reads_no_sections_of_another_familys_player(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Someone',
            'last_name'  => 'Else',
            'team_id'    => $this->team_id,
            'status'     => 'active',
        ] );
        $other = (int) $wpdb->insert_id;

        foreach ( [ 'evaluations', 'goals', 'activities' ] as $entity ) {
            $this->assertTrue( AuthorizationService::canReadPlayerSection( $this->parent_uid, $this->player_id, $entity ), "own child: {$entity}" );
            $this->assertFalse( AuthorizationService::canReadPlayerSection( $this->parent_uid, $other, $entity ), "other family: {$entity}" );
        }
        $tabs = $this->tabsFor( $this->parent_uid, $other );
        $this->assertArrayNotHasKey( 'evaluations', $tabs );
        $this->assertArrayNotHasKey( 'goals', $tabs );
        $this->assertArrayNotHasKey( 'activities', $tabs );
    }

    /**
     * A grant on one team never opens another team's player. This head
     * coach reads evaluations on team A, and opens team B's players through
     * a kit-manager role there. Before this, `canAnyScope( 'evaluations' )`
     * was true and team B's evaluations were on screen.
     */
    public function test_evaluations_on_one_team_do_not_open_another_teams_player(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Own Record U16', 'age_group' => 'U16' ] );
        $team_a = (int) $wpdb->insert_id;

        $uid = $this->makeCoach( true, $team_a );
        $this->assignFunctionalRole( $uid, $this->team_id, 'kit_manager' );

        $this->assertTrue(
            AuthorizationService::canViewPlayer( $uid, $this->player_id ),
            'Precondition: the account opens the team-B player.'
        );
        $this->assertTrue(
            MatrixGate::canAnyScope( $uid, 'evaluations', MatrixGate::READ ),
            'Precondition: the account reads evaluations somewhere (team A).'
        );

        $tabs = $this->tabsFor( $uid );
        $this->assertArrayNotHasKey( 'evaluations', $tabs );
        $this->assertArrayNotHasKey( 'goals', $tabs );
        $this->assertStringContainsString(
            'You do not have permission to view evaluations for this player.',
            $this->evaluationsTabFor( $uid )
        );
        $this->assertStringNotContainsString( 'Avg rating', $this->glanceFor( $uid ) );
    }

    public function test_the_avg_rating_tile_follows_the_evaluations_tab(): void {
        $assistant = $this->makeCoach( false );
        $head      = $this->makeCoach( true );

        $this->assertStringNotContainsString( 'Avg rating', $this->glanceFor( $assistant ) );
        $this->assertStringContainsString( 'Avg rating', $this->glanceFor( $head ) );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @return array<string,string> */
    private function tabsFor( int $user_id, ?int $player_id = null ): array {
        wp_set_current_user( $user_id );
        $method = new ReflectionMethod( FrontendPlayerDetailView::class, 'tabs' );
        $method->setAccessible( true );
        return (array) $method->invoke( null, $user_id, $player_id ?? $this->player_id );
    }

    private function glanceFor( int $user_id ): string {
        wp_set_current_user( $user_id );
        $player = QueryHelpers::get_player( $this->player_id );
        $this->assertNotNull( $player );
        $method = new ReflectionMethod( FrontendPlayerDetailView::class, 'renderAtAGlance' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $this->player_id, $player, home_url( '/' ), PlayerFileCounts::for( $this->player_id ) );
        return (string) ob_get_clean();
    }

    private function assignFunctionalRole( int $user_id, int $team_id, string $role_key ): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_functional_roles";
        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE role_key = %s AND club_id = %d",
            $role_key, 1
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( $table, [
                'club_id'     => 1,
                'role_key'    => $role_key,
                'label'       => ucfirst( str_replace( '_', ' ', $role_key ) ),
                'description' => 'Created by PlayerProfileOwnRecordTabsTest.',
                'is_system'   => 1,
                'sort_order'  => 90,
            ] );
            $role_id = (int) $wpdb->insert_id;
        }
        $person_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d LIMIT 1",
            $user_id
        ) );
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id'            => 1,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
            'role_in_team'       => $role_key,
        ] );
        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
    }

    private function evaluationsTabFor( int $user_id ): string {
        wp_set_current_user( $user_id );
        $method = new ReflectionMethod( FrontendPlayerDetailView::class, 'renderEvaluationsTab' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $this->player_id );
        return (string) ob_get_clean();
    }

    /** A `tt_coach` assigned to a team (the player's by default); `is_head_coach` splits the persona. */
    private function makeCoach( bool $is_head_coach, ?int $team_id = null ): int {
        global $wpdb;
        $p    = $wpdb->prefix;
        $team = $team_id ?? $this->team_id;

        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => $is_head_coach ? 'Hoofd' : 'Assistent',
            'last_name'  => 'Trainer',
            'role_type'  => $is_head_coach ? 'head_coach' : 'assistant_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $team,
            'person_id'     => $person_id,
            'role_in_team'  => $is_head_coach ? 'head_coach' : 'assistant_coach',
            'is_head_coach' => $is_head_coach ? 1 : 0,
        ] );

        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team,
        ] );

        $this->assertContains(
            $is_head_coach ? 'head_coach' : 'assistant_coach',
            PersonaResolver::personasFor( $uid ),
            'the fixture must resolve to the persona under test'
        );

        return $uid;
    }
}
