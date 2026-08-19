<?php
/**
 * Pre-authentication chrome for the MFA challenge screens (#2554).
 *
 * The challenge used to render inside the authenticated app shell — nav
 * rail, global search, notification bell, persona menu — around a code
 * field, for a session that had a cookie but had not cleared its second
 * factor. What's pinned here is the two units that decision rests on: the
 * guard can be asked whether a challenge is open without reading
 * transients by hand, and the breadcrumb chain can be silenced for a
 * request so the shared wizard view doesn't smuggle navigation onto a
 * pre-auth screen.
 */

use TT\Modules\Mfa\Auth\MfaLoginGuard;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

class MfaPreAuthChromeTest extends WP_UnitTestCase {

    private const PENDING_PREFIX = 'tt_mfa_pending_';
    private const ENROLL_PREFIX  = 'tt_mfa_must_enroll_';

    private int $user_id = 0;

    public function set_up(): void {
        parent::set_up();
        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    public function tear_down(): void {
        delete_transient( self::PENDING_PREFIX . $this->user_id );
        delete_transient( self::ENROLL_PREFIX . $this->user_id );
        FrontendBreadcrumbs::suppress( false );
        parent::tear_down();
    }

    public function test_no_challenge_reports_clean(): void {
        $this->assertFalse( MfaLoginGuard::isPending( $this->user_id ) );
        $this->assertFalse( MfaLoginGuard::mustEnroll( $this->user_id ) );
        $this->assertFalse( MfaLoginGuard::hasOpenChallenge( $this->user_id ) );
    }

    public function test_pending_challenge_is_open(): void {
        set_transient( self::PENDING_PREFIX . $this->user_id, 1, 900 );

        $this->assertTrue( MfaLoginGuard::isPending( $this->user_id ) );
        $this->assertFalse( MfaLoginGuard::mustEnroll( $this->user_id ) );
        $this->assertTrue( MfaLoginGuard::hasOpenChallenge( $this->user_id ) );
    }

    public function test_must_enroll_challenge_is_open(): void {
        set_transient( self::ENROLL_PREFIX . $this->user_id, 1, 900 );

        $this->assertFalse( MfaLoginGuard::isPending( $this->user_id ) );
        $this->assertTrue( MfaLoginGuard::mustEnroll( $this->user_id ) );
        $this->assertTrue( MfaLoginGuard::hasOpenChallenge( $this->user_id ) );
    }

    public function test_logged_out_user_has_no_challenge(): void {
        $this->assertFalse( MfaLoginGuard::isPending( 0 ) );
        $this->assertFalse( MfaLoginGuard::mustEnroll( 0 ) );
        $this->assertFalse( MfaLoginGuard::hasOpenChallenge( 0 ) );
    }

    public function test_breadcrumbs_render_by_default(): void {
        ob_start();
        FrontendBreadcrumbs::fromDashboard( 'Two-factor authentication' );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'tt-breadcrumbs', $html );
    }

    public function test_suppressed_breadcrumbs_render_nothing(): void {
        FrontendBreadcrumbs::suppress();

        ob_start();
        FrontendBreadcrumbs::fromDashboard( 'Two-factor authentication' );
        FrontendBreadcrumbs::render( [ [ 'label' => 'Anything' ] ] );
        $html = (string) ob_get_clean();

        $this->assertSame( '', $html, 'A pre-auth screen must emit no chain and no back-pill.' );
    }

    public function test_suppression_can_be_lifted(): void {
        FrontendBreadcrumbs::suppress();
        FrontendBreadcrumbs::suppress( false );

        ob_start();
        FrontendBreadcrumbs::fromDashboard( 'Players' );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'tt-breadcrumbs', $html );
    }
}
