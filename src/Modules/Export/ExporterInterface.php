<?php
namespace TT\Modules\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;

/**
 * ExporterInterface (#0063) — one exporter per use case.
 *
 * Use cases (15 total in the spec): players_csv, attendance_csv,
 * goals_csv, evaluations_xlsx, pdp_pdf, scout_report_pdf, team_ical,
 * federation_json, gdpr_subject_zip, etc. Each is a discrete
 * ExporterInterface implementation registered in `ExporterRegistry`
 * by its key.
 *
 * The exporter:
 *   - declares which formats it supports (`supportedFormats()`),
 *   - declares which capability gates it (`requiredCap()`),
 *   - validates the per-request filters (`validateFilters()`),
 *   - produces the payload (`collect()`) — shape is renderer-aware
 *     per the convention in `FormatRendererInterface`.
 *
 * `ExportService` orchestrates: cap-gate → validate → collect → render
 * → audit. Exporters never speak HTTP themselves; the REST controller
 * does that.
 */
interface ExporterInterface {

    /** Stable key, used as the URL slug and audit-log discriminator. */
    public function key(): string;

    /** Human-readable label (translatable). */
    public function label(): string;

    /**
     * Formats this exporter can produce.
     *
     * Most exporters target one format. Multi-format exporters declare
     * each one and the renderer convention determines payload shape.
     *
     * @return string[]   subset of `FormatRendererRegistry::formats()`
     */
    public function supportedFormats(): array;

    /**
     * Capability that gates this exporter. Checked by `ExportService`
     * before `collect()` runs. Use `tt_export_*` per the spec.
     */
    public function requiredCap(): string;

    /**
     * Validate + normalise the per-request filters. Return the
     * sanitised array; throw or return `null` on invalid input
     * (the controller maps that to 400).
     *
     * @return array<string,mixed>|null
     */
    public function validateFilters( array $raw ): ?array;

    /**
     * Build the renderer-aware payload for this request. Return shape
     * depends on the format: CSV expects `[ headers, rows ]`, ICS
     * expects `[ events ]`, JSON accepts any associative array.
     *
     * @return array<string,mixed>
     */
    public function collect( ExportRequest $request ): array;
}
