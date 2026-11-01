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
 * Cap: `tt_edit_activities`, the capability the match prep view, its REST
 * route and the print page ask; then the activity's team, as they do.
 */
final class MatchPrepPdfExporter implements ExporterInterface {

    public function key(): string { return 'match_prep_pdf'; }

    public function label(): string { return __( 'Match preparation (PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_edit_activities'; }

    /** Non-tabular exporter — opts out of the column picker (#986). */
    public function availableColumns(): array { return []; }

    public function validateFilters( array $raw ): ?array {
        $activity_id = isset( $raw['activity_id'] ) ? (int) $raw['activity_id'] : 0;
        if ( $activity_id <= 0 ) return null;
        return [ 'activity_id' => $activity_id ];
    }

    public function collect( ExportRequest $request ): array {
        $activity_id = (int) ( $request->filters['activity_id'] ?? 0 );
        // The capability is club-wide on every coach; whose match it is is
        // the activity's team, asked as the activity brief and the print
        // router ask it. The admin flag is the requester's, who need not be
        // the user this request is authenticated as.
        // The capability is asked here too, so a caller that reaches
        // `collect()` without `ExportService` gets the same answer as the
        // match prep view: a reader who sees every team is not a coach.
        $requester = (int) $request->requesterUserId;
        if ( ! user_can( $requester, $this->requiredCap() )
            || ! \TT\Modules\Authorization\ActivityTeamScope::coversActivity( $requester, $activity_id, user_can( $requester, 'tt_edit_settings' ) ) ) {
            throw new \TT\Modules\Export\ExportException( 'forbidden', \TT\Modules\Authorization\ActivityTeamScope::refusalMessage() );
        }
        $body        =MatchPrepPrintableRenderer::bodyHtml( $activity_id, (int) $request->clubId, $requester );
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
