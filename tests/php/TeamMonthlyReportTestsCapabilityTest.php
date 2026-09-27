<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Analytics\Reports\TestsBlockOptions;
use TT\Modules\Export\Exporters\TeamMonthlyReportPdfExporter;

/**
 * #4095 (epic #4094) — which tests detail each layout can print, decided in
 * one place.
 *
 * Pinned: the pack prints every `show` value and the other two print the
 * summary; the reason the panel shows is empty exactly when the option is
 * supported; the landscape matrix keeps three tests and names the rest; and
 * the PDF follows the same rule, so "Readings" on the pack prints the table.
 */
final class TeamMonthlyReportTestsCapabilityTest extends WP_UnitTestCase {

    public function test_only_the_pack_prints_a_player_table(): void {
        foreach ( TestsBlockOptions::SHOW as $show ) {
            $this->assertTrue( TeamMonthlyReportLayout::supportsTestsShow( TeamMonthlyReportLayout::PACK, $show ), "pack / {$show}" );
            $this->assertSame( $show, TeamMonthlyReportLayout::testsShowFor( TeamMonthlyReportLayout::PACK, $show ) );
            $this->assertSame( '', TeamMonthlyReportLayout::testsShowReason( TeamMonthlyReportLayout::PACK, $show ) );

            foreach ( [ TeamMonthlyReportLayout::ONE_PAGER, TeamMonthlyReportLayout::MATRIX ] as $layout ) {
                $supported = $show === TestsBlockOptions::SHOW_SUMMARY;
                $this->assertSame( $supported, TeamMonthlyReportLayout::supportsTestsShow( $layout, $show ), "{$layout} / {$show}" );
                $this->assertSame( TestsBlockOptions::SHOW_SUMMARY, TeamMonthlyReportLayout::testsShowFor( $layout, $show ) );
                $this->assertSame( $supported, TeamMonthlyReportLayout::testsShowReason( $layout, $show ) === '', "{$layout} / {$show} reason" );
            }
        }
    }

    public function test_the_matrix_keeps_three_tests_and_names_the_rest(): void {
        $rounds = [];
        foreach ( [ 'Sprint', 'Jump', 'Yo-Yo', 'Agility', 'Cooper' ] as $name ) $rounds[] = [ 'name' => $name ];

        $matrix = TeamMonthlyReportLayout::testsForLayout( [ 'rounds' => $rounds, 'show' => 'values' ], TeamMonthlyReportLayout::MATRIX );
        $this->assertCount( 3, $matrix['rounds'] );
        $this->assertSame( [ 'Agility', 'Cooper' ], $matrix['omitted'] );
        $this->assertSame( TestsBlockOptions::SHOW_SUMMARY, $matrix['show'] );

        $pack = TeamMonthlyReportLayout::testsForLayout( [ 'rounds' => $rounds, 'show' => 'values' ], TeamMonthlyReportLayout::PACK );
        $this->assertCount( 5, $pack['rounds'] );
        $this->assertArrayNotHasKey( 'omitted', $pack );
        $this->assertSame( 'values', $pack['show'] );
    }

    public function test_the_pdf_prints_what_the_layout_supports(): void {
        $round = [
            'name' => 'Sprint 30m', 'date' => '2026-08-12', 'tested' => 2, 'squad' => 2, 'direction' => 'lower',
            'average' => [ 'display' => '4,90', 'delta_display' => '—' ], 'moves' => [ 'up' => 1, 'down' => 0, 'flat' => 0, 'first' => 1 ],
            'readings' => [
                [ 'player_id' => 1, 'name' => 'Reading One', 'value_display' => '4,80', 'rank' => 1, 'first' => true ],
                [ 'player_id' => 2, 'name' => 'Reading Two', 'value_display' => '5,00', 'rank' => 2, 'first' => true ],
            ],
        ];
        $report = [
            'from' => '2026-08-01', 'to' => '2026-08-31', 'blocks' => [ 'letterhead', 'tests' ],
            'data' => [
                'letterhead' => [ 'head_coach' => '', 'squad_size' => 2, 'activity_count' => 3 ],
                'tests'      => [ 'rounds' => [ $round ], 'show' => 'values' ],
            ],
        ];

        $pack = TeamMonthlyReportPdfExporter::payload( $report, TeamMonthlyReportLayout::PACK, 'Cap U13' )['html'];
        $this->assertStringContainsString( 'Reading Two', $pack, 'Readings on the pack print the readings table.' );

        $one = TeamMonthlyReportPdfExporter::payload( $report, TeamMonthlyReportLayout::ONE_PAGER, 'Cap U13' )['html'];
        $this->assertStringNotContainsString( 'Reading Two', $one, 'The one-pager prints the summary, as the panel said.' );

        $report['data']['tests']['rounds'] = [];
        foreach ( [ 'Sprint', 'Jump', 'Yo-Yo', 'Agility', 'Cooper' ] as $name ) {
            $report['data']['tests']['rounds'][] = [ 'name' => $name, 'date' => '2026-08-12', 'tested' => 2, 'squad' => 2, 'improved' => [], 'declined' => [] ];
        }
        $matrix = TeamMonthlyReportPdfExporter::payload( $report, TeamMonthlyReportLayout::MATRIX, 'Cap U13' )['html'];
        $this->assertStringContainsString( 'Agility, Cooper', $matrix, 'Landscape names the tests it leaves out.' );
    }
}
