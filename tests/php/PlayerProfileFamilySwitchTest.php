<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Invitations\PlayerParentsRepository;
use TT\Shared\Frontend\FrontendPlayerDetailView;

/**
 * A parent on their child's profile sees the evaluations and goals the
 * child shares, and nothing the child keeps to themselves: the profile asks
 * the family switch the REST routes ask.
 */
final class PlayerProfileFamilySwitchTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $player = 0;
    private int $parent = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Family U13', 'age_group' => 'U13' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => 1, 'first_name' => 'Family', 'last_name' => 'Childx',
            'team_id' => $this->team, 'status' => 'active',
            'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new PlayerParentsRepository() )->link( $this->player, $this->parent, true );
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        unset( $_GET['tab'] );
        parent::tear_down();
    }

    public function test_shared_sections_show(): void {
        $html = $this->profile();
        $this->assertStringContainsString( 'tab=evaluations', $html, 'precondition: the parent reads the shared evaluations' );
        $this->assertStringContainsString( 'tab=goals', $html, 'precondition: and the shared goals' );
    }

    public function test_sections_the_child_keeps_private_do_not_show(): void {
        $prefs = new PlayerParentVisibilityRepository();
        $prefs->setVisibility( $this->player, 'evaluations', false );
        $prefs->setVisibility( $this->player, 'goals', false );
        $this->assertFalse( AuthorizationService::parentCanViewSection( $this->parent, $this->player, 'evaluations' ), 'precondition: the switch is off' );

        $html = $this->profile();
        $this->assertStringNotContainsString( 'tab=evaluations', $html );
        $this->assertStringNotContainsString( 'tab=goals', $html );
        $this->assertStringNotContainsString( 'Avg rating', $html );
    }

    public function test_every_section_the_child_hides_leaves_the_strip(): void {
        $before = $this->profile();
        $shown  = array_values( array_filter(
            [ 'measurements', 'pdp', 'training', 'tournaments' ],
            static fn( string $tab ): bool => strpos( $before, 'tab=' . $tab ) !== false
        ) );
        $this->assertContains( 'measurements', $shown, 'precondition: the parent reads the shared measurements' );
        $this->assertContains( 'pdp', $shown, 'precondition: and the shared PDP' );

        $prefs = new PlayerParentVisibilityRepository();
        foreach ( PlayerParentVisibilityRepository::SECTIONS as $section ) {
            $prefs->setVisibility( $this->player, $section, false );
        }

        $html = $this->profile();
        foreach ( $shown as $tab ) {
            $this->assertStringNotContainsString( 'tab=' . $tab, $html, $tab . ' tab or tile' );
        }
    }

    public function test_the_teams_coach_still_sees_every_section_the_child_hides_from_family(): void {
        global $wpdb;
        $prefs = new PlayerParentVisibilityRepository();
        foreach ( PlayerParentVisibilityRepository::SECTIONS as $section ) {
            $prefs->setVisibility( $this->player, $section, false );
        }

        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Team', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $this->team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        AuthorizationService::flushCache();

        $html = $this->profile( $coach );
        foreach ( [ 'goals', 'evaluations', 'measurements', 'pdp' ] as $tab ) {
            $this->assertStringContainsString( 'tab=' . $tab, $html, 'the switch is the family\'s, not the coach\'s: ' . $tab );
        }
    }

    public function test_a_private_tab_cannot_be_opened_by_url(): void {
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'evaluations', false );
        $_GET['tab'] = 'evaluations';

        $html = $this->profile();
        $this->assertStringNotContainsString( 'tab=evaluations', $html );
        $this->assertStringNotContainsString( 'You do not have permission to view evaluations', $html, 'the profile tab opens instead, not the evaluations tab' );
    }

    private function profile( int $viewer = 0 ): string {
        $viewer = $viewer > 0 ? $viewer : $this->parent;
        wp_set_current_user( $viewer );
        AuthorizationService::flushCache();
        ob_start();
        FrontendPlayerDetailView::render( $this->player, $viewer, false );
        return (string) ob_get_clean();
    }
}
