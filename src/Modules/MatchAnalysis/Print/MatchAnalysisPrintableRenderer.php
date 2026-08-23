<?php
namespace TT\Modules\MatchAnalysis\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\Frontend\MatchAnalysisDocument;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;

/**
 * MatchAnalysisPrintableRenderer — the printed analysis.
 *
 * The body is `MatchAnalysisDocument`, the same markup the screen and the
 * share page render, so the sheet a coach hands round the staff room is the
 * document they were looking at rather than a second implementation of it.
 *
 * What is print-specific lives here and nowhere else: one landscape A4, the
 * app's stylesheets pulled in over http (the standalone route runs outside
 * the shell, so `wp_enqueue_style` never fires for it), and the footer that
 * says what the sheet is.
 *
 * Text, not an image capture. Match prep prints through html2canvas because
 * its value is a pitch diagram; an analysis is prose and names, where
 * selectable, searchable text is worth more than pixel fidelity — and the
 * browser's own dialog then makes the PDF with no library at all.
 */
final class MatchAnalysisPrintableRenderer {

    /**
     * @param array<string,mixed> $payload
     */
    public static function bodyHtml( array $payload ): string {
        ob_start();
        MatchAnalysisDocument::render( $payload, [ 'print' => true ] );
        return (string) ob_get_clean();
    }

    /**
     * The stylesheets the standalone document needs, as `<link>` tags. The
     * print route emits a bare page, so the tokens, the base styles and the
     * document sheet are all requested by URL.
     */
    public static function styleLinks(): string {
        $sheets = [
            'assets/css/tokens.css',
            'assets/css/public.css',
            'assets/css/frontend-match-analysis-document.css',
        ];

        $out = '';
        foreach ( $sheets as $sheet ) {
            $out .= '<link rel="stylesheet" href="'
                . esc_url( TT_PLUGIN_URL . $sheet . '?v=' . rawurlencode( (string) TT_VERSION ) )
                . '" />';
        }
        return $out;
    }

    /**
     * Page geometry and the few overrides a sheet of paper needs that a
     * screen does not. Everything else comes from the shared stylesheet.
     */
    public static function styleBlock(): string {
        return '@page { size: A4 landscape; margin: 10mm; }'
            . 'body { margin: 0; padding: 10mm; background: #fff; }'
            . '@media print { body { padding: 0; } }'
            // The document's own print rules only apply under @media print;
            // the on-screen preview of this route should already look like
            // the sheet, so the landscape grid is restated here.
            . '@media screen { .tt-mad__body { display: grid;'
            . ' grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 78mm;'
            . ' gap: 4mm; align-items: start; }'
            . ' .tt-mad__players { grid-column: auto; }'
            . ' .tt-mad__head { flex-direction: row; align-items: flex-end; justify-content: space-between; } }';
    }

    /**
     * Convenience for the router: compose then render, or '' when the
     * activity carries no analysis worth printing.
     */
    public static function forActivity( int $activity_id ): string {
        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        if ( $payload === null || (int) $payload['analysis_id'] <= 0 ) return '';

        return self::bodyHtml( $payload );
    }
}
