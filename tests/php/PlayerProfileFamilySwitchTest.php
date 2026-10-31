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
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => 1, 'first_name' => 'Family', 'last_name' => 'Childx',
            'team_id' => $team, 'status' => 'active',
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

    public function test_a_private_tab_cannot_be_opened_by_url(): void {
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'evaluations', false );
        $_GET['tab'] = 'evaluations';

        $html = $this->profile();
        $this->assertStringNotContainsString( 'tab=evaluations', $html );
        $this->assertStringNotContainsString( 'You do not have permission to view evaluations', $html, 'the profile tab opens instead, not the evaluations tab' );
    }

    private function profile(): string {
        wp_set_current_user( $this->parent );
        AuthorizationService::flushCache();
        ob_start();
        FrontendPlayerDetailView::render( $this->player, $this->parent, false );
        return (string) ob_get_clean();
    }
}
