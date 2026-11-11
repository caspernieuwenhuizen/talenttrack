<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\KpiComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDocument;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Export\Exporters\ReportStudioPdfExporter;
use TT\Modules\Export\Exporters\TeamMonthlyReportPdfExporter;
use TT\Modules\ReportStudio\Blocks\KeyFiguresBlock;
use TT\Modules\ReportStudio\Document\StudioDocument;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportDefinition;
use TT\Modules\ReportStudio\Domain\ReportPageFit;

/**
 * #5021 — a Report Studio report as one document. The PDF prints the markup
 * the screen shows; a block that is a monthly section prints with the
 * monthly report's renderer and is measured with its heights; the document
 * uses nothing DomPDF cannot print; and a report at the most blocks a
 * definition holds renders within the monthly report's own DomPDF time.
 */
final class StudioDocumentTest extends WP_UnitTestCase {

    private const KPI = [
        'activities'               => [ 'value' => 14, 'delta' => 2 ],
        'attendance_pct'           => [ 'value' => 84.5, 'delta' => -1.5 ],
        'minutes_share_median_pct' => [ 'value' => 52, 'delta' => null ],
        'evaluated'                => [ 'value' => 12, 'of' => 20, 'delta' => 3 ],
        'squad_rating'             => [ 'value' => 6.8, 'delta' => 0.2 ],
        'needs_attention'          => [ 'value' => 5, 'delta' => 1 ],
    ];

    public function test_the_pdf_prints_the_markup_the_screen_shows(): void {
        $doc   = $this->document();
        $body  = StudioDocument::body( $doc, 1 );
        $paper = StudioDocument::html( $doc, 1, 'Pack' );

        $this->assertSame( 1, preg_match( '#<body>(.*)</body>#s', $paper, $m ), 'the paper has a body' );
        $this->assertSame( $body, $m[1], 'the PDF\'s body is the screen\'s document, byte for byte' );
        $this->assertSame( $paper, ReportStudioPdfExporter::documentPayload( $doc, 1, 'Pack' )['html'], 'the exporter prints this document' );
        $this->assertSame( [ 'b1', 'b2', 'b3', 'b4', 'b6', 'b7' ], $this->blocksIn( $body ), 'every block but the page break, in order' );
    }

    public function test_the_document_breaks_its_pages_where_the_estimate_does(): void {
        $body  = StudioDocument::body( $this->document(), 1 );
        $pages = array_slice( explode( '<div class="tt-sd-page', $body ), 1 );

        $this->assertCount( 2, $pages, 'the page break starts a second page' );
        $this->assertSame( [ 'b1', 'b2', 'b3', 'b4' ], $this->blocksIn( $pages[0] ) );
        $this->assertSame( [ 'b6', 'b7' ], $this->blocksIn( $pages[1] ) );
        $this->assertStringStartsWith( ' tt-sd-break"', $pages[1], 'and the second page starts on new paper' );
        $this->assertStringContainsString( '<table class="tt-sd-pair" role="presentation">', $pages[0], 'two half blocks sit side by side' );
    }

    public function test_the_document_uses_nothing_dompdf_cannot_print(): void {
        $paper = StudioDocument::html( $this->document(), 1, 'Pack' );
        foreach ( [ 'display:flex', 'display: flex', 'display:grid', 'display: grid', '<script', 'style="' ] as $banned ) {
            $this->assertStringNotContainsString( $banned, $paper, "{$banned} is not in the PDF" );
        }
        $this->assertStringNotContainsString( '<a ', $paper, 'paper carries no links' );
    }

    public function test_a_block_the_reader_may_not_see_prints_no_figures(): void {
        $body = StudioDocument::body( $this->document(), 1 );
        $this->assertStringContainsString( 'Not available to you.', $body );
        $this->assertStringNotContainsString( 'forbidden detail', $body, 'the refusal\'s own words stay on the server' );
    }

    public function test_a_family_document_says_who_it_is_for(): void {
        $doc                        = $this->document();
        $doc['context']['audience'] = PlayerReportAudience::FAMILY;
        $this->assertStringContainsString( esc_html( PlayerReportAudience::footerText( true ) ), StudioDocument::body( $doc, 1 ) );
        $this->assertStringContainsString( esc_html( PlayerReportAudience::footerText( false ) ), StudioDocument::body( $this->document(), 1 ) );
    }

    public function test_the_key_figures_print_as_the_monthly_reports_kpi_section(): void {
        $figures = $this->figures( self::KPI );
        $this->assertSame( self::KPI, KeyFiguresBlock::section( $figures ), 'the section payload comes back whole from the figures' );

        $report   = [
            'from'   => '2026-08-01',
            'to'     => '2026-08-31',
            'blocks' => [ 'letterhead', 'kpi' ],
            'data'   => [ 'letterhead' => [ 'head_coach' => 'Head Coach', 'squad_size' => 20, 'activity_count' => 14 ], 'kpi' => self::KPI ],
        ];
        $prepared = TeamMonthlyReportDocument::prepare( $report, TeamMonthlyReportLayout::PACK );
        $screen   = TeamMonthlyReportDocument::screen( $prepared['report'], TeamMonthlyReportLayout::PACK, 'Studio U13', $prepared['fit'] );
        $section  = TeamMonthlyReportDocument::sectionHtml( 'kpi', self::KPI );

        $this->assertStringContainsString( '<table class="tt-d-kpi">', $section );
        $this->assertStringContainsString( $section, $screen, 'the monthly report\'s page shows this markup for its key figures' );

        $block = BlockRegistry::find( 'key_figures' );
        $this->assertInstanceOf( KeyFiguresBlock::class, $block );
        $this->assertStringContainsString( $section, $block->document( $figures ), 'and the Studio block prints the same' );

        $body = StudioDocument::body( [ 'context' => [ 'scope' => 'team' ], 'blocks' => [ [ 'id' => 'k', 'key' => 'key_figures', 'width' => 'full', 'data' => $figures ] ] ], 1 );
        $this->assertStringContainsString( $section, $body, 'in the Studio document too' );
    }

    public function test_the_key_figures_are_measured_as_the_monthly_report_measures_them(): void {
        $figures = $this->figures( self::KPI );
        $entry   = ReportPageFit::entryOf( [ 'id' => 'k', 'key' => 'key_figures', 'width' => 'full', 'data' => $figures ] );
        $this->assertSame( TeamMonthlyReportLayout::sectionHeight( 'kpi', self::KPI ), $entry['height'] ?? null, 'the monthly report\'s height for its key figures' );

        // The Studio's letterhead and key figures fill a page as the monthly
        // one-pager's letterhead and key figures do.
        $studio  = ReportPageFit::estimate( [
            ReportPageFit::entryOf( [ 'id' => 'l', 'key' => 'letterhead', 'width' => 'full', 'data' => [ 'subject' => 'team' ] ] ),
            $entry,
        ] );
        $monthly = TeamMonthlyReportLayout::fit( [
            'data' => [ 'letterhead' => [ 'activity_count' => 14 ], 'kpi' => self::KPI ],
        ], TeamMonthlyReportLayout::ONE_PAGER );

        $this->assertSame( $monthly['pages'], $studio['pages'] );
        $this->assertSame( $monthly['fill'], $studio['fill'] );
    }

    public function test_a_document_prints_at_most_the_blocks_a_definition_holds(): void {
        $blocks = [];
        for ( $i = 1; $i <= ReportDefinition::MAX_BLOCKS + 5; $i++ ) {
            $blocks[] = [ 'id' => 'b' . $i, 'key' => 'text', 'width' => 'full', 'data' => [ 'heading' => '', 'text' => 'Line ' . $i ] ];
        }
        $body = StudioDocument::body( [ 'context' => [], 'blocks' => $blocks ], 1 );
        $this->assertCount( StudioDocument::MAX_BLOCKS, $this->blocksIn( $body ) );
    }

    /**
     * The DomPDF budget: a Studio report at the most blocks a definition
     * holds renders within the time the monthly report's full pack takes on
     * the same machine, with slack for a noisy runner. Both times are printed
     * to the test log, so the PR can quote them.
     */
    public function test_the_largest_report_renders_within_the_monthly_reports_dompdf_time(): void {
        if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
            $this->markTestSkipped( 'DomPDF not installed.' );
        }

        $monthly = TeamMonthlyReportPdfExporter::payload( $this->monthlyReport( 20 ), TeamMonthlyReportLayout::PACK, 'Studio U13' );
        $studio  = ReportStudioPdfExporter::documentPayload( $this->largest(), 1, 'Studio U13' );

        // Warm the font cache once, so neither measurement pays for it.
        $this->render( '<html><body><p>x</p></body></html>' );
        $monthly_s = $this->render( $monthly['html'] );
        $studio_s  = $this->render( $studio['html'] );

        fwrite( STDERR, sprintf( "\n[#5021 DomPDF] monthly pack, 20 players: %.2fs; Studio, %d blocks: %.2fs\n", $monthly_s, ReportDefinition::MAX_BLOCKS, $studio_s ) );
        $this->assertLessThanOrEqual( max( 1.5, $monthly_s * 1.5 ), $studio_s, 'the largest Studio report renders within the monthly report\'s time' );
    }

    // ---- fixtures ------------------------------------------------------------------

    /** @return array<string,mixed> a read report: letterhead, a half pair, a refusal, a page break, a table */
    private function document(): array {
        $rows = [];
        for ( $i = 1; $i <= 6; $i++ ) $rows[] = [ 'player_id' => $i, 'name' => 'Player ' . $i, 'minutes' => 60 * $i, 'share_pct' => 10.5 * $i ];
        return [
            'context'  => [ 'scope' => 'team', 'team_id' => 3, 'from' => '2026-10-01', 'to' => '2026-10-31', 'audience' => PlayerReportAudience::INTERNAL ],
            'blocks'   => [
                [ 'id' => 'b1', 'key' => 'letterhead', 'width' => 'full', 'data' => [ 'subject' => 'team', 'team_name' => 'Studio U13', 'age_group' => 'U13', 'head_coach' => 'Head Coach', 'from' => '2026-10-01', 'to' => '2026-10-31' ] ],
                [ 'id' => 'b2', 'key' => 'minutes_share', 'width' => 'half', 'data' => [ 'value' => 42.5, 'unit' => 'percent', 'measure_label' => 'Share of available minutes', 'target_pct' => 50 ] ],
                [ 'id' => 'b3', 'key' => 'heading', 'width' => 'half', 'data' => [ 'heading' => 'Second half' ] ],
                [ 'id' => 'b4', 'key' => 'attendance_per_player', 'width' => 'full', 'error' => [ 'code' => 'forbidden', 'message' => 'forbidden detail' ] ],
                [ 'id' => 'b5', 'key' => 'page_break', 'width' => 'full', 'data' => [] ],
                [ 'id' => 'b6', 'key' => 'minutes_share', 'width' => 'full', 'data' => [ 'breakdown' => 'player_id', 'rows' => $rows, 'target_pct' => 50 ] ],
                [ 'id' => 'b7', 'key' => 'text', 'width' => 'full', 'data' => [ 'heading' => 'Reading it', 'text' => 'Lowest share first.' ] ],
            ],
            'page_fit' => [ 'pages' => 2, 'fill' => [ 40, 30 ], 'breaks' => [ 'b6' ] ],
        ];
    }

    /** @return array<string,mixed> the most blocks a definition holds, each the size a squad gives it */
    private function largest(): array {
        $squad = [];
        for ( $i = 1; $i <= 20; $i++ ) $squad[] = [ 'player_id' => $i, 'name' => 'Player ' . $i, 'minutes' => 60 * $i, 'available_minutes' => 900, 'share_pct' => 4.5 * $i ];
        $blocks = [
            [ 'id' => 'b1', 'key' => 'letterhead', 'width' => 'full', 'data' => [ 'subject' => 'team', 'team_name' => 'Studio U13', 'from' => '2026-10-01', 'to' => '2026-10-31' ] ],
            [ 'id' => 'b2', 'key' => 'key_figures', 'width' => 'full', 'data' => $this->figures( self::KPI ) ],
        ];
        for ( $i = 3; $i <= ReportDefinition::MAX_BLOCKS; $i++ ) {
            $blocks[] = match ( $i % 4 ) {
                0       => [ 'id' => 'b' . $i, 'key' => 'minutes_share', 'width' => 'full', 'data' => [ 'breakdown' => 'player_id', 'rows' => array_slice( $squad, 0, 10 ), 'target_pct' => 50 ] ],
                1       => [ 'id' => 'b' . $i, 'key' => 'minutes_share', 'width' => 'half', 'data' => [ 'value' => 42.5, 'unit' => 'percent', 'measure_label' => 'Share of available minutes' ] ],
                2       => [ 'id' => 'b' . $i, 'key' => 'minutes_share', 'width' => 'half', 'data' => [ 'value' => 38.0, 'unit' => 'percent', 'measure_label' => 'Share of available minutes' ] ],
                default => [ 'id' => 'b' . $i, 'key' => 'text', 'width' => 'full', 'data' => [ 'heading' => 'Note ' . $i, 'text' => str_repeat( 'What the squad worked on this month. ', 4 ) ] ],
            };
        }
        $layout = array_map( [ ReportPageFit::class, 'entryOf' ], $blocks );
        return [
            'context'  => [ 'scope' => 'team', 'team_id' => 3, 'from' => '2026-10-01', 'to' => '2026-10-31', 'audience' => PlayerReportAudience::INTERNAL ],
            'blocks'   => $blocks,
            'page_fit' => ReportPageFit::estimate( $layout ),
        ];
    }

    /**
     * The key figures block's payload for a KPI section, built the way the
     * block builds it.
     *
     * @param array<string,array<string,mixed>> $kpi
     * @return array<string,mixed>
     */
    private function figures( array $kpi ): array {
        $figures = [];
        foreach ( KpiComposer::tiles() as $tile ) {
            if ( ! array_key_exists( $tile['key'], $kpi ) ) continue;
            $m = $kpi[ $tile['key'] ];
            $figures[] = [
                'key'        => $tile['key'],
                'label'      => $tile['label'],
                'value'      => $m['value'] ?? null,
                'of'         => $tile['unit'] === 'count_of' ? (int) ( $m['of'] ?? 0 ) : null,
                'unit'       => $tile['unit'],
                'delta'      => $m['delta'] ?? null,
                'delta_unit' => $tile['delta_unit'],
            ];
        }
        return [ 'figures' => $figures, 'previous' => [ 'from' => '2026-07-01', 'to' => '2026-07-31' ] ];
    }

    /**
     * A full monthly composition for a squad, shaped like
     * `TeamMonthlyReport::forTeam()`'s payload.
     *
     * @return array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string}
     */
    private function monthlyReport( int $players ): array {
        $attendance = [];
        $minutes    = [];
        $roster     = [];
        for ( $i = 1; $i <= $players; $i++ ) {
            $name         = 'Player ' . $i;
            $attendance[] = [ 'player_id' => $i, 'name' => $name, 'present_pct' => 58 + $i * 2, 'band' => $i <= 3 ? 'red' : 'green' ];
            $minutes[]    = [ 'player_id' => $i, 'name' => $name, 'share_pct' => 18 + $i * 3.5 ];
            $roster[]     = [ 'player_id' => $i, 'name' => $name, 'jersey_number' => $i, 'status' => 'green', 'attendance_pct' => 80, 'minutes' => 540, 'share_pct' => 55.5, 'open_goals' => 2, 'injured' => $i === 5, 'suspended' => $i === 6 ? 2 : 0 ];
        }
        $attention = [];
        for ( $i = 1; $i <= 5; $i++ ) {
            $attention[] = [ 'player_id' => $i, 'name' => 'Player ' . $i, 'color' => 'red', 'attendance_pct' => 55, 'reasons' => [ 'Attendance below 70%' ] ];
        }
        return [
            'from'   => '2026-08-01',
            'to'     => '2026-08-31',
            'blocks' => [ 'letterhead', 'coverage', 'kpi', 'status', 'attendance', 'minutes', 'attention', 'changes', 'tests', 'roster', 'notes', 'quality' ],
            'data'   => [
                'letterhead' => [ 'head_coach' => 'Head Coach', 'squad_size' => $players, 'activity_count' => 14 ],
                'coverage'   => [ 'state' => 'partial', 'completed' => 14, 'with_register' => 13, 'missing' => [ [ 'title' => 'Training', 'date' => '2026-08-04' ] ] ],
                'kpi'        => self::KPI,
                'status'     => [ 'counts' => [ 'green' => 12, 'amber' => 4, 'red' => 2, 'unknown' => 2 ] ],
                'attendance' => [ 'rows' => $attendance, 'team_avg_pct' => 84 ],
                'minutes'    => [ 'rows' => $minutes, 'target_pct' => 50 ],
                'attention'  => [ 'items' => $attention ],
                'changes'    => [ 'events' => [ [ 'date' => '2026-08-10', 'player_id' => 1, 'name' => 'Player 1', 'summary' => 'Injury' ] ], 'open_injuries' => 1 ],
                'tests'      => [ 'rounds' => [ [ 'name' => 'Sprint 30m', 'date' => '2026-08-12', 'tested' => 18, 'squad' => $players, 'improved' => [], 'declined' => [] ] ] ],
                'roster'     => [ 'rows' => $roster ],
                'notes'      => [],
                'quality'    => [ 'activities_without_register' => [ 1 ], 'matches_without_minutes' => 0, 'players_not_evaluated' => [], 'players_with_incomplete_status' => [] ],
            ],
        ];
    }

    /** Seconds DomPDF takes to lay out and paint this document. */
    private function render( string $html ): float {
        $options = new \Dompdf\Options();
        $options->set( 'defaultFont', 'DejaVu Sans' );
        $options->set( 'isHtml5ParserEnabled', true );
        $dompdf = new \Dompdf\Dompdf( $options );
        $start  = microtime( true );
        $dompdf->loadHtml( $html, 'UTF-8' );
        $dompdf->setPaper( 'A4', 'portrait' );
        $dompdf->render();
        return microtime( true ) - $start;
    }

    /** @return list<string> the block ids a piece of the document holds, in order */
    private function blocksIn( string $html ): array {
        preg_match_all( '/<!--tt-sd-block:([a-z0-9_-]+)-->/', $html, $m );
        return $m[1];
    }
}
