# TalentTrack v3.106.1 — Reporting export and scheduled reports (#0083 Child 6, closes #0083)

Sixth and final child of #0083 (Reporting framework). **Closes the epic.** Operationalises analytics for users who want their numbers regularly: schedule a KPI to run weekly / monthly / season-end, attach the CSV, send via email.

Renumbered v3.105.1 → v3.106.1 mid-rebase after the parallel-agent ship of v3.106.0 (#0066 Communication module foundation) landed.

## Co-existence with #0063 Export foundation + #0066 Comms foundation

v3.105.0 shipped the `Modules\Export\` foundation; v3.106.0 shipped the `Modules\Comms\` foundation. This PR's `Modules\Analytics\Export\CsvExporter` is a thin direct-to-`FactQuery` consumer for the scheduled-reports cron — it deliberately bypasses the #0063 ExporterRegistry today to keep the scheduled-reports loop closed without depending on #0063 follow-up shaping.

A future ship re-routes analytics CSV through `Modules\Export\ExporterRegistry` (so both paths share one renderer) and re-routes the scheduled-report email send through `Modules\Comms\CommsService` (so opt-out + quiet-hours + audit apply uniformly). For Child 6 v1, the simpler direct path keeps the spec promise (closes #0083) without forcing a deeper integration sprint.

## What landed

### `Modules\Analytics\Export\CsvExporter`

UTF-8-with-BOM CSV renderer (Excel-NL friendly opens correctly without an encoding pick). Two entry points:

- `forKpi( $kpi_key, $extra_filters )` — uses the KPI's fact + measure + default filters merged with extras, grouped by every `exploreDimension`.
- `raw( $fact_key, $dim_keys, $measure_keys, $filters, $title )` — for the explorer's "Export this view" affordance.

Streams via `fputcsv` to a memory stream. Capped at the engine's 5,000-row LIMIT.

### Migration `0075_scheduled_reports`

`tt_scheduled_reports` table per the spec — `club_id` + `uuid` for SaaS-readiness, `name` + `kpi_key`, `frequency` (`weekly_monday` / `monthly_first` / `season_end`), `recipients` JSON, `format` (`csv` v1), `last_run_at` + `next_run_at` + `status`, audit columns.

### `ScheduledReportsRepository`

CRUD + `dueForRun()` cron consumer + `markRun()` + pure `computeNextRun()`:
- `weekly_monday` → next Monday 06:00 UTC.
- `monthly_first` → first day of next month 06:00 UTC.
- `season_end` → 1 July 06:00 UTC.

### `Cron\ScheduledReportsRunner`

Daily WP-cron `tt_scheduled_reports_cron`. Renders CSV, expands recipients (email strings pass through, role keys expand via `get_users()`), sends via `wp_mail()` with the file attached, audit-logs `scheduled_report.run`. License-gated.

### `?tt_view=scheduled-reports` — management view

Cap-gated on `tt_view_analytics`. License-gated via `LicenseGate::allows('scheduled_reports')`; free-tier operators see the standard `UpgradeNudge` paywall. Create form + list with Pause / Resume / Archive actions.

### `Admin\ScheduledReportsActionHandlers`

Four admin-post endpoints (create / pause / resume / archive).

### License feature `scheduled_reports`

Registered in `FeatureMap::DEFAULT_MAP[TIER_STANDARD]`.

### Wiring

Slug ownership + dispatch arm + `mobile_class = desktop_only` all registered.

## What's NOT in this PR

- **Explorer "Export CSV" button** — `CsvExporter::raw()` is callable today; UI surface follows.
- **XLSX + PDF formats.** CSV-only v1.
- **Explorer-state-as-schedule.** Today only KPI-direct schedules.
- **Per-schedule edit form.**
- **#0063 `ExporterRegistry` integration.** Future ship re-routes via the central renderer pipeline.
- **#0066 `CommsService` integration.** Future ship re-routes the email send through the Comms orchestrator.

## Affected files

- `database/migrations/0075_scheduled_reports.php` — new.
- `src/Modules/Analytics/ScheduledReportsRepository.php` — new.
- `src/Modules/Analytics/Export/CsvExporter.php` — new.
- `src/Modules/Analytics/Cron/ScheduledReportsRunner.php` — new.
- `src/Modules/Analytics/Frontend/FrontendScheduledReportsView.php` — new.
- `src/Modules/Analytics/Admin/ScheduledReportsActionHandlers.php` — new.
- `src/Modules/Analytics/AnalyticsModule.php` — `boot()` wires action handlers + cron runner.
- `src/Shared/Frontend/DashboardShortcode.php` — dispatch arm.
- `src/Shared/CoreSurfaceRegistration.php` — slug ownership + `mobile_class = desktop_only`.
- `src/Modules/License/FeatureMap.php` — `scheduled_reports` feature in the Standard tier.
- `languages/talenttrack-nl_NL.po` — 26 new msgids.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## #0083 — closed

Six children shipped:
1. **v3.104.1 — Fact registry**
2. **v3.104.2 — KPI platform**
3. **v3.104.3 — Dimension explorer**
4. **v3.104.4 — Entity Analytics tab**
5. **v3.104.5 — Central analytics surface**
6. **v3.106.1 — Export and schedule** (this PR)

The cost of the next analytical question drops to zero — same affordances every time.
