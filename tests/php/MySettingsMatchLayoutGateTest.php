<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\MatchExecution\MatchExecutionLayout;
use TT\Shared\Frontend\FrontendMySettingsView;

/**
 * #3388 — the Live match screen card rendered for every persona.
 *
 * A player was offered a choice between two layouts for a surface
 * `FrontendMatchExecutionView::render()` refuses them. The setting was
 * inert, and it was one of five cards on a page that should hold two or
 * three: *"as a player, there are too many settings available under my
 * settings"*.
 *
 * `CrossViewLink`'s permissive fallback could never have caught this —
 * `match-execution` registers no tile, so `entityForViewSlug()` returns
 * null and `fallbackAllows()` returns true for everyone. The gate had to
 * be explicit, and these tests are what say so.
 */
final class MySettingsMatchLayoutGateTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
    }

    public function test_a_player_is_not_offered_the_card(): void {
        $html = $this->renderFor( $this->userWith( 'tt_player' ) );

        $this->assertStringNotContainsString( 'update_match_layout', $html );
        $this->assertStringNotContainsString( 'tt-ms-match-layout', $html );
    }

    public function test_a_parent_is_not_offered_the_card(): void {
        $html = $this->renderFor( $this->userWith( 'tt_parent' ) );

        $this->assertStringNotContainsString( 'update_match_layout', $html );
    }

    public function test_a_coach_still_gets_the_card(): void {
        // The direction that must not break: #2934 added this for a coach
        // who has run twenty matches on one layout.
        $html = $this->renderFor( $this->userWith( 'administrator' ) );

        $this->assertStringContainsString( 'update_match_layout', $html );
        $this->assertStringContainsString( 'tt-ms-match-layout', $html );
    }

    public function test_the_page_still_renders_its_other_cards_for_a_player(): void {
        // A gate that removed the whole page would pass the assertions
        // above for the wrong reason.
        $html = $this->renderFor( $this->userWith( 'tt_player' ) );

        $this->assertStringContainsString( 'update_profile', $html );
        $this->assertStringContainsString( 'change_password', $html );
    }

    public function test_posting_the_layout_as_a_player_is_refused(): void {
        // The card being hidden is a UI decision; this is what makes it a
        // rule. Without the server-side half the preference is still
        // writable by posting the form directly.
        $user = $this->userWith( 'tt_player' );
        wp_set_current_user( $user );

        $_POST = [
            'tt_my_settings_action'                  => 'update_match_layout',
            'tt_my_settings_match_layout_nonce'      => wp_create_nonce( 'tt_my_settings_match_layout' ),
            'tt_match_layout'                        => MatchExecutionLayout::SECTIONS,
        ];
        $html = $this->renderFor( $user );
        $_POST = [];

        $this->assertSame(
            MatchExecutionLayout::INHERIT,
            MatchExecutionLayout::userOverride( $user ),
            'a forged post must not write a preference for a screen the user cannot open'
        );
        $this->assertStringContainsString( 'not available to you', $html );
    }

    public function test_a_coach_can_still_save_the_layout(): void {
        $user = $this->userWith( 'administrator' );
        wp_set_current_user( $user );

        $_POST = [
            'tt_my_settings_action'             => 'update_match_layout',
            'tt_my_settings_match_layout_nonce' => wp_create_nonce( 'tt_my_settings_match_layout' ),
            'tt_match_layout'                   => MatchExecutionLayout::SECTIONS,
        ];
        $this->renderFor( $user );
        $_POST = [];

        $this->assertSame( MatchExecutionLayout::SECTIONS, MatchExecutionLayout::userOverride( $user ) );
    }

    /* ---- helpers ------------------------------------------------------ */

    private function userWith( string $role ): int {
        return (int) self::factory()->user->create( [ 'role' => $role ] );
    }

    private function renderFor( int $user_id ): string {
        wp_set_current_user( $user_id );
        ob_start();
        FrontendMySettingsView::render();
        return (string) ob_get_clean();
    }
}
