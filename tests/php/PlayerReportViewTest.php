<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\FrontendStandardReportsView;

/**
 * #3873 (epic #3871) — the player report on screen.
 *
 * Pinned: it opens on the conversation set, a deselected section is absent
 * from the page, a player with no development plan file still gets the whole
 * report, the chain is rendered when the report is refused, the report is
 * refused when switched off, and no player picked is a picker rather than a
 * blank page.
 *
 * The caller is a `tt_club_admin` (the `academy_admin` persona), which holds
 * `reports`, `players` and `pdp_file` at global scope in the seed; see
 * `PlayerReportTest` for why a WordPress administrator would not do.
 */
final class PlayerReportViewTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] ) );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'View U13', 'age_group' => 'U13' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $this->team,
            'first_name' => 'Screen', 'last_name' => 'Player', 'status' => 'active', 'wp_user_id' => null,
        ] );
        $this->player = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        $_GET = [];
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_it_opens_on_the_conversation_set(): void {
        $html = $this->renderReport( [ 'from' => '2020-03-01', 'to' => '2020-03-31' ] );

        $this->assertStringContainsString( 'Screen Player', $html );
        $this->assertStringContainsString( 'data-tt-player-report', $html );
        $this->assertStringContainsString( 'tt-mr-lines', $html, 'the notes area is in the conversation set' );
        $this->assertStringContainsString( 'value="talking_points" checked', $html );
        $this->assertStringNotContainsString( 'value="tests" checked', $html, 'tests are one tick away, not on by default' );
    }

    public function test_a_deselected_section_is_absent_from_the_page(): void {
        $html = $this->renderReport( [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'blocks' => 'attendance' ] );

        $this->assertStringContainsString( 'No training or matches recorded in this window.', $html );
        $this->assertStringNotContainsString( 'tt-mr-lines', $html );
    }

    /** #3962 — the panel lists the sections in the report's order, and each can move without dragging. */
    public function test_the_sections_stand_in_the_chosen_order_and_can_move(): void {
        $html = $this->renderReport( [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'blocks' => 'tests,ratings' ] );

        $tests   = strpos( $html, 'data-tt-pr-row="tests"' );
        $ratings = strpos( $html, 'data-tt-pr-row="ratings"' );
        $this->assertNotFalse( $tests );
        $this->assertNotFalse( $ratings );
        $this->assertLessThan( $ratings, $tests, 'tests were put first, so the panel lists them first' );

        // Tests is first of the chosen: it can only move down, and the link
        // carries the order that move makes.
        $this->assertSame( 1, preg_match( '/href="([^"]*)"[^>]*data-tt-pr-move="down"/', $html, $m ) );
        $this->assertStringContainsString( 'blocks=letterhead,ratings,tests', html_entity_decode( $m[1] ) );
        $this->assertStringContainsString( 'Move Tests down', $html );

        // The printed order follows the screen: tests before evaluations.
        $this->assertLessThan(
            (int) strpos( $html, '<h2 class="tt-rep-section__title">Evaluations' ),
            (int) strpos( $html, '<h2 class="tt-rep-section__title">Tests' )
        );
    }

    public function test_a_player_without_a_development_plan_still_gets_the_report(): void {
        $html = $this->renderReport( [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'blocks' => 'pdp' ] );

        $this->assertStringContainsString( 'This player has no development plan file yet.', $html );
    }

    public function test_no_player_picked_is_a_picker(): void {
        $_GET = [ 'tt_view' => 'standard-report', 'slug' => 'player-report' ];
        ob_start();
        FrontendStandardReportsView::render( get_current_user_id(), false );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'tt-rep-picker', $html );
        $this->assertStringContainsString( 'player_id=' . $this->player, $html );
    }

    public function test_a_player_outside_the_club_is_refused_without_the_report(): void {
        global $wpdb;
        $this->assertStringContainsString( 'data-tt-player-report', $this->renderReport( [] ), 'precondition: the player is readable' );

        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'club_id' => CurrentClub::id() + 1 ], [ 'id' => $this->player ] );
        $html = $this->renderReport( [] );

        $this->assertStringNotContainsString( 'data-tt-player-report', $html );
        $this->assertStringContainsString( 'You do not have access to a report on this player.', $html );
    }

    public function test_the_report_is_refused_when_switched_off(): void {
        \TT\Core\FeatureRegistry::setEnabled( 'report_player_report', false );
        $this->assertFalse( \TT\Core\FeatureRegistry::isEnabled( 'report_player_report' ), 'Precondition: the toggle took.' );

        $html = $this->renderReport( [] );
        \TT\Core\FeatureRegistry::setEnabled( 'report_player_report', true );

        $this->assertStringNotContainsString( 'data-tt-player-report', $html, 'A switched-off report must not render, even from a direct link.' );
    }

    /** @param array<string,string> $get */
    private function renderReport( array $get ): string {
        $_GET = array_merge( [ 'tt_view' => 'standard-report', 'slug' => 'player-report', 'player_id' => (string) $this->player ], $get );
        ob_start();
        FrontendStandardReportsView::render( get_current_user_id(), false );
        return (string) ob_get_clean();
    }
}
