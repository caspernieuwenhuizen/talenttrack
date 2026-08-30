<?php
namespace TT\Tests\Php;

use TT\Modules\License\Admin\AccountPage;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\Frontend\FrontendPlanView;
use TT\Modules\License\PlanSummary;
use TT\Modules\Mfa\Frontend\FrontendTwoFactorView;
use WP_UnitTestCase;

/**
 * #3134 — the account page's two user-facing halves on the frontend.
 *
 * The half that did NOT move is as much the subject as the half that did:
 * disabling another user's second factor and the phone-home action stay in
 * wp-admin, and a later refactor that quietly drags them onto a public
 * route is exactly what this asserts against.
 */
final class AccountFrontendPortTest extends WP_UnitTestCase {

    private function viewSource( string $relative ): string {
        return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative );
    }

    // ── What moved ─────────────────────────────────────────────────────

    public function test_the_plan_summary_answers_the_three_tiers(): void {
        $plan = PlanSummary::build();

        $this->assertArrayHasKey( 'tier', $plan );
        $this->assertArrayHasKey( 'effective_tier', $plan );
        $this->assertArrayHasKey( 'paid_tier', $plan );
        $this->assertCount( 2, $plan['caps'], 'Teams and players are the two free-tier caps.' );
        $this->assertSame(
            [ FeatureMap::TIER_FREE, FeatureMap::TIER_STANDARD, FeatureMap::TIER_PRO ],
            array_map( static fn( array $t ): string => $t['key'], $plan['tiers'] )
        );
        $this->assertNotEmpty( $plan['features'], 'The matrix is built from FeatureMap, so it is never empty.' );
    }

    /**
     * Exactly one tier column is flagged current, whatever the install is
     * on — the highlight is what makes the matrix readable, and two of
     * them (or none) means the effective tier stopped resolving.
     */
    public function test_exactly_one_tier_is_marked_as_this_install(): void {
        $plan    = PlanSummary::build();
        $current = array_filter( $plan['tiers'], static fn( array $t ): bool => $t['current'] );
        $this->assertCount( 1, $current );
    }

    public function test_every_feature_row_carries_a_verdict_for_every_tier(): void {
        $plan = PlanSummary::build();
        $keys = array_map( static fn( array $t ): string => $t['key'], $plan['tiers'] );

        foreach ( $plan['features'] as $feature ) {
            foreach ( $keys as $key ) {
                $this->assertArrayHasKey(
                    $key,
                    $feature['tiers'],
                    "Feature {$feature['key']} has no answer for tier {$key}."
                );
            }
        }
    }

    // ── What did not move ──────────────────────────────────────────────

    /**
     * The operator half of the MFA tab — per-club enforcement and
     * resetting somebody else's second factor — must not appear on the
     * frontend view. Asserted by name against the two handler actions,
     * because those are what a form would have to post to.
     */
    public function test_the_frontend_two_factor_view_carries_no_operator_action(): void {
        $src = $this->viewSource( 'src/Modules/Mfa/Frontend/FrontendTwoFactorView.php' );

        $this->assertStringNotContainsString(
            'ACTION_OPERATOR_DISABLE',
            $src,
            "Resetting another user's MFA is an operator recovery action and stays in wp-admin."
        );
        $this->assertStringNotContainsString(
            'ACTION_SAVE_PERSONAS',
            $src,
            'Per-club MFA enforcement is an install-wide setting and stays in wp-admin.'
        );
        $this->assertStringNotContainsString(
            'tt_phone_home_now',
            $src,
            'The phone-home diagnostic is an operator tool and stays in wp-admin.'
        );
    }

    /** The two actions it DOES carry are the ones that act on the caller's own row. */
    public function test_the_frontend_two_factor_view_carries_the_self_service_actions(): void {
        $src = $this->viewSource( 'src/Modules/Mfa/Frontend/FrontendTwoFactorView.php' );

        $this->assertStringContainsString( 'ACTION_REGENERATE', $src );
        $this->assertStringContainsString( 'ACTION_DISABLE', $src );
    }

    /** wp-admin keeps the operator controls, so the page is not retired. */
    public function test_the_wp_admin_account_page_still_exists_with_all_three_tabs(): void {
        $this->assertSame( 'tt-account', AccountPage::SLUG );
        $this->assertSame( 'account', AccountPage::TAB_ACCOUNT );
        $this->assertSame( 'plan', AccountPage::TAB_PLAN );
        $this->assertSame( 'mfa', AccountPage::TAB_MFA );

        $src = $this->viewSource( 'src/Modules/License/Admin/AccountPage.php' );
        $this->assertStringContainsString( 'renderOperatorMfaSection', $src );
        $this->assertStringContainsString( 'renderPhoneHomeDiagnostics', $src );
    }

    // ── The redirect targets ───────────────────────────────────────────

    /**
     * Both URL helpers fall back to wp-admin when no page hosts the
     * dashboard shortcode. Without that guard `RecordLink::dashboardUrl()`
     * resolves to the *current* request, and a redirect to the current
     * request is a loop — which is why `Menu::redirectRetiredDashboard()`
     * carries the same guard.
     *
     * The test environment has no dashboard page, so this is the branch
     * under test here.
     */
    public function test_the_url_helpers_fall_back_to_wp_admin_without_a_dashboard_page(): void {
        $this->assertStringContainsString( 'page=tt-account', FrontendPlanView::url() );
        $this->assertStringContainsString( 'tab=plan', FrontendPlanView::url() );
        $this->assertStringContainsString( 'page=tt-account', FrontendTwoFactorView::url() );
        $this->assertStringContainsString( 'tab=mfa', FrontendTwoFactorView::url() );
    }

    public function test_the_url_helpers_carry_extra_arguments_through(): void {
        $this->assertStringContainsString(
            'tt_msg=cap_players',
            FrontendPlanView::url( [ 'tt_msg' => 'cap_players' ] )
        );
        $this->assertStringContainsString(
            'tt_msg=mfa_enrolled',
            FrontendTwoFactorView::url( [ 'tt_msg' => 'mfa_enrolled' ] )
        );
    }

    /**
     * Every one of the six redirect targets the issue enumerated now goes
     * through a helper rather than hard-coding `page=tt-account`, except
     * the three operator ones in `MfaActionHandlers`, which are supposed
     * to keep pointing at wp-admin.
     */
    public function test_the_self_service_redirects_route_through_the_helpers(): void {
        $this->assertStringContainsString(
            'FrontendTwoFactorView::url',
            $this->viewSource( 'src/Modules/Mfa/Wizards/BackupCodesStep.php' ),
            'The enrolment wizard hands off to the frontend surface.'
        );
        $this->assertStringContainsString(
            'FrontendPlanView::url',
            $this->viewSource( 'src/Modules/Players/Admin/PlayersPage.php' )
        );
        $this->assertStringContainsString(
            'FrontendPlanView::url',
            $this->viewSource( 'src/Modules/Teams/Admin/TeamsPage.php' )
        );
        $this->assertStringContainsString(
            'FrontendPlanView::url',
            $this->viewSource( 'src/Modules/Backup/Admin/BulkUndoNotice.php' )
        );
        $this->assertStringContainsString(
            'FrontendPlanView::url',
            $this->viewSource( 'src/Modules/License/UpgradePanel.php' ),
            "#3104's locked-feature panel is the link this port exists to fix."
        );
    }

    /**
     * The three operator redirects stay put. Counted rather than named:
     * `handleSavePersonas` has one and `handleOperatorDisable` has two
     * (the invalid-input path and the success path).
     */
    public function test_the_operator_redirects_still_point_at_wp_admin(): void {
        $src = $this->viewSource( 'src/Modules/Mfa/Admin/MfaActionHandlers.php' );
        $this->assertSame(
            4,
            substr_count( $src, "'page' => 'tt-account'" ),
            'Three operator redirects plus the self-service fallback.'
        );
    }
}
