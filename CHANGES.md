# TalentTrack v3.110.8 — Fifth binary export use case: full club-data backup ZIP (#0063 use case 9)

Per the #0063 spec: "delegates to #0013 (Backup & DR) rather than re-implementing — Export is the public surface; #0013 is the engine." This exporter is intentionally thin: it reuses `BackupSerializer::snapshot()` + `BackupSerializer::toGzippedJson()` (the same engine the on-screen `?page=tt-backup` operator dashboard uses) and packages the result through the v3.110.0 `ZipRenderer`.

## What landed

### `BackupZipExporter` (`exporter_key = backup_zip`, use case 9)

URL pattern:
`GET /wp-json/talenttrack/v1/exports/backup_zip?format=zip&preset=standard`

**Filters**:

- `preset` (optional) — `minimal` / `standard` / `thorough` (default: `standard`). The Backup module's `custom` preset is intentionally not exposed at the route — `custom` reads operator-saved selected-tables from `BackupSettings` and is best driven from the existing admin page; the export route accepts the three named presets and falls back to `standard` on any unrecognized value.

**Cap**: `tt_manage_backups` — same gate as the on-screen Backup admin page.

**Layout**: the ZIP carries one entry: the gzipped-JSON snapshot, named per `BackupSerializer::filename( $preset )` so a snapshot pulled via the export route is interchangeable with one pulled via the Backup admin page — operators can restore through either surface without filename surprises. The `ZipRenderer` also emits a `MANIFEST.json` carrying the snapshot metadata (preset, table list, schema version, plugin version, created-at, checksum) so a downstream consumer can confirm what's in the box without opening the gzip.

**Module wiring**: registered in `ExportModule::boot()` alongside the v3.105.0 / v3.109.0 / v3.110.0 / v3.110.4 / v3.110.5 / v3.110.6 / v3.110.7 entries. Foundation now at 10 of 15 use cases live.

## What's NOT in this PR

- **The `?page=tt-backup` admin page stays in place** — this exporter is additive, opening the same artifact up to the central Export module so future surfaces (Comms attachment that emails the link, scheduled batch hook, external-system poll) can consume it through the standard pipeline.
- **`custom` preset over the route** — operators who want fine-grained table selection use the admin page's settings + on-demand button; the export route is for the named presets.
- **Async dispatch** — synchronous-only via the standard REST stream-and-exit. If a real club's full snapshot grows past the synchronous-export comfort zone, this exporter is the natural first consumer of the deferred Action-Scheduler async pipeline (per the v3.110.0 plan); for typical pilot-scale data the synchronous path fits comfortably under the 30-second request window.
- **The 5 remaining deferred Export use cases** (4 match-day team sheet, 6 multi-sheet evaluations XLSX, 8 methodology session-plan PDF, 10 GDPR subject-access ZIP, 15 demo-data export Excel).

## Notes

- Zero new operator-facing strings — exporter label uses an existing `__()` pattern; the snapshot body is JSON-encoded data with no user-visible copy surface.
- No new migrations.
- No composer dependency changes.
- Renumbered v3.110.8 against any parallel-agent ship that took a v3.110.x slot during build.
