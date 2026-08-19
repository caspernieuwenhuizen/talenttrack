<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigSnapshotService;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * ConfigJsonExporter (#2540) — the academy's current configuration as a
 * portable JSON file: every setting, plus which modules and features are
 * switched on or off.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/config_json?format=json`
 *   No filters — the snapshot is the whole configuration for the current
 *   club or it is not useful.
 *
 * Cap: `tt_edit_settings` — whoever may change the configuration may read
 * it back out. Deliberately not a weaker view-only cap: the payload spans
 * every settings area at once, so it should not be reachable by someone
 * who can only read one of them.
 *
 * Thin by design. The snapshot is assembled by `ConfigSnapshotService`
 * (CLAUDE.md §4) so the rendered download and any future SaaS consumer
 * get an identical answer; this class only adapts it to the export
 * pipeline.
 *
 * Secret handling lives in the service, not here: `tt_config` holds
 * integration credentials (Strava app secret, Spond password, DeepL API
 * key) and they are redacted before the payload ever reaches a renderer.
 *
 * Uncatalogued in `FeatureRegistry` — like the per-record PDF exporters,
 * and unlike the bulk tiles on the Exports page, it has no per-academy
 * toggle. `ExportService` treats uncatalogued keys as enabled.
 */
final class ConfigJsonExporter implements ExporterInterface {

    public function key(): string { return 'config_json'; }

    public function label(): string { return __( 'Academy configuration (JSON)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'json' ]; }

    public function requiredCap(): string { return 'tt_edit_settings'; }

    /** Non-tabular exporter — opts out of the column picker (#986). */
    public function availableColumns(): array { return []; }

    public function validateFilters( array $raw ): ?array { return []; }

    public function collect( ExportRequest $request ): array {
        return ( new ConfigSnapshotService() )->snapshot();
    }
}
