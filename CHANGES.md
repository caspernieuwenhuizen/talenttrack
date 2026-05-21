# TalentTrack v4.0.11 — Exports page gains six bulk-export use cases (closes #865)

## Pilot ask

> I also believe that not all export use cases are present?

Pilot completeness audit confirmed: 8 bulk exporters were registered on the Exports page, and 6 obvious data shapes were missing. Shipped together to round out the surface in one go.

## New exporters

All six follow the existing `ExporterInterface` pattern (`key()`, `label()`, `supportedFormats()`, `requiredCap()`, `validateFilters()`, `collect()`), register in `ExportModule::boot()`, and surface as cards on `FrontendExportsView` with form-driven filters.

| Key | Class | Formats | Cap |
|---|---|---|---|
| `player_evaluations` | `PlayerEvaluationsCsvExporter` | csv, xlsx | `tt_view_evaluations` |
| `team_roster_stats`  | `TeamRosterStatsCsvExporter`   | csv, xlsx | `tt_view_players` |
| `team_activities`    | `TeamActivitiesCsvExporter`    | csv, xlsx | `tt_view_activities` |
| `staff_directory`    | `StaffDirectoryCsvExporter`    | csv, xlsx | `tt_view_people` |
| `kpi_snapshot`       | `KpiSnapshotXlsxExporter`      | xlsx      | `tt_view_reports` |
| `audit_log`          | `AuditLogCsvExporter`          | csv       | `tt_manage_settings` |

### Player evaluations (flat)

Flat CSV companion to the existing multi-sheet `evaluations_xlsx`. One row per evaluation; one column per main `tt_eval_categories` with the average across that evaluation's sub-category ratings.

Filters: `team_id` (optional), `date_from` / `date_to` (optional).

### Team roster + season stats

One row per player on the chosen team. Pulls roster fields (`tt_players`) plus three subquery-computed metrics across the date range:

- attendance count (`tt_attendance.status = 'present'`)
- total minutes played (`tt_attendance.minutes_played`)
- average rating (`AVG(tt_eval_ratings.rating)` across the player's evaluations)

Filters: `team_id` (required), `date_from` (default 1 year ago) / `date_to` (default today).

### Team activity history

One row per activity within the date range. Subquery-computed attendance count and average rating (the rating average joins evaluations on `eval_date = session_date` for the team — close-enough for match-day evaluation rollups).

Filters: `team_id` (optional), `date_from` (default 1 year ago) / `date_to` (default today).

### Coach / staff directory

Non-parent `tt_people` rows with email, phone, role and a `GROUP_CONCAT` of the team names they're assigned to via `tt_team_people`. Filterable by role type.

Filters: `role_type` (allowlist: all / coach / scout / staff / other).

### KPI snapshot

Single-sheet XLSX with point-in-time KPIs for board reports:

- Snapshot range from / to / generated-at
- Active players / total players / active teams
- Activities in period / evaluations in period
- Attendance rows / present / present %
- Goals — total / active / completed

Filters: `date_from` (default first-of-month) / `date_to` (default today).

### Audit log

Admin-only dump of `tt_audit_log` for compliance / GDPR review. Guards on `SHOW TABLES LIKE` so a fresh install before migrations doesn't crash.

Filters: `date_from` (default 30 days ago) / `date_to` (default today), `action` (LIKE contains), `entity_type` (exact match).

## Implementation notes

- Every exporter scopes by `club_id` (matrix tenancy scaffold) — no cross-tenant leak even when SaaS multi-tenancy lands.
- Every exporter cap-gates on its existing capability — no new caps introduced.
- Multi-format exporters (csv + xlsx) leverage the `XlsxRenderer` recognition path for the `[ 'headers' => …, 'rows' => … ]` payload shape added in v4.0.10 — same `collect()` feeds both renderers.
- `FrontendExportsView::cards()` was extended with one card per new exporter, all using the existing `formats[]` shape + chip toggle / hidden badge logic.

## Out of scope

- Per-record exports stay on detail pages (unchanged).
- The audit log lacks server-side retention; for very large logs the date range is the main control. An async runner ships when a real install hits the size ceiling.

## Verification

- All 14 bulk-export cards render on the Exports page; each respects its cap-gating.
- A coach without `tt_view_reports` sees the page minus the KPI snapshot.
- An admin downloads the KPI snapshot — numbers cross-check against the dashboard widgets for the same date range.
- Audit log on a fresh install returns an empty file rather than a 500.

## Closes

- #865 — Exports completeness audit — 6 missing bulk-export use cases
