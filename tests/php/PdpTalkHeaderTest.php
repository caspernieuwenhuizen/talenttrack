<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Frontend\FrontendPdpManageView;
use TT\Shared\Frontend\ShellPreference;

/**
 * #5079 (epic #5078) — the PDP talk names its player and the talk.
 *
 * The spine carries the player's name, initials, team, shirt number,
 * position and the talk line, once; the tab reads "Background"; the
 * breadcrumb chain ends in the player's name, then the talk.
 */
final class PdpTalkHeaderTest extends WP_UnitTestCase {

    private int $coach = 0;
    private int $file  = 0;
    private int $conv  = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        $this->coach = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->coach );
        delete_user_meta( $this->coach, ShellPreference::USER_META_KEY );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_seasons", [
            'name' => '2026/27', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_current' => 1,
        ] );
        $season = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Header O15-1' ] );
        $team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $team, 'first_name' => 'Mila', 'last_name' => 'Kopregel',
            'status' => 'active', 'jersey_number' => 7, 'preferred_positions' => '["CM","LW"]',
        ] );
        $player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_pdp_files", [
            'club_id' => $club, 'player_id' => $player, 'season_id' => $season,
            'owner_coach_id' => $this->coach, 'status' => 'open', 'cycle_size' => 3,
        ] );
        $this->file = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_pdp_conversations", [
            'club_id'      => $club,
            'pdp_file_id'  => $this->file,
            'sequence'     => 1,
            'template_key' => 'start',
            'scheduled_at' => '2026-09-15 18:30:00',
            'planning_window_start' => '2026-08-01',
            'planning_window_end'   => '2027-06-30',
        ] );
        $this->conv = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        unset( $_GET['id'], $_GET['conv'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_spine_names_the_player_and_the_talk_once(): void {
        ShellPreference::setClubDefault( ShellPreference::APP );
        $html = $this->page();

        $this->assertSame( 1, substr_count( $html, 'class="tt-spine__name"' ), 'one identity strip' );
        $this->assertStringContainsString( '<span class="tt-spine__name">Mila Kopregel</span>', $html );
        $this->assertStringContainsString( '>MK</span>', $html, 'initials without a photo' );
        $meta = 'Header O15-1 · #7 · ' . LabelTranslator::positionLabel( 'CM' );
        $this->assertStringContainsString( '<span class="tt-spine__meta">' . esc_html( $meta ) . '</span>', $html, 'team, shirt number and the first position' );
        $this->assertStringContainsString( 'Start of season · conversation 1 of 3 · planned', $html, 'the talk line' );
        $this->assertSame( 1, substr_count( $html, 'class="tt-spine__detail"' ) );

        $this->assertStringNotContainsString( 'Conversation 1 (Start of season)</h1>', $html, 'the old title is gone' );
        $this->assertStringContainsString( 'tt-pdp-conv-title tt-screen-reader-text">Mila Kopregel</h1>', $html, 'the heading stays for assistive tech' );
    }

    public function test_the_tab_reads_background(): void {
        ShellPreference::setClubDefault( ShellPreference::APP );
        $html = $this->page();

        $this->assertStringContainsString( 'aria-controls="tt-pdp-pane-evidence"', $html );
        $this->assertStringContainsString( '>Background</button>', $html );
        $this->assertStringNotContainsString( '>Evidence</button>', $html );
        $this->assertStringContainsString( 'What is on record about the player this season, to support the talk.', $html );
    }

    public function test_the_chain_ends_in_the_player_then_the_talk(): void {
        ShellPreference::setClubDefault( ShellPreference::APP );
        $html  = $this->page();
        $crumb = strpos( $html, 'Mila Kopregel' );
        $talk  = strpos( $html, 'Conversation 1 (Start of season)' );

        $this->assertNotFalse( $crumb );
        $this->assertNotFalse( $talk );
        $this->assertLessThan( $talk, $crumb, 'the player comes before the talk' );
        $this->assertStringNotContainsString( 'PDP file detail', $html );
    }

    public function test_under_classic_the_heading_is_the_name_on_the_page(): void {
        ShellPreference::setClubDefault( ShellPreference::CLASSIC );
        $html = $this->page();

        $this->assertStringNotContainsString( 'tt-spine__id', $html, 'the identity strip is app-only' );
        $this->assertStringContainsString( '<h1 class="tt-fview-title tt-pdp-conv-title">Mila Kopregel</h1>', $html );
        $this->assertStringContainsString( '<p class="tt-pdp-conv-talkline">Start of season · conversation 1 of 3 · planned', $html );
        $this->assertStringContainsString( 'role="tablist"', $html, 'the tabs still render' );
    }

    public function test_a_held_talk_says_when(): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_pdp_conversations", [ 'conducted_at' => '2026-09-16 18:30:00' ], [ 'id' => $this->conv ] );
        ShellPreference::setClubDefault( ShellPreference::APP );

        $this->assertStringContainsString( 'Start of season · conversation 1 of 3 · held', $this->page() );
    }

    private function page(): string {
        $_GET['id']   = (string) $this->file;
        $_GET['conv'] = (string) $this->conv;
        ob_start();
        FrontendPdpManageView::render( $this->coach, true );
        return (string) ob_get_clean();
    }
}
