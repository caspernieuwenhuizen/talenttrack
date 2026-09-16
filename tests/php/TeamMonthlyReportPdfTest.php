<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterRegistry;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\Exporters\TeamMonthlyReportPdfExporter;

/**
 * #3460 (epic #3457) — the team monthly report on paper.
 *
 * Pinned: the template is DomPDF-safe (no flexbox, no grid); the page count
 * the composition panel promises is the page count DomPDF prints, for a
 * 20-player squad in every layout; the one-pager prints the shortened lists it
 * measured; the confidential footer is on the page; and a user who cannot read
 * the team's reports is refused rather than handed an empty PDF.
 */
final class TeamMonthlyReportPdfTest extends WP_UnitTestCase {

    public function test_exporter_is_registered_for_pdf_only(): void {
        $exporter = ExporterRegistry::get( 'team_monthly_report_pdf' );
        $this->assertInstanceOf( TeamMonthlyReportPdfExporter::class, $exporter );
        $this->assertSame( [ 'pdf' ], $exporter->supportedFormats() );
    }

    public function test_filters_need_a_team_and_fall_back_to_last_month(): void {
        $exporter = new TeamMonthlyReportPdfExporter();
        $this->assertNull( $exporter->validateFilters( [] ) );
        $this->assertNull( $exporter->validateFilters( [ 'team_id' => 4, 'period' => 'next_decade' ] ) );
        $this->assertNull( $exporter->validateFilters( [ 'team_id' => 4, 'from' => '2026-08-31', 'to' => '2026-08-01' ] ) );

        $clean = $exporter->validateFilters( [ 'team_id' => '4', 'from' => '2026-08-01', 'to' => '2026-08-31', 'layout' => 'c', 'blocks' => 'kpi,bogus,roster' ] );
        $this->assertIsArray( $clean );
        $this->assertSame( 'C', $clean['layout'] );
        $this->assertSame( [ 'kpi', 'roster' ], $clean['blocks'], 'Unknown blocks drop, as they do on screen.' );

        $default = $exporter->validateFilters( [ 'team_id' => 4 ] );
        $this->assertIsArray( $default );
        $this->assertSame( TeamMonthlyReportLayout::DEFAULT, $default['layout'] );
        $this->assertStringEndsWith( '-01', $default['from'] );
    }

    public function test_a_user_without_the_team_is_refused(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Pdf U13', 'age_group' => 'U13' ] );
        $team_id = (int) $wpdb->insert_id;
        $uid     = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $request = new ExportRequest( 'team_monthly_report_pdf', 'pdf', 1, $uid, null, [
            'team_id' => $team_id, 'from' => '2026-08-01', 'to' => '2026-08-31', 'layout' => 'A', 'blocks' => [],
        ] );

        try {
            ( new TeamMonthlyReportPdfExporter() )->collect( $request );
            $this->fail( 'A user who cannot read the team must not get a PDF.' );
        } catch ( ExportException $e ) {
            $this->assertSame( 'forbidden', $e->errorKey );
        }
    }

    public function test_the_template_uses_no_layout_dompdf_cannot_print(): void {
        foreach ( TeamMonthlyReportLayout::ALL as $layout ) {
            $html = TeamMonthlyReportPdfExporter::payload( $this->report( 20 ), $layout, 'Pdf U13' )['html'];
            $flat = strtolower( (string) preg_replace( '/\s+/', '', $html ) );
            $this->assertStringNotContainsString( 'display:flex', $flat, "Layout {$layout} uses flexbox." );
            $this->assertStringNotContainsString( 'display:grid', $flat, "Layout {$layout} uses grid." );
            $this->assertStringContainsString( 'Confidential', $html, "Layout {$layout} lost its confidential footer." );
        }
    }

    public function test_the_matrix_prints_landscape(): void {
        $this->assertSame( 'landscape', TeamMonthlyReportPdfExporter::payload( $this->report( 20 ), 'C', 'x' )['options']['orientation'] );
        $this->assertSame( 'portrait', TeamMonthlyReportPdfExporter::payload( $this->report( 20 ), 'B', 'x' )['options']['orientation'] );
    }

    public function test_the_one_pager_prints_the_lists_it_measured(): void {
        $report = $this->only( $this->report( 20 ), [ 'coverage', 'kpi', 'status', 'attendance', 'minutes', 'attention', 'notes' ] );
        $fit    = TeamMonthlyReportLayout::fit( $report, 'A' );
        $this->assertContains( TeamMonthlyReportLayout::ELIDE_RANKED, $fit['degraded'], 'Precondition: 20 players need shortening.' );

        $html = TeamMonthlyReportPdfExporter::payload( $report, 'A', 'x' )['html'];
        $this->assertStringContainsString( '13 players between', $html );
        $this->assertStringNotContainsString( 'Player 10<', $html, 'The middle of the ranked list is left out.' );
    }

    /**
     * The promise the panel makes. Rendered with the real DomPDF, so a change
     * to the template's row heights that the estimate does not follow fails
     * here rather than on a coach's printer.
     */
    public function test_the_printed_page_count_is_the_estimated_one_for_a_twenty_player_squad(): void {
        if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
            $this->markTestSkipped( 'DomPDF not installed.' );
        }

        $cases = [
            'one-pager, all sections'  => [ 'A', null ],
            'one-pager, the dashboard' => [ 'A', [ 'coverage', 'kpi', 'status', 'attendance', 'attention', 'quality' ] ],
            'pack, all sections'       => [ 'B', null ],
            'pack, no roster'          => [ 'B', [ 'coverage', 'kpi', 'status', 'attendance', 'minutes', 'attention', 'notes' ] ],
            'matrix, the squad'        => [ 'C', [ 'kpi', 'roster' ] ],
            'matrix, all sections'     => [ 'C', null ],
        ];
        foreach ( $cases as $label => [ $layout, $blocks ] ) {
            $report  = $blocks === null ? $this->report( 20 ) : $this->only( $this->report( 20 ), $blocks );
            $fit     = TeamMonthlyReportLayout::fit( $report, $layout );
            $payload = TeamMonthlyReportPdfExporter::payload( $report, $layout, 'Pdf U13' );

            $options = new \Dompdf\Options();
            $options->set( 'defaultFont', 'DejaVu Sans' );
            $options->set( 'isHtml5ParserEnabled', true );
            $dompdf = new \Dompdf\Dompdf( $options );
            $dompdf->loadHtml( $payload['html'], 'UTF-8' );
            $dompdf->setPaper( 'A4', $payload['options']['orientation'] );
            $dompdf->render();

            $this->assertSame( $fit['pages'], $dompdf->getCanvas()->get_page_count(), "{$label}: the panel and the paper disagree." );
        }
    }

    /**
     * A full composition for a squad of `$players`, shaped like
     * `TeamMonthlyReport::forTeam()`'s payload.
     *
     * @return array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string}
     */
    private function report( int $players ): array {
        $attendance = [];
        $minutes    = [];
        $roster     = [];
        for ( $i = 1; $i <= $players; $i++ ) {
            $name         = 'Player ' . $i;
            $attendance[] = [ 'player_id' => $i, 'name' => $name, 'present_pct' => 58 + $i * 2, 'band' => $i <= 3 ? 'red' : 'green' ];
            $minutes[]    = [ 'player_id' => $i, 'name' => $name, 'share_pct' => 18 + $i * 3.5 ];
            $roster[]     = [ 'player_id' => $i, 'name' => $name, 'jersey_number' => $i, 'status' => 'green', 'attendance_pct' => 80, 'minutes' => 540, 'share_pct' => 55.5, 'open_goals' => 2, 'injured' => $i === 5 ];
        }
        $attention = [];
        for ( $i = 1; $i <= 5; $i++ ) {
            $attention[] = [ 'player_id' => $i, 'name' => 'Player ' . $i, 'color' => 'red', 'attendance_pct' => 55, 'reasons' => [ 'Attendance below 70%' ] ];
        }

        return [
            'from'   => '2026-08-01',
            'to'     => '2026-08-31',
            'blocks' => TeamMonthlyReportBlock::ALL,
            'data'   => [
                'letterhead' => [ 'head_coach' => 'Head Coach', 'squad_size' => $players, 'activity_count' => 14 ],
                'coverage'   => [ 'state' => 'partial', 'completed' => 14, 'with_register' => 13, 'missing' => [ [ 'title' => 'Training', 'date' => '2026-08-04' ] ] ],
                'kpi'        => [ 'activities' => [ 'value' => 14, 'delta' => 2 ], 'attendance_pct' => [ 'value' => 84.5, 'delta' => -1.5 ], 'minutes_share_median_pct' => [ 'value' => 52, 'delta' => null ], 'evaluated' => [ 'value' => 12, 'of' => $players, 'delta' => 3 ], 'squad_rating' => [ 'value' => 6.8, 'delta' => 0.2 ], 'needs_attention' => [ 'value' => 5, 'delta' => 1 ] ],
                'status'     => [ 'counts' => [ 'green' => 12, 'amber' => 4, 'red' => 2, 'unknown' => 2 ] ],
                'attendance' => [ 'rows' => $attendance, 'team_avg_pct' => 84 ],
                'minutes'    => [ 'rows' => $minutes, 'target_pct' => 50 ],
                'attention'  => [ 'items' => $attention ],
                'changes'    => [ 'events' => [ [ 'date' => '2026-08-10', 'player_id' => 1, 'name' => 'Player 1', 'summary' => 'Injury' ], [ 'date' => '2026-08-20', 'player_id' => 2, 'name' => 'Player 2', 'summary' => 'Moved to U14' ] ], 'open_injuries' => 1 ],
                'tests'      => [ 'rounds' => [ [ 'name' => 'Sprint 30m', 'date' => '2026-08-12', 'tested' => 18, 'squad' => $players, 'improved' => [], 'declined' => [] ] ] ],
                'roster'     => [ 'rows' => $roster ],
                'notes'      => [],
                'quality'    => [ 'activities_without_register' => [ 1 ], 'matches_without_minutes' => 0, 'players_not_evaluated' => [], 'players_with_incomplete_status' => [] ],
            ],
        ];
    }

    /**
     * The same report with only `$blocks` selected, as the composer would
     * return it: a deselected block has no data at all.
     *
     * @param array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string} $report
     * @param list<string> $blocks
     * @return array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string}
     */
    private function only( array $report, array $blocks ): array {
        $keep             = array_merge( [ TeamMonthlyReportBlock::LETTERHEAD ], $blocks );
        $report['data']   = array_intersect_key( $report['data'], array_flip( $keep ) );
        $report['blocks'] = array_values( array_filter( TeamMonthlyReportBlock::ALL, static fn( string $b ): bool => in_array( $b, $keep, true ) ) );
        return $report;
    }
}
