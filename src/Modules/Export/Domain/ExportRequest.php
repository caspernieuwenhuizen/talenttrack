<?php
namespace TT\Modules\Export\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ExportRequest (#0063) — value object describing a single export
 * invocation. Carries everything `ExportService` needs to resolve the
 * exporter, render the format, and audit the result.
 *
 * Immutable. Created by REST controllers / admin handlers and passed
 * through to `ExporterInterface::collect()` and `FormatRendererInterface::render()`.
 *
 * `entityId` carries the per-use-case scope: a player_id for player
 * exports, team_id for team exports, season_id for season exports.
 * Null when the use case is club-wide (e.g. demo round-trip).
 *
 * `filters` is per-exporter — the exporter declares the keys it
 * accepts and validates them on its own.
 */
final class ExportRequest {

    public function __construct(
        public string $exporterKey,            // 'players_csv', 'team_ical', 'pdp_pdf', …
        public string $format,                 // 'csv' / 'json' / 'ics' / 'pdf' / 'xlsx' / 'zip'
        public int $clubId,                    // CurrentClub::id() at request time
        public int $requesterUserId,           // who invoked
        public ?int $entityId = null,          // optional per-use-case scope id
        public array $filters = [],            // <string, mixed> exporter-validated
        public ?string $brandKitMode = null,   // 'auto' (default) / 'blank' / 'letterhead'
        public ?string $locale = null          // override locale; null => requester's
    ) {}
}
