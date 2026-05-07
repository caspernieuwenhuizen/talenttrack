# TalentTrack v3.105.1 — Reporting export and scheduled reports (#0083 Child 6, closes #0083)

Sixth and final child of #0083 (Reporting framework). **Closes the epic.** Operationalises analytics for users who want their numbers regularly: schedule a KPI to run weekly / monthly / season-end, attach the CSV, send via email.

Renumbered v3.104.6 → v3.105.1 mid-rebase after the parallel-agent ship of v3.105.0 (#0063 Export module foundation + Team iCal feed) landed.

## Co-existence with #0063 Export foundation

v3.105.0 shipped `Modules\Export\` with its own `CsvRenderer` / `JsonRenderer` / `IcsRenderer` and `ExporterRegistry`. This PR ships `Modules\Analytics\Export\CsvExporter` — a thinner consumer of `FactQuery::run()` that produces CSV directly. They live alongside without conflict:

- The #0063 Export module is the central authority for outbound data artefacts that have a real consumer outside analytics — Team iCal feed (use case 12, shipped v3.105.0); player evaluation PDF (use case 1, follow-up); GDPR ZIP (use case 10, future); etc.
- The analytics CSV is consumed by the daily scheduled-reports cron and (in a follow-up) the explorer's "Export this view" button.
- A future ship re-routes the analytics CSV through the #0063 `ExporterRegistry` so both paths share one renderer; today's split avoids blocking #0083 closure on a deeper #0063 integration.

## What landed

### `Modules\Analytics\Export\CsvExporter`

UTF-8-with-BOM CSV renderer (Excel-NL friendly opens correctly without an encoding pick). Two entry points:

- `forKpi( $kpi_key, $extra_filters )` — uses the KPI's fact + measure + default filters merged with extras, grouped by every `exploreDimension` so the export carries the full breakdown.
- `raw( $fact_key, $dim_keys, $measure_keys, $filters, $title )` — bypasses KPI metadata for the explorer's "Export this view" affordance (callable today; UI surface in a follow-up).

Streams via `fputcsv` to a memory stream. Capped at the engine's 5,000-row LIMIT. Optional title row above the headers carries the KPI label and the UTC generation timestamp.

### Migration `0075_scheduled_reports`

`tt_scheduled_reports` table per the spec — `club_id` + `uuid` for SaaS-readiness, `name` + `kpi_key`, `frequency` (`weekly_monday` / `monthly_first` / `season_end`), `recipients` JSON, `format` (`csv` v1), `last_run_at` + `next_run_at` + `status`, audit columns. Idempotent CREATE TABLE IF NOT EXISTS.

### `ScheduledReportsRepository`

CRUD + `dueForRun()` cron consumer + `markRun()` + pure `computeNextRun()`:
- `weekly_monday` → next Monday 06:00 UTC.
- `monthly_first` → first day of next month 06:00 UTC.
- `season_end` → 1 July 06:00 UTC (Northern-hemisphere convention).

### `Cron\ScheduledReportsRunner`

Daily WP-cron `tt_scheduled_reports_cron`. Iterates `dueForRun()`, renders CSV, expands recipients (email strings pass through, role keys expand via `get_users()`), writes a temp file in the WP uploads dir, sends via `wp_mail()` with the file attached, deletes the temp file, audit-logs `scheduled_report.run`. License-gated — silently skips when the tier doesn't have `scheduled_reports`.

### `?tt_view=scheduled-reports` — management view

Cap-gated on `tt_view_analytics` (HoD + Admin by default). License-gated via `LicenseGate::allows('scheduled_reports')`; Free tier sees the standard `UpgradeNudge` inline paywall.

- **Create form** — name + KPI dropdown + frequency + recipients textarea (one per line).
- **Schedule list** — name + KPI label + frequency + next-run timestamp + status.
- **Per-row actions** — Pause / Resume / Archive.

Edit form deferred (operators pause + recreate).

### `Admin\ScheduledReportsActionHandlers`

Four admin-post endpoints: `tt_scheduled_reports_create` / `_pause` / `_resume` / `_archive`. All gated on `tt_view_analytics`.

### License feature `scheduled_reports`

Registered in `FeatureMap::DEFAULT_MAP[TIER_STANDARD]` so Standard / Trial / Pro tiers have it; Free is gated.

### Wiring

- **Slug ownership** `scheduled-reports` → `AnalyticsModule`.
- **Dispatch arm** `analytics_schedules_slugs = ['scheduled-reports']` in `DashboardShortcode::render()`.
- **`mobile_class = desktop_only`** — phone-class user agents see the #0084 Child 1 polite-prompt page.

## What's NOT in this PR

- **Explorer "Export CSV" button.** `CsvExporter::raw()` is callable today; UI surface follows.
- **XLSX + PDF formats.** CSV-only v1.
- **Explorer-state-as-schedule.** Today only KPI-direct schedules.
- **Per-schedule edit form.** Pause + recreate is the v1 work-around.
- **#0063 ExporterRegistry integration.** Future ship re-routes the analytics CSV through #0063's renderer pipeline.

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

## Translations

26 new translatable strings.

## #0083 — closed

Six children shipped:

1. **v3.104.1 — Fact registry** (Child 1).
2. **v3.104.2 — KPI platform** (Child 2).
3. **v3.104.3 — Dimension explorer** (Child 3).
4. **v3.104.4 — Entity Analytics tab** (Child 4).
5. **v3.104.5 — Central analytics surface** (Child 5).
6. **v3.105.1 — Export and schedule** (Child 6, this PR).

Bulk migration of legacy 26 KPIs + the remaining 49 spec KPIs + the explorer's chart + drilldown + export-button follow as separate ships; the platform that hosts them is closed.
