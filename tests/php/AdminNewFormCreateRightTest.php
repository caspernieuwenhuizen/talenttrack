<?php
namespace TT\Tests\Php;

use WPDieException;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Players\Admin\PlayersPage;
use TT\Modules\Teams\Admin\TeamsPage;

/**
 * #4349 — wp-admin's `action=new` asks the create right before the form.
 *
 * The "Add New" link was hidden from a head coach, but the URL behind it
 * still rendered the empty form, and the save refused it. The page now
 * gives the save's refusal up front; `action=edit` keeps its own check.
 */
final class AdminNewFormCreateRightTest extends WP_UnitTestCase {

    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        if ( ! function_exists( 'submit_button' ) ) {
            require_once ABSPATH . 'wp-admin/includes/template.php';
        }

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => 'New-form U13', 'age_group' => 'U13' ] );
        $this->team = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        unset( $_GET['action'], $_GET['id'] );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * @param callable():void $render
     */
    private function renderAs( int $user_id, string $action, int $id, callable $render ): string {
        wp_set_current_user( $user_id );
        $_GET['action'] = $action;
        if ( $id > 0 ) {
            $_GET['id'] = (string) $id;
        }
        ob_start();
        try {
            $render();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    public function test_a_head_coach_is_refused_the_new_team_form(): void {
        $coach = $this->makeHeadCoach( $this->team );
        $this->assertFalse( AuthorizationService::canCreateTeam( $coach ), 'fixture sanity' );

        try {
            $this->renderAs( $coach, 'new', 0, [ TeamsPage::class, 'render_page' ] );
            $this->fail( 'the new-team form rendered for a head coach' );
        } catch ( WPDieException $e ) {
            $this->assertStringContainsString( 'Unauthorized', $e->getMessage() );
        }
    }

    public function test_a_head_coach_is_refused_the_new_player_form(): void {
        $coach = $this->makeHeadCoach( $this->team );
        $this->assertFalse( AuthorizationService::canCreatePlayer( $coach ), 'fixture sanity' );

        try {
            $this->renderAs( $coach, 'new', 0, [ PlayersPage::class, 'render_page' ] );
            $this->fail( 'the new-player form rendered for a head coach' );
        } catch ( WPDieException $e ) {
            $this->assertStringContainsString( 'Unauthorized', $e->getMessage() );
        }
    }

    /**
     * `action=edit` without a record renders the same empty create form,
     * so it gets the same refusal: no id, and id=0.
     *
     * @return array<string, array{0:string, 1:int}>
     */
    public function editWithoutARecord(): array {
        return [
            'teams, no id'   => [ TeamsPage::class, -1 ],
            'teams, id=0'    => [ TeamsPage::class, 0 ],
            'players, no id' => [ PlayersPage::class, -1 ],
            'players, id=0'  => [ PlayersPage::class, 0 ],
        ];
    }

    /**
     * @dataProvider editWithoutARecord
     */
    public function test_a_head_coach_is_refused_edit_without_a_record( string $page, int $id ): void {
        $coach = $this->makeHeadCoach( $this->team );
        if ( $id === 0 ) {
            $_GET['id'] = '0';
        }

        try {
            $this->renderAs( $coach, 'edit', 0, [ $page, 'render_page' ] );
            $this->fail( 'the empty create form rendered for a head coach through action=edit' );
        } catch ( WPDieException $e ) {
            $this->assertStringContainsString( 'Unauthorized', $e->getMessage() );
        }
    }

    public function test_the_head_of_development_gets_both_new_forms(): void {
        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        $this->assertStringContainsString(
            'value="tt_save_team"',
            $this->renderAs( $hod, 'new', 0, [ TeamsPage::class, 'render_page' ] )
        );
        $this->assertStringContainsString(
            'value="tt_save_player"',
            $this->renderAs( $hod, 'new', 0, [ PlayersPage::class, 'render_page' ] )
        );
    }

    public function test_a_head_coach_still_opens_the_edit_form_of_their_own_team(): void {
        $coach = $this->makeHeadCoach( $this->team );

        $this->assertStringContainsString(
            'value="tt_save_team"',
            $this->renderAs( $coach, 'edit', $this->team, [ TeamsPage::class, 'render_page' ] )
        );
    }

    public function test_a_head_coach_still_opens_the_edit_form_of_their_own_player(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Squad',
            'last_name'  => 'Newform',
            'team_id'    => $this->team,
            'status'     => 'active',
        ] );
        $player = (int) $wpdb->insert_id;
        $coach  = $this->makeHeadCoach( $this->team );

        $this->assertStringContainsString(
            'value="tt_save_player"',
            $this->renderAs( $coach, 'edit', $player, [ PlayersPage::class, 'render_page' ] )
        );
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
