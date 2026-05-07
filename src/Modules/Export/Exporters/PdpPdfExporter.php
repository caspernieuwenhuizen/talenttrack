<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;
use TT\Modules\Pdp\Print\PdpPrintRouter;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;

/**
 * PdpPdfExporter (#0063 use case 2) — PDP / development plan PDF.
 *
 * The formal-plan deliverable from #0044, often printed for parent
 * meetings. Reuses `PdpPrintRouter::renderHtml()` (extracted as a
 * public method in v3.110.5 alongside this exporter) so the PDF and
 * the on-screen print view share their layout — header + photo,
 * season + status meta, current goals table, agreed-actions per
 * conversation, end-of-season verdict block, signature lines, and an
 * optional second-A4 evidence page. Fixes to the print layout
 * propagate to both surfaces simultaneously.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/pdp_pdf?format=pdf&file_id=42`
 *   `&include_evidence=1` to append the second A4 evidence page.
 *
 * Cap: `tt_view_pdp` (route gate) plus the per-file
 * `PdpPrintRouter::canAccess()` check inside `collect()` mirroring the
 * print path's authorization (admin / coach-of-this-player / linked
 * player or parent).
 *
 * Per-file `entityId` is set to `file_id` so the audit row carries the
 * specific PDP file the operator generated.
 */
final class PdpPdfExporter implements ExporterInterface {

    public function key(): string { return 'pdp_pdf'; }

    public function label(): string { return __( 'PDP / development plan (PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_view_pdp'; }

    public function validateFilters( array $raw ): ?array {
        $file_id = isset( $raw['file_id'] ) ? (int) $raw['file_id'] : 0;
        if ( $file_id <= 0 ) return null;

        $include_evidence = ! empty( $raw['include_evidence'] );

        return [
            'file_id'          => $file_id,
            'include_evidence' => $include_evidence,
        ];
    }

    public function collect( ExportRequest $request ): array {
        $file_id          = (int) ( $request->filters['file_id'] ?? 0 );
        $include_evidence = (bool) ( $request->filters['include_evidence'] ?? false );

        $files = new PdpFilesRepository();
        $file  = $files->find( $file_id );

        if ( ! $file ) {
            return [
                'html'    => '<p>' . esc_html__( 'PDP file not found.', 'talenttrack' ) . '</p>',
                'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
            ];
        }

        // Per-file authorization mirrors the on-screen print path's
        // canAccess(): coach-of-this-player + matrix `tt_view_pdp`,
        // linked self-player, or linked parent. The route-level cap
        // already gated to `tt_view_pdp`; this narrows to the file.
        if ( ! PdpPrintRouter::canAccess( $file ) ) {
            return [
                'html'    => '<p>' . esc_html__( 'You do not have access to this PDP file.', 'talenttrack' ) . '</p>',
                'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
            ];
        }

        $html = PdpPrintRouter::renderHtml( $file, $include_evidence );

        // The print layout's `<div class="toolbar">` carries the
        // browser-side Print / Re-render / Close buttons. `@media print`
        // hides them when the user prints from a browser, but DomPDF
        // doesn't honour print-media queries, so without this strip
        // the buttons would render visibly in the PDF. Mirrors the
        // v3.110.4 PlayerEvaluationPdfExporter's `<script>` strip.
        $html = self::stripToolbar( $html );

        return [
            'html'    => $html,
            'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
        ];
    }

    private static function stripToolbar( string $html ): string {
        $clean = preg_replace(
            '#<div\s+class=["\']toolbar["\'][^>]*>.*?</div>#is',
            '',
            $html,
            1
        );
        return $clean === null ? $html : $clean;
    }
}
