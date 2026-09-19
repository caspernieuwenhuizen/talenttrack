<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendAccessControl;
use TT\Shared\Frontend\FrontendUsageStatsView;

/**
 * #3595 — a frontend view links into wp-admin only for someone wp-admin
 * lets in.
 *
 * The Application KPIs screen showed "Open in wp-admin" to everyone who could
 * open it. `FrontendAccessControl::restrictWpAdmin()` sends every
 * non-administrator back to the dashboard, so for a Head of Development the
 * button was a dead link.
 */
final class WpAdminLinkReachabilityTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_head_of_development_gets_no_wp_admin_button(): void {
        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        wp_set_current_user( $hod );
        $this->assertTrue( current_user_can( 'tt_view_analytics' ), 'precondition: the screen opens for them' );

        $html = $this->render( $hod );

        $this->assertStringContainsString( 'tt-usage-kpis', $html, 'the screen itself renders' );
        $this->assertStringNotContainsString( 'tt-usage-periods__admin', $html );
        $this->assertFalse( FrontendAccessControl::canReachWpAdmin( 'tt_view_settings' ) );
    }

    public function test_an_administrator_still_gets_it(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );

        $html = $this->render( $admin );

        $this->assertStringContainsString( 'tt-usage-periods__admin', $html );
        $this->assertStringContainsString( 'page=tt-usage-stats', $html );
        $this->assertTrue( FrontendAccessControl::canReachWpAdmin( 'tt_view_settings' ) );
    }

    public function test_nobody_logged_out_can_reach_it(): void {
        wp_set_current_user( 0 );
        $this->assertFalse( FrontendAccessControl::canReachWpAdmin() );
    }

    private function render( int $user_id ): string {
        ob_start();
        FrontendUsageStatsView::render( $user_id, false );
        return (string) ob_get_clean();
    }
}
