<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Shared\Frontend\FrontendStandardReportsView;

/**
 * #3459 (epic #3457) — the team monthly report on screen.
 *
 * Pinned: `last_month` in the shared period vocabulary; the fit estimate the
 * panel and the PDF share (pack page count, one-pager degradation order); and
 * the rendered page — a deselected section absent from the output, the report
 * refused when its toggle is off, and the empty state for a quiet team.
 */
final class TeamMonthlyReportViewTest extends WP_UnitTestCase {

    private int $team_id = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'View U15', 'age_group' => 'U15' ] );
        $this->team_id = (int) $wpdb->insert_id;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        $_GET = [];
        parent::tear_down();
    }

    public function test_last_month_is_in_the_shared_vocabulary(): void {
        $this->assertContains( 'last_month', ReportFilters::PERIODS );
        $this->assertArrayHasKey( 'last_month', ReportFilters::periodLabels() );
        $this->assertSame( [ 'from' => '2020-02-01', 'to' => '2020-02-29' ], ReportFilters::periodWindow( 'last_month', '2020-03-31' ) );
    }

    /** @param array<string,array<string,mixed>> $data */
    private function report( array $data ): array {
        return [ 'data' => $data ];
    }

    /** @return list<array{player_id:int,name:string}> */
    private function rows( int $n ): array {
        $out = [];
        for ( $i = 1; $i <= $n; $i++ ) $out[] = [ 'player_id' => $i, 'name' => 'P' . $i ];
        return $out;
    }

    public function test_the_pack_drops_the_roster_page_when_the_roster_is_not_selected(): void {
        $with    = TeamMonthlyReportLayout::fit( $this->report( [ 'letterhead' => [], 'kpi' => [], 'roster' => [ 'rows' => $this->rows( 14 ) ], 'notes' => [] ] ), 'B' );
        $without = TeamMonthlyReportLayout::fit( $this->report( [ 'letterhead' => [], 'kpi' => [], 'notes' => [] ] ), 'B' );

        $this->assertSame( 3, $with['pages'] );
        $this->assertSame( 2, $without['pages'] );
    }

    /** The one-pager elides ranked lists first, trims the agenda second, and only then fails. */
    public function test_the_one_pager_degrades_in_the_decided_order(): void {
        $moderate = TeamMonthlyReportLayout::fit( $this->report( [
            'letterhead' => [], 'coverage' => [], 'kpi' => [], 'status' => [],
            'attendance' => [ 'rows' => $this->rows( 20 ) ],
            'minutes'    => [ 'rows' => $this->rows( 20 ) ],
            'attention'  => [ 'items' => $this->rows( 2 ) ],
        ] ), 'A' );
        $this->assertTrue( $moderate['fits'] );
        $this->assertSame( [ TeamMonthlyReportLayout::ELIDE_RANKED ], $moderate['degraded'] );

        $huge = TeamMonthlyReportLayout::fit( $this->report( [
            'letterhead' => [], 'coverage' => [], 'kpi' => [], 'status' => [],
            'attendance' => [ 'rows' => $this->rows( 30 ) ],
            'minutes'    => [ 'rows' => $this->rows( 30 ) ],
            'attention'  => [ 'items' => $this->rows( 12 ) ],
            'changes'    => [ 'events' => $this->rows( 20 ) ],
            'roster'     => [ 'rows' => $this->rows( 30 ) ],
            'notes'      => [],
        ] ), 'A' );
        $this->assertFalse( $huge['fits'] );
        $this->assertSame( [ TeamMonthlyReportLayout::ELIDE_RANKED, TeamMonthlyReportLayout::TRIM_ATTENTION ], $huge['degraded'] );
    }

    private function renderReport( array $get ): string {
        $_GET = array_merge( [ 'tt_view' => 'standard-report', 'slug' => 'team-monthly', 'team_id' => (string) $this->team_id ], $get );
        ob_start();
        FrontendStandardReportsView::render( get_current_user_id(), true );
        return (string) ob_get_clean();
    }

    public function test_a_quiet_team_gets_the_letterhead_and_one_sentence(): void {
        $html = $this->renderReport( [ 'from' => '2020-03-01', 'to' => '2020-03-31' ] );

        // #4097 — the screen shows the printed document: its letterhead and
        // its one sentence, on one sheet.
        $this->assertStringContainsString( 'class="tt-d-lh"', $html );
        $this->assertStringContainsString( 'class="tt-d-empty"', $html );
        $this->assertStringNotContainsString( 'class="tt-d-kpi"', $html, 'No page of zeroes.' );
        $this->assertSame( 1, substr_count( $html, '<article class="tt-mr-sheet"' ) );
    }

    public function test_a_deselected_section_is_absent_from_the_page(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => 1, 'team_id' => $this->team_id, 'title' => 'Tuesday', 'session_date' => '2020-03-03',
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );

        $html = $this->renderReport( [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'blocks' => 'kpi' ] );

        $this->assertStringContainsString( 'class="tt-d-kpi"', $html );
        $this->assertStringNotContainsString( 'class="tt-d-cov"', $html );
        $this->assertStringNotContainsString( 'class="tt-d-tbl"', $html );
    }

    /**
     * #4096 — the screen numbers its sections as the PDF of the same
     * composition does: following the ticked sections, in print order.
     */
    public function test_sections_on_screen_carry_the_numbers_the_pdf_prints(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => 1, 'team_id' => $this->team_id, 'title' => 'Tuesday', 'session_date' => '2020-03-03',
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );

        $html = $this->renderReport( [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'layout' => 'B', 'blocks' => 'kpi,attendance,roster,quality' ] );

        $this->assertStringContainsString( '<td class="tt-mr-sh__no">01</td><td class="tt-mr-sh__t"><h2>Attendance</h2>', $html );
        $this->assertStringContainsString( '<td class="tt-mr-sh__no">02</td><td class="tt-mr-sh__t"><h2>Player by player</h2>', $html );
        $this->assertStringContainsString( '<td class="tt-mr-sh__no">03</td><td class="tt-mr-sh__t"><h2>Data quality</h2>', $html );
    }

    /**
     * #4035 — the attendance section's subtitle names the order its rows are
     * actually in. It read "Lowest first" while the rows had been in shirt
     * order since #3518, so a coach reading the top rows as the players who
     * miss sessions was reading shirt numbers.
     */
    public function test_the_attendance_section_names_the_order_its_rows_are_in(): void {
        global $wpdb;

        // Shirt order 7 / 9 / 11; attendance order is the reverse of it.
        $players = [];
        foreach ( [ [ 7, 'Zeven', 3 ], [ 9, 'Negen', 2 ], [ 11, 'Elf', 1 ] ] as [ $jersey, $last, $present ] ) {
            $wpdb->insert( "{$wpdb->prefix}tt_players", [
                'club_id'       => 1,
                'team_id'       => $this->team_id,
                'first_name'    => 'Speler',
                'last_name'     => $last,
                'jersey_number' => $jersey,
                'status'        => 'active',
            ] );
            $players[] = [ 'id' => (int) $wpdb->insert_id, 'last' => $last, 'present' => $present ];
        }

        $activities = [];
        foreach ( [ '2020-03-03', '2020-03-10', '2020-03-17' ] as $date ) {
            $wpdb->insert( "{$wpdb->prefix}tt_activities", [
                'club_id' => 1, 'team_id' => $this->team_id, 'title' => 'Training ' . $date,
                'session_date' => $date, 'activity_type_key' => 'training',
                'activity_status_key' => 'completed', 'plan_state' => 'completed',
            ] );
            $activities[] = (int) $wpdb->insert_id;
        }
        foreach ( $players as $player ) {
            foreach ( $activities as $i => $activity_id ) {
                $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
                    'club_id'     => 1,
                    'activity_id' => $activity_id,
                    'player_id'   => $player['id'],
                    'status'      => $i < $player['present'] ? 'present' : 'absent',
                    'is_guest'    => 0,
                    'record_type' => 'actual',
                ] );
            }
        }

        $html = $this->renderReport( [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'blocks' => 'attendance' ] );

        $this->assertStringNotContainsString( 'Lowest first', $html, 'the subtitle must not claim an order the rows do not follow' );
        $this->assertStringContainsString( 'In shirt-number order', $html );

        $seven = strpos( $html, 'Speler Zeven' );
        $nine  = strpos( $html, 'Speler Negen' );
        $eleven = strpos( $html, 'Speler Elf' );
        $this->assertNotFalse( $seven );
        $this->assertNotFalse( $nine );
        $this->assertNotFalse( $eleven );
        $this->assertTrue( $seven < $nine && $nine < $eleven, 'rows follow shirt order 7, 9, 11 — not attendance' );

        // #4097 — on screen a name opens the player's profile; the printed
        // copy of the same document names them as text.
        $this->assertMatchesRegularExpression( '/<a class="tt-record-link" href="[^"]*">Speler Zeven<\/a>/', $html );
    }

    /**
     * #4097 — the page shows the document the PDF prints, as sheets, and
     * none of the retired web-only section renderers' markup.
     */
    public function test_the_page_shows_the_printed_sheets(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => 1, 'team_id' => $this->team_id, 'title' => 'Tuesday', 'session_date' => '2020-03-03',
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );

        $html = $this->renderReport( [ 'from' => '2020-03-01', 'to' => '2020-03-31', 'layout' => 'B', 'blocks' => 'kpi,roster,quality' ] );

        $this->assertStringContainsString( 'class="tt-mr-doc tt-mr-doc--b"', $html );
        $this->assertSame( 3, substr_count( $html, '<article class="tt-mr-sheet"' ), 'dashboard, roster, and the meeting page' );
        $this->assertStringContainsString( 'Page 3 of 3 in the PDF', $html );
        $this->assertStringNotContainsString( 'tt-mr-section', $html, 'no web-only section renderer is left' );
    }

    public function test_the_report_is_refused_when_switched_off(): void {
        \TT\Core\FeatureRegistry::setEnabled( 'report_team_monthly', false );
        $this->assertFalse( \TT\Core\FeatureRegistry::isEnabled( 'report_team_monthly' ), 'Precondition: the toggle took.' );

        $html = $this->renderReport( [ 'from' => '2020-03-01', 'to' => '2020-03-31' ] );

        $this->assertStringNotContainsString( 'tt-mr-panel', $html, 'A switched-off report must not render, even from a direct link.' );

        \TT\Core\FeatureRegistry::setEnabled( 'report_team_monthly', true );
    }
}
