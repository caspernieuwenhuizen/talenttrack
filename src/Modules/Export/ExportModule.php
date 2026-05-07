<?php
namespace TT\Modules\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Export\Format\FormatRendererRegistry;
use TT\Modules\Export\Format\Renderers\CsvRenderer;
use TT\Modules\Export\Format\Renderers\IcsRenderer;
use TT\Modules\Export\Format\Renderers\JsonRenderer;
use TT\Modules\Export\Exporters\AttendanceRegisterCsvExporter;
use TT\Modules\Export\Exporters\GoalsCsvExporter;
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
        // Register the v1 format renderers. Per-use-case PDF / XLSX /
        // ZIP renderers register from their owning module so we don't
        // pay the dependency-import cost upfront.
        FormatRendererRegistry::register( new CsvRenderer() );
        FormatRendererRegistry::register( new JsonRenderer() );
        FormatRendererRegistry::register( new IcsRenderer() );

        // First v1 use case to prove the foundation end-to-end.
        // Other use cases (player evaluation PDF, GDPR ZIP, etc.)
        // land in subsequent ships and register themselves from
        // their owning modules.
        ExporterRegistry::register( new TeamIcalExporter() );

        // v3.109.0 — three CSV use cases lifted from the spec's v1
        // priority list (use cases 3, 5, 7). Pure-SQL exporters that
        // exercise the existing `CsvRenderer` end-to-end and prove
        // the foundation handles real production data shapes. They
        // live here rather than in their owning Players / Activities /
        // Goals modules because the readers are deliberately small
        // and the registration line is cheaper than a six-line shell
        // module update each. Future use cases that need owning-module
        // state (e.g. cycle-aware PDP exports) will register from
        // their owning module.
        ExporterRegistry::register( new PlayersListCsvExporter() );
        ExporterRegistry::register( new AttendanceRegisterCsvExporter() );
        ExporterRegistry::register( new GoalsCsvExporter() );

        ExportRestController::init();
    }
}
