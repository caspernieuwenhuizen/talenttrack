<?php
namespace TT\Modules\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Export\Format\FormatRendererRegistry;
use TT\Modules\Export\Format\Renderers\CsvRenderer;
use TT\Modules\Export\Format\Renderers\IcsRenderer;
use TT\Modules\Export\Format\Renderers\JsonRenderer;
use TT\Modules\Export\Format\Renderers\PdfRenderer;
use TT\Modules\Export\Format\Renderers\XlsxRenderer;
use TT\Modules\Export\Format\Renderers\ZipRenderer;
use TT\Modules\Export\Exporters\AttendanceRegisterCsvExporter;
use TT\Modules\Export\Exporters\BackupZipExporter;
use TT\Modules\Export\Exporters\DemoDataXlsxExporter;
use TT\Modules\Export\Exporters\EvaluationsXlsxExporter;
use TT\Modules\Export\Exporters\FederationJsonExporter;
use TT\Modules\Export\Exporters\GdprSubjectAccessZipExporter;
use TT\Modules\Export\Exporters\GoalsCsvExporter;
use TT\Modules\Export\Exporters\MatchDayTeamSheetPdfExporter;
use TT\Modules\Export\Exporters\PdpPdfExporter;
use TT\Modules\Export\Exporters\PlayerEvaluationPdfExporter;
use TT\Modules\Export\Exporters\PlayerOnePagerPdfExporter;
use TT\Modules\Export\Exporters\ScoutingReportPdfExporter;
use TT\Modules\Export\Exporters\ActivityBriefPdfExporter;
use TT\Modules\Export\Exporters\PlayersListCsvExporter;
use TT\Modules\Export\Exporters\TeamIcalExporter;
use TT\Modules\Export\Rest\ExportRestController;

/**
 * ExportModule (#0063) — central authority for outbound data artefacts.
 *
 * Foundation ships:
 *   - `Domain\ExportRequest` / `Domain\ExportResult` value objects.
 *   - `Format\FormatRendererInterface` + three v1 renderers (CSV / JSON / iCal).
 *     PDF / XLSX / ZIP renderers register with their first consumer.
 *   - `ExporterInterface` + `ExporterRegistry`. Use-case modules
 *     register their own exporters at boot.
 *   - `ExportService` orchestrator: cap-gate → validate → collect →
 *     render → audit.
 *   - `Rest\ExportRestController` at `/wp-json/talenttrack/v1/exports/{key}`.
 *
 * Open shaping decisions taken from the spec leans (locked at v3.105.0
 * by user direction): DomPDF default + wkhtml escape (Q1, when the PDF
 * renderer lands); Action Scheduler for async (Q2, lands with first
 * async use case); 24h signed-URL TTL (Q3); per-coach iCal token (Q4);
 * neutral-envelope JSON v1 (Q5); async GDPR ZIP (Q6); auto brand kit +
 * per-export override (Q7).
 *
 * Use cases land in subsequent ships, each binding to this foundation
 * via `ExporterRegistry::register()`.
 */
class ExportModule implements ModuleInterface {

    public function getName(): string { return 'export'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        // v1 format renderers (text-based) — always available.
        FormatRendererRegistry::register( new CsvRenderer() );
        FormatRendererRegistry::register( new JsonRenderer() );
        FormatRendererRegistry::register( new IcsRenderer() );

        // v3.110.0 — binary renderers. ZIP is pure PHP and always
        // available; XLSX needs PhpSpreadsheet (production composer
        // dependency); PDF needs DomPDF (production composer dependency).
        // Each renderer self-gates on its dependency at render time, so
        // we always register them — the failure path is a clean
        // `no_renderer` 500 rather than a silent absent format.
        FormatRendererRegistry::register( new ZipRenderer() );
        FormatRendererRegistry::register( new XlsxRenderer() );
        FormatRendererRegistry::register( new PdfRenderer() );

        // Use cases. Pure-SQL CSV / JSON exporters live in this module
        // because the readers are small and the registration line is
        // cheaper than a six-line shell-module update each. Future use
        // cases that need owning-module state (cycle-aware PDP exports,
        // session-plan PDFs, GDPR subject-access ZIP) will register
        // from their owning module.
        ExporterRegistry::register( new TeamIcalExporter() );           // use case 12 (v3.105.0)
        ExporterRegistry::register( new PlayersListCsvExporter() );     // use case 3  (v3.109.0)
        ExporterRegistry::register( new AttendanceRegisterCsvExporter() ); // use case 5  (v3.109.0)
        ExporterRegistry::register( new GoalsCsvExporter() );           // use case 7  (v3.109.0)
        ExporterRegistry::register( new FederationJsonExporter() );     // use case 11 (v3.110.0)
        ExporterRegistry::register( new PlayerEvaluationPdfExporter() ); // use case 1  (v3.110.4)
        ExporterRegistry::register( new PdpPdfExporter() );             // use case 2  (v3.110.5)
        ExporterRegistry::register( new ScoutingReportPdfExporter() );  // use case 14 (v3.110.6)
        ExporterRegistry::register( new PlayerOnePagerPdfExporter() );  // use case 13 (v3.110.7)
        ExporterRegistry::register( new BackupZipExporter() );          // use case 9  (v3.110.8)
        ExporterRegistry::register( new EvaluationsXlsxExporter() );    // use case 6  (v3.110.12)
        ExporterRegistry::register( new ActivityBriefPdfExporter() );    // use case 8  (v3.110.14)
        ExporterRegistry::register( new GdprSubjectAccessZipExporter() ); // use case 10 (v3.110.15)
        ExporterRegistry::register( new DemoDataXlsxExporter() );        // use case 15 (v3.110.16)
        ExporterRegistry::register( new MatchDayTeamSheetPdfExporter() ); // use case 4 (v3.110.17)

        ExportRestController::init();
    }
}
