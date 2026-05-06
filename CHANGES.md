# TalentTrack v3.105.0 — Export module foundation + Team iCal feed (#0063 Foundation + use case 12)

First ship of the #0063 Export epic. Foundation only — registry + three v1 format renderers + REST endpoint + audit hook. One use case (Team iCal feed) lands alongside to prove the foundation end-to-end. Use cases 1–11, 13–15 land in subsequent ships.

## Why bundle Foundation + one use case

Foundation alone is shelfware until a consumer arrives — no surface to demo, no integration test that exercises it. Bundling one use case (the smallest one in the spec — Team iCal) gets the foundation in front of a real query path on day one and validates the end-to-end shape (cap-gate → exporter validation → format renderer → audit) before any of the larger use cases bind to it.

Team iCal was chosen over the other v1 candidates because:
- It exercises the only renderer (`IcsRenderer`) that has no other natural consumer in the existing codebase.
- It's small (~130 LOC exporter + 30 LOC SQL).
- It's the single use case where TalentTrack is the source of truth for the data — every other v1 export depends on records the operator created elsewhere.
- It's already requested by pilot coaches who use Spond and want their TT-only activities in their phone calendar.

## Architecture

### Domain value objects

`Domain\ExportRequest` carries everything an export call needs: exporter key, format, `club_id` (from `CurrentClub::id()`), requester user id, optional entity id (per-use-case scope — player_id for player exports, team_id for team exports), validated filters, brand-kit mode (`auto` / `blank` / `letterhead`), and an optional locale override. Immutable.

`Domain\ExportResult` is the rendered output: bytes, MIME type, filename, size (cached `strlen`), optional renderer note (e.g. "12 rows", "0 events").

### Format renderer registry

`Format\FormatRendererInterface` declares one method beyond `format()`: `render( ExportRequest, $payload ): ExportResult`. Each renderer claims a format string and accepts a renderer-aware payload.

`Format\FormatRendererRegistry` keys on the format string. Same registration pattern as `WidgetRegistry` and `KpiDataSourceRegistry`: static, in-memory, idempotent.

### Three v1 renderers

**`Renderers\CsvRenderer`** — RFC 4180 via native `fputcsv` on a `php://temp` stream. Prepends a UTF-8 BOM so Excel-on-Windows opens UTF-8 CSVs with the right encoding on first try (well-known Excel pain point). Payload shape: `[ headers, rows ]`. Booleans coerce to `1`/`0`, nulls to empty string, everything else stringified.

**`Renderers\JsonRenderer`** — stable envelope around the exporter's payload:

```json
{
  "meta": {
    "exporter": "team_ical",
    "format": "json",
    "club_id": 1,
    "generated_at": "2026-05-06T22:30:00+00:00",
    "tt_version": "3.105.0"
  },
  "data": <whatever the exporter returned>
}
```

Federation JSON (use case 11) ships its neutral envelope on top of this — `meta` is invariant; `data` shape is per-use-case. Encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT`.

**`Renderers\IcsRenderer`** — hand-coded RFC 5545. PRODID + VERSION + CALSCALE + METHOD + N×VEVENT (UID + DTSTAMP + DTSTART + DTEND + SUMMARY + LOCATION + DESCRIPTION). CRLF line endings. 75-octet line folding per § 3.1 (continuation lines start with a single space). iCal text escaping per § 3.3.11 (backslash, semicolon, comma, newline). Two event modes: `VALUE=DATE` all-day with DTEND bumped one day per the exclusive-end rule, or UTC-Z timed events. No Sabre\VObject dependency — pure PHP.

### Exporter registry + service

`ExporterInterface` declares: `key()`, `label()`, `supportedFormats()`, `requiredCap()`, `validateFilters()`, `collect()`. The exporter validates request filters (returns `null` on invalid → 400) and produces a renderer-aware payload (CSV expects `[ headers, rows ]`, ICS expects `[ events ]`, JSON accepts any associative array).

`ExporterRegistry` keys on the exporter's key string. URL slug = key (`/exports/{key}`).

`ExportService::run()` orchestrates: cap-gate → format-supported check → filter validation → renderer lookup → `collect()` → `render()` → audit. `ExportException` short-circuits with one of `unknown_exporter` / `forbidden` / `unsupported_format` / `bad_filters` / `no_renderer`; the controller maps each to an HTTP status (404 / 403 / 400 / 400 / 500).

Audit via `AuditService::record( 'export.generated', 'export', $entity_id, [ exporter, format, club_id, user_id, filename, size, note ] )`. Audit failure never breaks the export.

### REST controller

Two routes under `/wp-json/talenttrack/v1/`:

- `GET /exports` — lists registered exporters that the caller has cap-access to. Returns `{ exporters: [ { key, label, formats, cap }, … ] }`.
- `GET /exports/{key}?format=ics&entity_id=42&...filters` — synchronous download. Bypasses the REST envelope: sets `Content-Type` / `Content-Length` / `Content-Disposition` headers, echoes bytes, exits.

The route gate is `is_user_logged_in()`; per-exporter cap-gating runs inside `ExportService` against `ExporterInterface::requiredCap()`. This keeps the route open to any holder of any `tt_export_*` cap and pushes precise checks into the service.

Async dispatch — `POST /exports/{key}` queuing an Action Scheduler job — lands with the first big-export use case (likely the GDPR subject-access ZIP). The sync GET path covers every v1 use case under the 30 s budget.

## Use case 12 — Team iCal feed

`Exporters\TeamIcalExporter` reads `tt_activities` for one team filtered by `session_date` window (configurable: `months_back` default 1 / `months_ahead` default 12) and emits one VEVENT per activity. Spond-sourced rows (`activity_source_key != 'manual'` per migration 0040) are filtered out so coaches who already sync Spond don't see the same training twice.

UID format: `tt-activity-{id}@{site_host}`. Cap: `tt_view_activities` (same gate as the activities admin).

URL: `GET /wp-json/talenttrack/v1/exports/team_ical?entity_id={team_id}&format=ics`.

Per-coach signed-token URLs (spec Q4 lean — "iCal as a 'secret URL' is a known anti-pattern but the realistic UX") defer to a follow-up "subscribe to this calendar" UI. Today the route is cookie-authed only, suitable for the operator-grade preview while the subscription UX is being shaped.

## Open shaping decisions locked

Per user direction, the in-spec leans on all 7 open Qs become locked decisions for #0063:

- **Q1 PDF engine** — DomPDF default + wkhtml escape hatch. Lands with the first PDF use case (player evaluation PDF, use case 1).
- **Q2 Async runner** — Action Scheduler. Lands with first big-export use case.
- **Q3 Big-export TTL** — 24 h with regenerate. Lands with the async pipeline.
- **Q4 iCal subscription** — signed-token-per-coach. The exporter layer is ready; the subscribe UI lands with a follow-up.
- **Q5 Federation JSON** — neutral envelope v1. Lands with the federation JSON use case.
- **Q6 GDPR subject-access** — async (queued + email link). Lands with the GDPR ZIP use case + Comms #0066.
- **Q7 Brand kit on PDF** — auto inherit + per-export override toggle. Lands with the PDF renderer.

## What's NOT in this PR

- **PDF / XLSX / ZIP renderers** — land with their first consumers. PDF (DomPDF) ships with the player evaluation PDF use case; PhpSpreadsheet (already a Composer dep) ships with the evaluations Excel use case; `ZipArchive` ships with the GDPR subject-access ZIP.
- **Async pipeline + Action Scheduler integration** — sync GET covers every v1 use case under the 30 s budget; async lands when the first big export needs it.
- **14 remaining use cases** (1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 14, 15) — each follows the same registration pattern via its owning module.
- **Brand-kit template inheritance** — only manifests visually on PDF, so lands with the PDF renderer.
- **Per-coach signed-token iCal subscription URLs** — exporter layer is ready; subscription UX is its own follow-up.

## Risk callouts

- **Fact-registry discipline.** A future module that adds a player-related table without registering loses analytics coverage silently. The static-analysis test for "every `tt_*` table with a `player_id` / `team_id` FK is registered (or explicitly opted out via comment)" is part of Child 2's definition of done — it ships next, not here.

## Affected files

- `src/Modules/Export/ExportModule.php` — new (module shell, registers renderers + first exporter + REST controller).
- `src/Modules/Export/Domain/ExportRequest.php` — new.
- `src/Modules/Export/Domain/ExportResult.php` — new.
- `src/Modules/Export/Format/FormatRendererInterface.php` — new.
- `src/Modules/Export/Format/FormatRendererRegistry.php` — new.
- `src/Modules/Export/Format/Renderers/CsvRenderer.php` — new (~70 lines).
- `src/Modules/Export/Format/Renderers/JsonRenderer.php` — new (~50 lines).
- `src/Modules/Export/Format/Renderers/IcsRenderer.php` — new (~150 lines).
- `src/Modules/Export/ExporterInterface.php` — new.
- `src/Modules/Export/ExporterRegistry.php` — new.
- `src/Modules/Export/ExportService.php` — new (~110 lines).
- `src/Modules/Export/ExportException.php` — new.
- `src/Modules/Export/Exporters/TeamIcalExporter.php` — new (~110 lines).
- `src/Modules/Export/Rest/ExportRestController.php` — new (~140 lines).
- `config/modules.php` — registers `ExportModule`.
- `talenttrack.php`, `readme.txt`, `SEQUENCE.md` — version bump + ship metadata.
