<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Backup\BackupSerializer;
use TT\Modules\Backup\PresetRegistry;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * BackupZipExporter (#0063 use case 9) — full club-data export ZIP.
 *
 * Per the #0063 spec: "delegates to #0013 (Backup & DR) rather than
 * re-implementing — Export is the public surface; #0013 is the
 * engine." This exporter is intentionally thin: it pulls a snapshot
 * from `BackupSerializer::snapshot()`, gzips it via the existing
 * `toGzippedJson()`, wraps the bytes in a ZIP via the v3.110.0
 * `ZipRenderer`, and lets the standard exporter pipeline stream it.
 *
 * The Backup module's existing on-demand path (`?page=tt-backup`)
 * stays in place for the operator-facing dashboard; this exporter
 * opens the same artifact up to the central Export module so future
 * surfaces (a Comms attachment that emails the link, a scheduled
 * batch hook, an external-system poll) can consume it through the
 * standard pipeline rather than going through the Backup admin page.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/backup_zip?format=zip&preset=standard`
 *   filters:
 *     `preset` (optional) — `minimal` / `standard` / `thorough` /
 *                           `custom` (default: `standard`).
 *                           `custom` would respect the operator-saved
 *                           `BackupSettings::tablesForCurrent()` list
 *                           but the route gate already covers the
 *                           settings cap, so we accept the four named
 *                           presets and let any unrecognized value
 *                           fall back to `standard`.
 *
 * Cap: `tt_manage_backups` — same gate as the on-screen Backup page.
 *
 * The ZIP is streaming-friendly today (single entry, no async needed).
 * If a real club's full snapshot grows past the synchronous-export
 * comfort zone, this exporter is the natural first consumer of the
 * deferred Action-Scheduler async pipeline (per the v3.110.0 plan).
 */
final class BackupZipExporter implements ExporterInterface {

    private const ALLOWED_PRESETS = [
        PresetRegistry::MINIMAL,
        PresetRegistry::STANDARD,
        PresetRegistry::THOROUGH,
    ];

    public function key(): string { return 'backup_zip'; }

    public function label(): string { return __( 'Full club-data backup (ZIP)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'zip' ]; }

    public function requiredCap(): string { return 'tt_manage_backups'; }

    public function validateFilters( array $raw ): ?array {
        $preset = isset( $raw['preset'] ) ? (string) $raw['preset'] : PresetRegistry::STANDARD;
        if ( ! in_array( $preset, self::ALLOWED_PRESETS, true ) ) {
            $preset = PresetRegistry::STANDARD;
        }
        return [ 'preset' => $preset ];
    }

    public function collect( ExportRequest $request ): array {
        $preset = (string) ( $request->filters['preset'] ?? PresetRegistry::STANDARD );

        $tables   = PresetRegistry::tablesFor( $preset );
        $snapshot = BackupSerializer::snapshot( $tables, $preset );
        $bytes    = BackupSerializer::toGzippedJson( $snapshot );

        // The ZIP carries one entry: the gzipped-JSON snapshot. The
        // filename matches BackupSerializer's convention so a snapshot
        // pulled via the export route is interchangeable with one
        // pulled via the Backup admin page — operators can restore
        // through either surface without filename surprises.
        $entry_name = BackupSerializer::filename( $preset );

        // ZipRenderer also emits a MANIFEST.json with the standard
        // meta envelope when manifest entries are supplied. Include
        // a minimal one so a downstream consumer can confirm what's
        // in the box without opening the gzip.
        $manifest = [
            'snapshot_filename' => $entry_name,
            'snapshot_preset'   => $preset,
            'snapshot_tables'   => array_keys( $snapshot['tables'] ?? [] ),
            'snapshot_version'  => (string) ( $snapshot['version'] ?? '' ),
            'plugin_version'    => (string) ( $snapshot['plugin_version'] ?? '' ),
            'created_at'        => (string) ( $snapshot['created_at'] ?? gmdate( 'c' ) ),
            'checksum'          => (string) ( $snapshot['checksum'] ?? '' ),
        ];

        return [
            'entries'  => [ $entry_name => $bytes ],
            'manifest' => $manifest,
        ];
    }
}
