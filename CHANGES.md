# TalentTrack v3.104.6 — Reporting export and scheduled reports (#0083 Child 6, closes #0083)

Sixth and final child of #0083 (Reporting framework). **Closes the epic.** Operationalises analytics for users who want their numbers regularly: schedule a KPI to run weekly / monthly / season-end, attach the CSV, send via email.

## What landed

### `Modules\Analytics\Export\CsvExporter`

UTF-8-with-BOM CSV renderer (Excel-NL friendly opens correctly without an encoding pick). Two entry points:

- `forKpi( $kpi_key, $extra_filters )` — uses the KPI's fact + measure + default filters merged with extras, grouped by every `exploreDimension` so the export carries the full breakdown.
- `raw( $fact_key, $dim_keys, $measure_keys, $filters, $title )` — bypasses KPI metadata for the explorer's "Export this view" affordance (callable today; UI surface in a follow-up).

Streams via `fputcsv` to a memory stream. Capped at the engine's 5,000-row LIMIT — larger exports defer to the async pipeline of a future sprint. Optional title row above the headers carries the KPI label and the UTC generation timestamp.

### Migration `0075_scheduled_reports`

`tt_scheduled_reports` table with the documented schema:

| Column | Notes |
|---|---|
| `club_id`, `uuid` | per CLAUDE.md §4 SaaS-readiness |
| `name`, `kpi_key` | required v1; `explorer_state_json` reserved for the explorer-state save flow shipping after |
| `frequency` | `weekly_monday` / `monthly_first` / `season_end` |
| `recipients` | JSON: list of email addresses or WordPress role keys |
| `format` | `csv` (v1) — XLSX / PDF defer |
| `last_run_at`, `next_run_at`, `status` | cron consumer reads `(status='active', next_run_at <= NOW())` |

Idempotent CREATE TABLE IF NOT EXISTS via dbDelta.

### `ScheduledReportsRepository`

CRUD + `dueForRun()` cron consumer + `markRun()` that re-stamps `last_run_at` + `next_run_at` after each run. `computeNextRun()` is a pure function:

- `weekly_monday` → next Monday 06:00 UTC.
- `monthly_first` → first day of next month 06:00 UTC.
- `season_end` → 1 July 06:00 UTC (Northern-hemisphere convention; rolls to next year if past).

### `Cron\ScheduledReportsRunner`

Registers a daily WP-cron `tt_scheduled_reports_cron`. The hook handler iterates `dueForRun()`, renders each as CSV via `CsvExporter::forKpi()`, expands recipients (email strings pass through; role keys like `tt_head_dev` expand via `get_users()`), writes the CSV to a temp file in the WP uploads dir, sends via `wp_mail()` with the file attached, deletes the temp file, audit-logs `scheduled_report.run` with the schedule id + recipient count + success flag.

**License-gated**: when the tier doesn't have `scheduled_reports`, the runner silently skips — operators see their definitions but no emails go out. Trial / Standard / Pro tiers run normally.

### `?tt_view=scheduled-reports` — management view

Cap-gated on `tt_view_analytics` (HoD + Admin by default — same gate as the central analytics view in Child 5). License-gated via `LicenseGate::allows('scheduled_reports')`; free-tier operators see the standard `UpgradeNudge` inline paywall.

- **Create form** — name + KPI dropdown + frequency + recipients textarea (one per line). Submits via `tt_scheduled_reports_create` admin-post; recipients split on newlines, validated (emails pass `is_email()`, others treated as role keys).
- **Schedule list** — name + KPI label + frequency + next-run timestamp (UTC) + status, with per-row Pause / Resume / Archive actions wired to corresponding admin-post endpoints. Archive prompts `confirm()` before submit.
- **One-shot success messages** — `tt_msg=schedule_created` / `_paused` / `_resumed` / `_archived`.

Edit form deferred (operators pause + recreate; the rare case where a schedule's recipients change before the schedule itself does).

### `Admin\ScheduledReportsActionHandlers`

Four admin-post endpoints: `tt_scheduled_reports_create` / `_pause` / `_resume` / `_archive`. All gated on `tt_view_analytics`.

### License feature `scheduled_reports`

Registered in `FeatureMap::DEFAULT_MAP[TIER_STANDARD]` so Standard / Trial / Pro tiers have it; Free is gated.

### Wiring

- **Slug ownership** `scheduled-reports` → `AnalyticsModule` in `CoreSurfaceRegistration::registerSlugOwnerships()` for the module-disabled friendly notice.
- **Dispatch arm** `analytics_schedules_slugs = ['scheduled-reports']` added in `DashboardShortcode::render()`.
- **`mobile_class = desktop_only`** registered — phone-class user agents see the `#0084` Child 1 polite-prompt page.

## What's NOT in this PR

- **Explorer "Export CSV" button.** `CsvExporter::raw()` is callable today but the explorer view doesn't surface an Export button. UI surface follows.
- **XLSX + PDF formats.** Spec asks for all three; CSV ships v1, the other two follow when there's a real consumer asking for them.
- **Explorer-state-as-schedule.** Today only KPI-direct schedules. The `explorer_state_json` column is reserved for the save flow shipping after.
- **Per-schedule edit form.** Pause + recreate is the work-around for v1.

## Affected files

- `database/migrations/0075_scheduled_reports.php` — new (tt_scheduled_reports table).
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

26 new translatable strings covering the management-view UI, the email subject + body, and the frequency labels.

## #0083 — closed

Six children shipped:

1. **v3.104.1 — Fact registry** (Child 1): `Modules\Analytics\Domain\{Fact, Dimension, Measure, DateTimeColumn}` + `FactRegistry` + `FactQuery::run()` + 8 initial fact registrations.
2. **v3.104.2 — KPI platform** (Child 2): `Domain\Kpi` + `KpiRegistry` + `KpiResolver` + 6 reference KPIs.
3. **v3.104.3 — Dimension explorer** (Child 3): `?tt_view=explore` + filter chips + group-by selector + threshold flagging.
4. **v3.104.4 — Entity Analytics tab** (Child 4): tab on player profiles surfacing player-scoped KPIs with click-through to the explorer.
5. **v3.104.5 — Central analytics surface** (Child 5): `?tt_view=analytics` + new `analytics` matrix entity + `tt_view_analytics` cap + top-up migration 0074.
6. **v3.104.6 — Export and schedule** (Child 6, this PR): CSV exporter + `tt_scheduled_reports` table + management view + daily cron + `scheduled_reports` license feature.

The cost of the next analytical question drops to zero — same affordances every time. Bulk migration of legacy 26 KPIs + the remaining 49 spec KPIs follow as separate ships; the platform that hosts them is closed.

## Player-centricity

The reporting framework's six-child arc is the answer to "every aggregation is one-off." A coach asking "how does my team's attendance compare across last three seasons" lands on the entity tab (Child 4), pivots in the explorer (Child 3), drops into the central view for academy-wide context (Child 5), and schedules a weekly digest for the head coach (Child 6) — all on the same fact registry (Child 1) + KPI platform (Child 2). Every step earns its place by serving a question about the player's journey through the academy.
