<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDelivery;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Format\Renderers\PdfRenderer;

/**
 * #4141 — the monthly report's downloaded PDF is named after the team and the
 * report's month, the same name the scheduled e-mail attaches, and every
 * other PDF export keeps `<exporter key>-<today>.pdf`.
 */
final class TeamMonthlyReportFilenameTest extends WP_UnitTestCase {

    public function test_a_single_month_names_the_team_and_the_month(): void {
        $this->assertSame( 'Hedel-O14-1-2026-09.pdf', TeamMonthlyReportDelivery::filename( 'Hedel O14-1', 7, '2026-09-01', '2026-09-30' ) );
        $this->assertSame( 'Hedel-O14-1-2026-09.pdf', TeamMonthlyReportDelivery::filename( 'Hedel O14-1', 7, '2026-09-01' ), 'The schedule call without an end keeps its name.' );
    }

    public function test_a_range_over_two_months_names_both_ends(): void {
        $this->assertSame( 'Team-2026-08-2026-09.pdf', TeamMonthlyReportDelivery::filename( 'Team', 7, '2026-08-15', '2026-09-14' ) );
    }

    public function test_a_snapshot_adds_the_day_it_was_taken(): void {
        $this->assertSame(
            'Team-2026-09-snapshot-2026-10-02.pdf',
            TeamMonthlyReportDelivery::filename( 'Team', 7, '2026-09-01', '2026-09-30', '2026-10-02 14:05:00' )
        );
    }

    public function test_an_unusable_team_name_falls_back_to_the_team_id(): void {
        $this->assertSame( 'team-12-2026-09.pdf', TeamMonthlyReportDelivery::filename( '   ', 12, '2026-09-01', '2026-09-30' ) );
        $name = TeamMonthlyReportDelivery::filename( 'JO13/1 "A"', 12, '2026-09-01', '2026-09-30' );
        $this->assertStringNotContainsString( '/', $name );
        $this->assertStringNotContainsString( '"', $name );
        $this->assertStringEndsWith( '-2026-09.pdf', $name );
    }

    public function test_the_pdf_renderer_uses_a_supplied_name_and_falls_back_otherwise(): void {
        $monthly = new ExportRequest( 'team_monthly_report_pdf', 'pdf', 1, 1 );
        $this->assertSame( 'Hedel-O14-1-2026-09.pdf', PdfRenderer::filename( $monthly, [ 'html' => '', 'filename' => 'Hedel-O14-1-2026-09.pdf' ] ) );

        $other = new ExportRequest( 'pdp_pdf', 'pdf', 1, 1 );
        $this->assertSame( 'pdp_pdf-' . gmdate( 'Y-m-d' ) . '.pdf', PdfRenderer::filename( $other, '<p>x</p>' ) );
        $this->assertSame( 'pdp_pdf-' . gmdate( 'Y-m-d' ) . '.pdf', PdfRenderer::filename( $other, [ 'html' => '<p>x</p>' ] ) );
        $this->assertSame( 'pdp_pdf-' . gmdate( 'Y-m-d' ) . '.pdf', PdfRenderer::filename( $other, [ 'html' => '', 'filename' => '' ] ) );
    }
}
