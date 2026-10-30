<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Search\ParentSearchService;
use TT\Infrastructure\Security\RolesService;
use TT\Shared\Frontend\FrontendParentSearchView;
use TT\Shared\Frontend\ShellPreference;

/**
 * #4252 — `?tt_view=search` is the parent search. A team manager who
 * opened it by URL was told "No child is linked to your account yet … Ask
 * the academy to link you", a sentence for a parent. Staff are now pointed
 * at the search that covers players; a parent without a linked child still
 * gets the notice; the parent search itself is untouched.
 */
final class ParentSearchStaffPointerTest extends WP_UnitTestCase {

    private const PARENT_NOTICE = 'Ask the academy to link you.';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
    }

    public function tear_down(): void {
        unset( $_GET['q'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_staff_in_the_app_shell_are_pointed_at_the_header_search(): void {
        $staff = $this->staff();
        ShellPreference::setUserOverride( $staff, ShellPreference::APP );
        $_GET['q'] = 'Dave';

        $html = $this->render();

        $this->assertStringNotContainsString( self::PARENT_NOTICE, $html );
        $this->assertStringNotContainsString( 'Nothing found.', $html );
        $this->assertStringContainsString( 'use the search at the top', $html );
        $this->assertMatchesRegularExpression( '/<button type="button"[^>]*data-tt-spotlight-focus/', $html );
        $this->assertStringNotContainsString( 'tt-parent-search__form', $html, 'staff get no parent search form' );
    }

    public function test_staff_in_the_classic_shell_get_a_link_to_the_players_list(): void {
        $staff = $this->staff();
        ShellPreference::setUserOverride( $staff, ShellPreference::CLASSIC );

        $html = $this->render();

        $this->assertStringNotContainsString( self::PARENT_NOTICE, $html );
        $this->assertStringContainsString( 'open the players list', $html );
        $this->assertMatchesRegularExpression( '/<a class="[^"]*tt-parent-search__pointer"[^>]*href="[^"]*tt_view=players/', $html );
        $this->assertStringNotContainsString( 'data-tt-spotlight-focus', $html );
    }

    public function test_a_parent_without_a_linked_child_keeps_the_notice(): void {
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] ) );
        $_GET['q'] = 'Dave';

        $html = $this->render();

        $this->assertStringContainsString( self::PARENT_NOTICE, $html );
        $this->assertStringContainsString( 'Nothing found.', $html, 'the identical-miss output is unchanged' );
        $this->assertStringNotContainsString( 'tt-parent-search__staff', $html );
    }

    public function test_the_parent_decision_lives_in_the_service(): void {
        $this->assertFalse( ParentSearchService::isParentCaller( $this->staff() ) );
        $this->assertTrue( ParentSearchService::isParentCaller( (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] ) ) );
        $this->assertFalse( ParentSearchService::isParentCaller( 0 ) );
    }

    private function staff(): int {
        $id = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $id );
        return $id;
    }

    private function render(): string {
        ob_start();
        FrontendParentSearchView::render();
        return (string) ob_get_clean();
    }
}
