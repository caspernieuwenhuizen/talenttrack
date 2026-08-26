<?php
/**
 * Focus surfaces — which views own the thumb zone (#2933).
 *
 * Two things are worth pinning. The first is that the list is honoured at
 * all, and only for the slugs on it. The second is the failure direction:
 * a missing or broken config file must leave the bar rendering everywhere,
 * because a wrongly-rendered bar is a cramped screen while a wrongly
 * suppressed one is a user with no navigation.
 */

use TT\Shared\Frontend\Components\FrontendAppBottomBar;
use TT\Shared\Frontend\FocusSurfaces;
use TT\Shared\Frontend\ShellPreference;

class FocusSurfacesTest extends WP_UnitTestCase {

    private int $user_id = 0;

    public function set_up(): void {
        parent::set_up();
        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user_id );
        ShellPreference::setClubDefault( ShellPreference::APP );
        FocusSurfaces::flush();
    }

    public function tear_down(): void {
        FocusSurfaces::flush();
        parent::tear_down();
    }

    private function renderBar( string $view ): string {
        ob_start();
        FrontendAppBottomBar::render( $this->user_id, $view, home_url( '/' ) );
        return (string) ob_get_clean();
    }

    public function test_the_shipped_surfaces_claim_the_thumb_zone(): void {
        $this->assertTrue( FocusSurfaces::claims( 'match-execution' ) );
        $this->assertTrue( FocusSurfaces::claims( 'training-run' ) );
    }

    public function test_every_other_surface_does_not(): void {
        $this->assertFalse( FocusSurfaces::claims( 'players' ) );
        $this->assertFalse( FocusSurfaces::claims( 'activities' ) );
        $this->assertFalse( FocusSurfaces::claims( 'dashboard' ) );
    }

    public function test_an_empty_or_unknown_slug_is_not_a_claim(): void {
        $this->assertFalse( FocusSurfaces::claims( '' ) );
        $this->assertFalse( FocusSurfaces::claims( '   ' ) );
        $this->assertFalse( FocusSurfaces::claims( 'no-such-view' ) );
    }

    public function test_every_entry_carries_a_reason(): void {
        // The point of the file is that "this one is different" was a
        // decision somebody wrote down. An entry with no sentence is an
        // entry nobody can review.
        foreach ( FocusSurfaces::map() as $slug => $reason ) {
            $this->assertNotSame( '', trim( $reason ), "focus surface '{$slug}' has no reason" );
        }
    }

    public function test_the_bar_is_suppressed_on_a_focus_surface(): void {
        $this->assertSame( '', $this->renderBar( 'match-execution' ) );
        $this->assertSame( '', $this->renderBar( 'training-run' ) );
    }

    public function test_the_bar_still_renders_everywhere_else(): void {
        $html = $this->renderBar( 'players' );

        $this->assertStringContainsString( 'tt-shell-bar', $html );
    }

    public function test_the_hub_still_renders_the_bar(): void {
        // The tile hub passes an empty active view; an empty slug must not
        // read as a claim, or the landing screen loses its navigation.
        $this->assertStringContainsString( 'tt-shell-bar', $this->renderBar( '' ) );
    }

    public function test_suppression_removes_the_markup_not_just_the_pixels(): void {
        // Hiding it in CSS would leave the links in the keyboard tab order
        // behind the view's own controls.
        $this->assertStringNotContainsString( '<nav', $this->renderBar( 'match-execution' ) );
        $this->assertStringNotContainsString( '<a ', $this->renderBar( 'match-execution' ) );
    }

    public function test_a_focus_surface_still_reports_why(): void {
        $this->assertNotSame( '', FocusSurfaces::reason( 'match-execution' ) );
        $this->assertSame( '', FocusSurfaces::reason( 'players' ) );
    }
}
