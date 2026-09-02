<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\MobileDetector;
use TT\Shared\MobileSurfaceRegistry;
use TT\Shared\Frontend\Components\SavedViews;

/**
 * #2808 — the `read_only` mobile surface class.
 *
 * The class itself, its config entries and `isReadOnly()` shipped with
 * #2806 / #2807; nothing consumed them, so a surface classified
 * `read_only` behaved exactly like a `viewable` one. This covers the
 * enforcement half.
 *
 * The only mutating controls on the ten `read_only` surfaces are the saved
 * views strip's save / rename / overwrite / delete affordances — the
 * reports themselves carry no POST form and no `FormSaveButton`. So the
 * strip is where the class has to bite, and the apply links must survive
 * it: applying a saved view is a GET, and it is the reason to render the
 * strip on a phone at all.
 */
final class ReadOnlySurfaceTest extends WP_UnitTestCase {

    /** A `read_only` slug that also renders the saved-views strip. */
    private const READ_ONLY_SLUG = 'attendance-report-team';

    /** A slug in another class, for the negative case. */
    private const EDITABLE_SLUG = 'players';

    public function tear_down(): void {
        // The client hint takes precedence over the UA string in
        // MobileDetector::isPhone(), so it has to go too or a hint left by
        // another test decides these.
        unset(
            $_GET['force_mobile'],
            // #3296 — `withFiltersSet()` puts this on the request; left
            // behind it would decide another test's saved-view matching.
            $_GET['team_id'],
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['HTTP_SEC_CH_UA_MOBILE']
        );
        parent::tear_down();
    }

    // ── the classification itself ──────────────────────────────────────

    public function test_the_configured_read_only_surfaces_are_classified_read_only(): void {
        // The nine the issue named, plus player-bmi which #2895 added to the
        // block afterwards. If one of these silently changes class, a
        // mutating control comes back on a phone without anyone noticing.
        $expected = [
            'analytics',
            'attendance-report-player',
            'attendance-report-team',
            'minutes-report-team',
            'podium',
            'reports',
            'standard-report',
            'test-trends',
            'player-bmi',
            'usage-stats',
        ];

        foreach ( $expected as $slug ) {
            $this->assertTrue(
                MobileSurfaceRegistry::isReadOnly( $slug ),
                "{$slug} is no longer classified read_only"
            );
        }
    }

    public function test_an_unknown_slug_still_falls_back_to_viewable(): void {
        $this->assertFalse( MobileSurfaceRegistry::isReadOnly( 'no-such-surface-xyz' ) );
        $this->assertFalse( MobileSurfaceRegistry::isDesktopOnly( 'no-such-surface-xyz' ) );
    }

    public function test_read_only_is_not_desktop_only(): void {
        // The whole point of the fourth class: these surfaces render, where
        // a desktop_only one is replaced by the prompt page.
        $this->assertTrue( MobileSurfaceRegistry::isReadOnly( self::READ_ONLY_SLUG ) );
        $this->assertFalse( MobileSurfaceRegistry::isDesktopOnly( self::READ_ONLY_SLUG ) );
    }

    // ── the shared phone gate ──────────────────────────────────────────

    public function test_the_gate_does_not_apply_on_a_desktop_user_agent(): void {
        $this->asDesktop();
        $this->assertFalse( MobileDetector::phoneGateApplies() );
    }

    public function test_force_mobile_opts_out_of_the_gate(): void {
        $this->asPhone();
        $_GET['force_mobile'] = '1';

        // Same escape hatch the desktop_only prompt honours. A user who asked
        // for the full surface gets the full surface.
        $this->assertFalse( MobileDetector::phoneGateApplies() );
    }

    // ── enforcement on the saved-views strip ───────────────────────────

    public function test_a_phone_gets_no_save_control_on_a_read_only_surface(): void {
        $this->asPhone();
        $this->asCapableUser();

        $html = $this->renderStrip( self::READ_ONLY_SLUG );

        $this->assertStringNotContainsString( 'data-tt-view-save-toggle', $html );
        $this->assertStringNotContainsString( 'data-tt-view-save-confirm', $html );
        $this->assertStringNotContainsString( 'data-tt-view-manage', $html );
    }

    public function test_a_desktop_keeps_the_save_control_on_the_same_surface(): void {
        $this->asDesktop();
        $this->asCapableUser();
        $this->withFiltersSet();

        $html = $this->renderStrip( self::READ_ONLY_SLUG );

        // read_only is a phone rule, not a surface rule. At a desk the
        // surface is fully editable.
        $this->assertStringContainsString( 'data-tt-view-save-toggle', $html );
    }

    public function test_a_phone_keeps_the_save_control_on_an_editable_surface(): void {
        $this->asPhone();
        $this->asCapableUser();
        $this->withFiltersSet();

        $html = $this->renderStrip( self::EDITABLE_SLUG );

        $this->assertStringContainsString( 'data-tt-view-save-toggle', $html );
    }

    public function test_the_save_script_is_not_enqueued_on_a_read_only_phone_render(): void {
        $this->asPhone();
        $this->asCapableUser();
        $this->withFiltersSet();

        // wp_scripts survives between tests in one process, and the desktop
        // cases above enqueue this. Start from nothing so the assertion is
        // about this render rather than about test order.
        wp_dequeue_script( 'tt-saved-views' );
        wp_deregister_script( 'tt-saved-views' );

        $this->renderStrip( self::READ_ONLY_SLUG );

        // Nothing for it to bind to, on the device with the tightest budget.
        $this->assertFalse( wp_script_is( 'tt-saved-views', 'enqueued' ) );

        // #3296 — the stylesheet assertion that used to sit here is gone with
        // `frontend-saved-views.css`. The chips and the trigger are part of
        // the filter bar now, so the bar's own sheet styles them and there is
        // no separate handle to check.
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /**
     * #3296 — put a filter on the request.
     *
     * The save affordance used to render unconditionally. It is now behind
     * the bookmark icon, which is absent when there is nothing to do with it:
     * no filters set AND no saved views. These tests are about the mobile
     * class, not about that gate, so they give the render something to save —
     * otherwise the positive assertions would fail for a reason that has
     * nothing to do with `read_only`.
     */
    private function withFiltersSet(): void {
        $_GET['team_id'] = '2';
    }

    private function renderStrip( string $slug ): string {
        return SavedViews::html(
            'attendance_team',
            [ 'period', 'team_id' ],
            home_url( '/' ),
            [ 'tt_view' => $slug ]
        );
    }

    /**
     * The strip is capability-gated per surface key — `attendance_team`
     * resolves to `tt_view_analytics` in `SavedViewsRegistry`, and the
     * component fails closed without it. Granting it explicitly keeps these
     * tests about the mobile class rather than about the matrix: without
     * it every render would return an empty string and the negative
     * assertions would pass for the wrong reason.
     */
    private function asCapableUser(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $user    = new \WP_User( $user_id );
        $user->add_cap( 'tt_view_analytics' );
        $user->add_cap( 'tt_view_players' );
        wp_set_current_user( $user_id );
    }

    private function asPhone(): void {
        $_SERVER['HTTP_USER_AGENT'] =
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 '
            . '(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    }

    private function asDesktop(): void {
        $_SERVER['HTTP_USER_AGENT'] =
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    }
}
