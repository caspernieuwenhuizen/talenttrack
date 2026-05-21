# TalentTrack v4.2.0 — Expected-vs-actual attendance schema + reporting sweep (partial #788, ship 1)

## Pilot ask

> investigate the option to also have expected attendance maintained for activities when they are planned … If a player is planned absent and with a specific reason that should be taken along in the initial step of attendance marking the completed activity.

This is the **first** of two ships under #788. Ship 1 lays the schema + makes every read-side surface defensive **before** ship 2 introduces the wizard mode that writes `expected` rows for planned activities — the moment that write path goes live, any surface that hadn't been updated would silently conflate "planned" and "actual" rows.

## Changes

### Schema — migration 0121

```sql
ALTER TABLE tt_attendance
    ADD COLUMN record_type ENUM('expected','actual') NOT NULL DEFAULT 'actual';
ALTER TABLE tt_attendance
    ADD INDEX idx_activity_record_type (activity_id, record_type);
```

`DEFAULT 'actual'` keeps every existing row semantically correct — pre-migration rows describe what already happened, so they're `actual`. The covering index speeds up the new `WHERE activity_id = ? AND record_type = 'actual'` predicates added throughout the read-side sweep.

Idempotent (`SHOW COLUMNS` / `SHOW INDEX` guards).

### Read-side sweep

Every SELECT that summarises attendance now filters `record_type = 'actual'`, and where it didn't already, also `plan_state = 'completed'` so completed-activity views can't surface planned rows. Files touched:

| File | Description |
|---|---|
| `src/Modules/Reports/PlayerReportRenderer.php` | Player report's attendance section — full filter. |
| `src/Infrastructure/PlayerStatus/PlayerAttendanceCalculator.php` | Player-status engine attendance score. |
| `src/Modules/PersonaDashboard/Kpis/AttendancePctRolling.php` | Academy-wide rolling attendance %. |
| `src/Modules/PersonaDashboard/Kpis/MyTeamAttendancePct.php` | Coach dashboard KPI. |
| `src/Modules/PersonaDashboard/Repositories/TeamOverviewRepository.php` | HoD team-overview (both `summariesFor` and `teamPlayerBreakdown`). |
| `src/Modules/PersonaDashboard/Widgets/TeamRosterTableWidget.php` | HoD roster attendance % column. |
| `src/Modules/Export/Exporters/AttendanceRegisterCsvExporter.php` | Bulk CSV register. |
| `src/Modules/Analytics/Frontend/FrontendAttendanceTeamReportView.php` | Team attendance report. |
| `src/Modules/Analytics/Frontend/FrontendAttendancePlayerReportView.php` | Player attendance report. |
| `src/Modules/Export/Exporters/TeamRosterStatsCsvExporter.php` | v4.0.11 exporter — attendance + minutes subqueries. |
| `src/Modules/Export/Exporters/TeamActivitiesCsvExporter.php` | v4.0.11 exporter — per-activity attendance count. |
| `src/Modules/Export/Exporters/KpiSnapshotXlsxExporter.php` | v4.0.11 exporter — attendance KPIs. |

All edits are surgical — one or two added clauses per query. Mechanical to review.

### Surfaces deferred to ship 2

- `MarkAttendanceWizard` dual-mode (planned vs completed entry).
- `AttendanceStep` carry-forward (pre-fill `actual` from existing `expected` rows when entering completed-mode).
- New planning surfaces: hero variant, page-header action, expected-absent pill on the activity list.
- `FrontendPlayerDetailView::renderActivitiesTab` expected-vs-actual visual cue.
- `FrontendActivitiesManageView::renderAttendanceSummary` mode-by-plan-state.

### Notifications / write paths

- `AttendanceFlagTemplate` and the write-side wizards (`MarkAttendanceWizard`, `RateConfirmStep`, `Evaluation\AttendanceStep`) are unchanged in ship 1 — they only write `record_type = 'actual'` today (the column default), which is still correct because no `expected` rows exist yet.

## Versioning

Minor bump (4.1.7 → 4.2.0). New feature epic — the planned-attendance behaviour. Even though no UI changes yet, the schema is the platform-level API change that ship 2 builds on.

## Verification

- After migration: existing data + every surface returns the same numbers as before (every pre-existing row is `record_type = 'actual'` by default).
- Insert a synthetic `record_type = 'expected'` row by hand: it should NOT appear in any of the swept surfaces (player report, KPIs, exports, etc.).
- Drop a synthetic `expected` row on a `plan_state = 'scheduled'` activity: every summary surface still reports zero attendance for that activity (ship 2 will surface them in their own views).

## Closes

- Partial: #788 — Expected attendance on planned activities. Ship 1 of two; ship 2 (wizard + planning surfaces) follows in a separate PR.
