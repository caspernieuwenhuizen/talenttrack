<?php
namespace TT\Modules\Export\Format;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Domain\ExportResult;

/**
 * FormatRendererInterface (#0063) — one renderer per output format.
 *
 * v1 ships CSV, JSON, and ICS (iCal). PDF / XLSX / ZIP renderers land
 * with their first consumers (per-use-case PRs). Each renderer is
 * registered in `FormatRendererRegistry` keyed on its format string;
 * `ExportService` looks up by `ExportRequest::$format`.
 *
 * The exporter (which produces the rows / payload) is decoupled from
 * the renderer (which serialises). Most exporters target one format,
 * but the contract supports multi-format exporters (e.g. evaluations
 * exporter could produce both CSV and Excel from the same row set).
 */
interface FormatRendererInterface {

    /** Format string this renderer claims (e.g. 'csv', 'ics', 'pdf'). */
    public function format(): string;

    /**
     * Render the exporter's payload into the format's bytes.
     *
     * `$payload` shape is exporter-determined and renderer-aware. CSV
     * renderer expects `[ 'headers' => string[], 'rows' => list<list<string>> ]`,
     * ICS renderer expects `[ 'events' => list<array> ]`, JSON renderer
     * accepts any associative array. The exporter and renderer agree
     * on the payload shape via convention; the contract here is loose
     * to keep both sides simple.
     */
    public function render( ExportRequest $request, $payload ): ExportResult;
}
