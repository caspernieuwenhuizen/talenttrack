<?php
namespace TT\Modules\Export\Format\Renderers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Domain\ExportResult;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\Format\FormatRendererInterface;

/**
 * PdfRenderer (#0063) — DomPDF-backed PDF output. Per spec Q1 lean
 * (locked at v3.105.0): "DomPDF default + wkhtml escape hatch for clubs
 * that want it." This renderer is the default; an alternative renderer
 * registered behind a `tt_pdf_renderer` filter could swap in
 * wkhtmltopdf for clubs whose hosting permits the binary.
 *
 * Payload shape — one of two forms:
 *
 *   1. Plain HTML string — rendered as the document body.
 *   2. `[ 'html' => '<...>', 'options' => [ 'paper' => 'A4' | 'Letter',
 *      'orientation' => 'portrait' | 'landscape' ] ]`.
 *
 * The renderer wraps the payload's HTML in a minimal `<html><head><style>`
 * shell with the club brand inheriting via the `tt_pdf_render_html`
 * filter (use cases that want brand-kit headers prepend their letterhead
 * markup; brand-kit *automatic* template inheritance is deferred until
 * the PDF renderer earns it across multiple use cases).
 *
 * Self-gates on DomPDF. Composer ships it as a production dependency;
 * the class_exists check exists so a dev install that skipped composer
 * install gets a `no_renderer` 500 instead of a fatal.
 */
final class PdfRenderer implements FormatRendererInterface {

    public function format(): string { return 'pdf'; }

    public function render( ExportRequest $request, $payload ): ExportResult {
        if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
            throw new ExportException(
                'no_renderer',
                'DomPDF not available — composer install needed'
            );
        }

        [ $html, $paper, $orientation ] = self::extract( $payload );

        $html = (string) apply_filters( 'tt_pdf_render_html', self::wrap( $html ), $request );

        $opts = new \Dompdf\Options();
        $opts->set( 'isHtml5ParserEnabled', true );
        $opts->set( 'isRemoteEnabled', false ); // never fetch remote assets at render time
        $opts->set( 'defaultFont', 'DejaVu Sans' );

        $dompdf = new \Dompdf\Dompdf( $opts );
        $dompdf->loadHtml( $html, 'UTF-8' );
        $dompdf->setPaper( $paper, $orientation );
        $dompdf->render();

        $bytes = (string) $dompdf->output();

        $filename = $request->exporterKey . '-' . gmdate( 'Y-m-d' ) . '.pdf';
        return ExportResult::fromString( $bytes, 'application/pdf', $filename );
    }

    /**
     * @return array{0:string,1:string,2:string}  [ html, paper, orientation ]
     */
    private static function extract( $payload ): array {
        if ( is_string( $payload ) ) {
            return [ $payload, 'A4', 'portrait' ];
        }
        if ( is_array( $payload ) ) {
            $html = isset( $payload['html'] ) ? (string) $payload['html'] : '';
            $opts = isset( $payload['options'] ) && is_array( $payload['options'] ) ? $payload['options'] : [];
            $paper = isset( $opts['paper'] ) ? (string) $opts['paper'] : 'A4';
            $orient = isset( $opts['orientation'] ) ? (string) $opts['orientation'] : 'portrait';
            $orient = in_array( $orient, [ 'portrait', 'landscape' ], true ) ? $orient : 'portrait';
            return [ $html, $paper, $orient ];
        }
        return [ '', 'A4', 'portrait' ];
    }

    private static function wrap( string $html ): string {
        // If the caller already supplied a full document (<html> root),
        // keep it as-is; otherwise wrap with a minimal shell.
        if ( stripos( $html, '<html' ) !== false ) return $html;
        $css = 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11pt;color:#222;line-height:1.4;}'
             . 'h1,h2,h3{color:#111;margin:0 0 .4em;}'
             . 'table{border-collapse:collapse;width:100%;}'
             . 'th,td{border:1px solid #ccc;padding:6px 8px;text-align:left;vertical-align:top;}'
             . 'th{background:#f4f4f4;}';
        return '<!doctype html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>'
             . $html
             . '</body></html>';
    }
}
