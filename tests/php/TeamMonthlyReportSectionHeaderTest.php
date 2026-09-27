<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\ReportBrandColour;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Export\Exporters\TeamMonthlyReportPdfExporter;

/**
 * #4096 (epic #4094) — every section opens with one numbered header, on
 * screen and on paper.
 *
 * Pinned: numbers follow the ticked sections in each layout's print order;
 * the PDF prints the same numbers, titles and meta lines; the meta line is
 * read off the section's data; and a club colour too pale to read on its own
 * tint gets ink numbers.
 */
final class TeamMonthlyReportSectionHeaderTest extends WP_UnitTestCase {

    public function test_numbers_follow_the_ticked_sections_in_print_order(): void {
        $selected = [ 'letterhead', 'kpi', 'status', 'attendance', 'matches', 'tests', 'roster', 'quality' ];

        $this->assertSame(
            [ 'status' => 1, 'attendance' => 2, 'matches' => 3, 'tests' => 4, 'roster' => 5, 'quality' => 6 ],
            TeamMonthlyReportLayout::sectionNumbers( $selected, TeamMonthlyReportLayout::ONE_PAGER )
        );
        // The pack prints the roster on its own page, ahead of the meeting pages.
        $this->assertSame(
            [ 'status' => 1, 'attendance' => 2, 'roster' => 3, 'matches' => 4, 'tests' => 5, 'quality' => 6 ],
            TeamMonthlyReportLayout::sectionNumbers( $selected, TeamMonthlyReportLayout::PACK )
        );
        // The matrix gathers matches and tests in its footer strip.
        $this->assertSame(
            [ 'status' => 1, 'roster' => 2, 'quality' => 3, 'matches' => 4, 'tests' => 5 ],
            TeamMonthlyReportLayout::sectionNumbers( $selected, TeamMonthlyReportLayout::MATRIX )
        );
    }

    public function test_the_pdf_prints_the_numbered_header_with_its_meta(): void {
        $report = [
            'from' => '2026-08-01', 'to' => '2026-08-31', 'blocks' => [ 'letterhead', 'status', 'matches' ],
            'data' => [
                'letterhead' => [ 'head_coach' => '', 'squad_size' => 20, 'activity_count' => 8 ],
                'status'     => [ 'counts' => [ 'green' => 12, 'amber' => 4, 'red' => 2, 'unknown' => 2 ] ],
                'matches'    => [
                    'shows' => [ 'record' => true, 'scorers' => false, 'squads' => false ],
                    'record' => [ 'played' => 4, 'won' => 2, 'drawn' => 1, 'lost' => 1, 'goals_for' => 8, 'goals_against' => 5, 'goal_difference' => 3 ],
                    'fixtures' => array_fill( 0, 4, [ 'date' => '2026-08-05', 'opponent' => 'Opp', 'home_away' => 'home', 'team_score' => 1, 'opp_score' => 0, 'outcome' => 'W' ] ),
                    'scorers' => [], 'tournaments_excluded' => 0,
                ],
            ],
        ];

        $this->assertSame( '4 played · 2 W 1 D 1 L', TeamMonthlyReport::sectionMeta( 'matches', $report['data']['matches'] ) );
        $this->assertSame( '12 of 20 on track', TeamMonthlyReport::sectionMeta( 'status', $report['data']['status'] ) );

        foreach ( TeamMonthlyReportLayout::ALL as $layout ) {
            $html = TeamMonthlyReportPdfExporter::payload( $report, $layout, 'Header U13' )['html'];
            $this->assertStringContainsString( '<td class="tt-mr-sh__no">01</td><td class="tt-mr-sh__t"><h2>Squad status</h2>', $html, "{$layout}: status is section 1" );
            $this->assertStringContainsString( '<td class="tt-mr-sh__no">02</td><td class="tt-mr-sh__t"><h2>Matches</h2>', $html, "{$layout}: matches is section 2" );
        }
        $pack = TeamMonthlyReportPdfExporter::payload( $report, TeamMonthlyReportLayout::PACK, 'Header U13' )['html'];
        $this->assertStringContainsString( '4 played · 2 W 1 D 1 L', $pack );
    }

    public function test_a_pale_club_colour_gets_ink_numbers(): void {
        $this->assertTrue( ReportBrandColour::readableOnTint( '#0b3d2e' ), 'the shipped green reads on its tint' );
        $this->assertTrue( ReportBrandColour::readableOnTint( '#0262a1' ), 'the Leon Hutten blue reads on its tint' );
        $this->assertFalse( ReportBrandColour::readableOnTint( '#f5d000' ), 'a yellow does not' );
        $this->assertSame( ReportBrandColour::INK, ReportBrandColour::numberColour( '#f5d000' ) );
        $this->assertGreaterThanOrEqual( 4.5, ReportBrandColour::contrast( ReportBrandColour::INK, ReportBrandColour::tint( '#f5d000' ) ) );
    }
}
