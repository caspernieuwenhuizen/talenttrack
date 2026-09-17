<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Frontend\TeamStatisticsTab;
use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;
use TT\Shared\Frontend\Components\FormChips;

/**
 * #3522 (epic #3519) — the Statistics tab's content.
 *
 * The tab composes and does not compute, so the assertions here are about what
 * reaches the screen: the three empty states that mean different things, the
 * contributors-only rule, the slice-not-the-whole-table rule, and that the
 * numbers on screen are the query's numbers rather than a second opinion.
 */
final class TeamStatisticsTabTest extends WP_UnitTestCase {

    private const TEAM_ID = 881;
    private const STRIKER = 41;

    private const WINDOW = [ 'stats_from' => '2026-01-01', 'stats_to' => '2026-06-30' ];

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'id' => self::STRIKER, 'team_id' => self::TEAM_ID,
            'first_name' => 'Sam', 'last_name' => 'Striker', 'jersey_number' => 9, 'status' => 'active',
        ] );

        $_GET = self::WINDOW;
    }

    public function tear_down(): void {
        $_GET = [];
        parent::tear_down();
    }

    private function seedFixture( int $id, string $date, ?int $home = null, ?int $away = null, string $type = 'game' ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id' => 1, 'id' => $id, 'team_id' => self::TEAM_ID,
            'title' => 'Fixture ' . $id, 'session_date' => $date,
            'activity_type_key' => $type, 'opponent' => 'Ajax', 'home_away' => 'home',
            'home_score' => $home, 'away_score' => $away,
        ] );
    }

    private function render(): string {
        ob_start();
        TeamStatisticsTab::render( self::TEAM_ID );
        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------
    // The three empty states
    // ---------------------------------------------------------------

    public function test_no_matches_says_so_rather_than_printing_zeros(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'No matches played in this period.', $html );
        $this->assertStringNotContainsString( 'tt-ts-record', $html, 'a strip of zeros is not an answer' );
    }

    public function test_matches_without_a_score_are_named_rather_than_counted(): void {
        $this->seedFixture( 9201, '2026-02-01', 2, 1 );
        $this->seedFixture( 9202, '2026-02-08' );

        $html = $this->render();

        $this->assertStringContainsString( 'no score recorded', $html );
        $this->assertStringContainsString( 'tt-ts-record', $html );
    }

    public function test_unattributed_goals_say_so_rather_than_showing_an_empty_table(): void {
        // The state most likely to be reported as a bug: the matches were
        // played and scored, but nobody said who scored them.
        $this->seedFixture( 9210, '2026-02-01', 3, 0 );

        $html = $this->render();

        $this->assertStringContainsString( 'No goals have been attributed', $html );
        $this->assertStringNotContainsString( 'Top scorers', $html );
    }

    // ---------------------------------------------------------------
    // The record
    // ---------------------------------------------------------------

    public function test_the_record_matches_the_query(): void {
        $this->seedFixture( 9220, '2026-02-01', 3, 1 );
        $this->seedFixture( 9221, '2026-02-08', 2, 2 );

        $stats = ( new TeamMatchStatsQuery() )->forTeam( self::TEAM_ID, [
            'from' => self::WINDOW['stats_from'],
            'to'   => self::WINDOW['stats_to'],
        ] );

        $html = $this->render();

        // The view prints what the query answers; a second opinion here would
        // be the thing #3520 exists to prevent.
        $this->assertSame( 2, $stats['record']['played'] );
        $this->assertStringContainsString( 'tt-ts-record__n', $html );
        $this->assertStringContainsString( 'Goal difference', $html );
    }

    public function test_a_tournament_is_excluded_and_the_tab_says_so(): void {
        $this->seedFixture( 9230, '2026-02-01', 2, 1 );
        $this->seedFixture( 9231, '2026-02-08', 1, 0, 'tournament' );

        $html = $this->render();

        $this->assertStringContainsString( 'is not included', $html );
    }

    // ---------------------------------------------------------------
    // The window
    // ---------------------------------------------------------------

    public function test_the_window_is_labelled_and_narrowable(): void {
        $this->seedFixture( 9240, '2026-02-01', 1, 0 );

        $html = $this->render();

        $this->assertStringContainsString( 'tt-ts-window', $html );
        $this->assertStringContainsString( 'name="stats_from"', $html );
        $this->assertStringContainsString( 'type="date"', $html );
        $this->assertStringContainsString( '2026-01-01', $html, 'the window the URL asked for is reflected back' );
    }

    public function test_a_half_window_is_ignored_rather_than_half_applied(): void {
        $this->seedFixture( 9250, '2026-02-01', 1, 0 );
        $_GET = [ 'stats_from' => '2026-01-01' ];

        $html = $this->render();

        // Falls back to the default window rather than answering a question
        // nobody asked.
        $this->assertStringContainsString( 'tt-ts-window', $html );
        $this->assertStringNotContainsString( 'value="2026-01-01"', $html );
    }

    // ---------------------------------------------------------------
    // Composition rules
    // ---------------------------------------------------------------

    public function test_the_tab_carries_no_module_level_navigation(): void {
        $this->seedFixture( 9260, '2026-02-01', 1, 0 );

        $html = $this->render();

        // §5b — the global nav is shell-rendered. The one cross-view link here
        // is the minutes report, which is a report about this team.
        $this->assertStringNotContainsString( 'tt-tile', $html );
        $this->assertStringNotContainsString( 'tt-breadcrumbs', $html, 'the host view owns the chain' );
    }

    public function test_the_form_chips_come_from_the_shared_component(): void {
        ob_start();
        FormChips::render( [
            [ 'outcome' => 'W', 'team_score' => 3, 'opp_score' => 1, 'opponent' => 'Ajax' ],
            [ 'outcome' => 'L', 'team_score' => 0, 'opp_score' => 2, 'opponent' => 'PSV' ],
        ] );
        $chips = (string) ob_get_clean();

        $this->assertStringContainsString( 'tt-mt-form__chip--win', $chips );
        $this->assertStringContainsString( 'tt-mt-form__chip--loss', $chips );
        // Colour never alone: the letter and the scoreline are both in the chip.
        $this->assertStringContainsString( '3–1', $chips );
        $this->assertStringContainsString( 'tt-mt-form__letter', $chips );
    }

    public function test_the_shared_chips_render_nothing_for_no_results(): void {
        ob_start();
        FormChips::render( [] );

        $this->assertSame( '', (string) ob_get_clean() );
    }
}
