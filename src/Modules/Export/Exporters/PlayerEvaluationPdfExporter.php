<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;
use TT\Modules\Reports\PlayerReportRenderer;

/**
 * PlayerEvaluationPdfExporter (#0063 use case 1) — player evaluation PDF.
 *
 * The canonical "report" deliverable from #0014, lifted out of Reports
 * so other surfaces (a coach's "Send report to parent" button, the
 * scout-share link, a future "Generate end-of-cycle PDFs" batch action)
 * can produce one through the central Export module.
 *
 * Reuses `PlayerReportRenderer::renderStandard()` for the HTML body —
 * the same renderer that powers the on-screen report view today.
 * Wrapping the existing renderer keeps the PDF output and the on-screen
 * view in lockstep without forking the layout. The renderer's chart
 * payload `<script>` block is stripped before handoff to `PdfRenderer`
 * because DomPDF doesn't execute JavaScript — Chart.js charts render
 * empty in PDFs (a known v1 limitation; SVG fallback is a follow-up
 * once the brand-kit template-inheritance work lands).
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/player_evaluation_pdf?format=pdf&player_id=42`
 *   filters:
 *     `player_id`  (REQUIRED — the player to render the report for)
 *     `date_from`  (optional ISO date; restricts evaluation window)
 *     `date_to`    (optional ISO date; restricts evaluation window)
 *     `eval_type_id` (optional positive int; filters to one eval type)
 *
 * Cap: `tt_view_evaluations` — same gate as the on-screen report view.
 */
final class PlayerEvaluationPdfExporter implements ExporterInterface {

    public function key(): string { return 'player_evaluation_pdf'; }

    public function label(): string { return __( 'Player evaluation report (PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_view_evaluations'; }

    public function validateFilters( array $raw ): ?array {
        $player_id = isset( $raw['player_id'] ) ? (int) $raw['player_id'] : 0;
        if ( $player_id <= 0 ) return null;

        $filters = [ 'player_id' => $player_id ];

        // The renderer's `ReportConfig::sanitizeRawFilters()` re-validates
        // these too, but pruning here keeps the audit-row payload tidy.
        if ( isset( $raw['date_from'] ) && $raw['date_from'] !== '' ) {
            $filters['date_from'] = (string) $raw['date_from'];
        }
        if ( isset( $raw['date_to'] ) && $raw['date_to'] !== '' ) {
            $filters['date_to'] = (string) $raw['date_to'];
        }
        if ( isset( $raw['eval_type_id'] ) ) {
            $eval_type_id = (int) $raw['eval_type_id'];
            if ( $eval_type_id > 0 ) $filters['eval_type_id'] = $eval_type_id;
        }

        return $filters;
    }

    public function collect( ExportRequest $request ): array {
        $filters     = $request->filters;
        $player_id   = (int) ( $filters['player_id'] ?? 0 );

        // Tenant-scope check — the player must belong to the current
        // request's club. Without this an authenticated user could
        // request a report for a player in another club by guessing
        // the id. `QueryHelpers::get_player()` already scopes to the
        // current club via its repository; an unscoped fetch here
        // would be a regression.
        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player ) {
            return [
                'html'    => '<p>' . esc_html__( 'Player not found.', 'talenttrack' ) . '</p>',
                'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
            ];
        }

        // Renderer-friendly filter shape.
        $renderer_filters = [];
        foreach ( [ 'date_from', 'date_to', 'eval_type_id' ] as $k ) {
            if ( isset( $filters[ $k ] ) ) $renderer_filters[ $k ] = $filters[ $k ];
        }

        $html = PlayerReportRenderer::renderStandard(
            $player_id,
            $renderer_filters,
            (int) $request->requesterUserId
        );

        // DomPDF can't run JS — strip the Chart.js boot script so the
        // PDF doesn't carry dead script bytes (and so the boot doesn't
        // accidentally execute when the same HTML is reused in a
        // browser preview). The chart `<canvas>` blocks are kept; a
        // future ship swaps them for server-side SVG.
        $html = self::stripScriptTags( $html );

        return [
            'html'    => $html,
            'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
        ];
    }

    private static function stripScriptTags( string $html ): string {
        $clean = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
        return $clean === null ? $html : $clean;
    }
}
