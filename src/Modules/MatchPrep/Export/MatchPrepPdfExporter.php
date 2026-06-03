<?php
namespace TT\Modules\MatchPrep\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;
use TT\Modules\MatchPrep\Print\MatchPrepPrintableRenderer;

/**
 * MatchPrepPdfExporter (#838, #1059) — landscape A4 print sheet for a
 * match preparation.
 *
 * #1059 — body rendering delegates to MatchPrepPrintableRenderer so
 * the PDF output mirrors the on-screen match-prep view's content
 * shape: formation pitches per half, bench per half, Dutch
 * Wedstrijddoelen labels (Algemeen / Aanvallen / Verdedigen /
 * Spelhervattingen aanvallend + verdedigend), and a row per
 * available player on the "Doen per speler" column. The browser
 * print router consumes the same renderer, so the two outputs stay
 * in lockstep.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/match_prep_pdf?format=pdf&activity_id=42`
 *
 * Cap: `tt_view_activities`.
 */
final class MatchPrepPdfExporter implements ExporterInterface {

    public function key(): string { return 'match_prep_pdf'; }

    public function label(): string { return __( 'Match preparation (PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_view_activities'; }

    /** Non-tabular exporter — opts out of the column picker (#986). */
    public function availableColumns(): array { return []; }

    public function validateFilters( array $raw ): ?array {
        $activity_id = isset( $raw['activity_id'] ) ? (int) $raw['activity_id'] : 0;
        if ( $activity_id <= 0 ) return null;
        return [ 'activity_id' => $activity_id ];
    }

    public function collect( ExportRequest $request ): array {
        $activity_id = (int) ( $request->filters['activity_id'] ?? 0 );
        $body        = MatchPrepPrintableRenderer::bodyHtml( $activity_id, (int) $request->clubId );
        if ( $body === '' ) {
            return [
                'html'    => '<p>' . esc_html__( 'No match prep exists for this activity yet. Open the wizard from the activity detail page.', 'talenttrack' ) . '</p>',
                'options' => [ 'paper' => 'A4', 'orientation' => 'landscape' ],
            ];
        }
        $html = '<style>' . MatchPrepPrintableRenderer::styleBlock() . '</style>' . $body;
        return [
            'html'    => $html,
            'options' => [ 'paper' => 'A4', 'orientation' => 'landscape' ],
        ];
    }
}
