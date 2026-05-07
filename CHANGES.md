# TalentTrack v3.108.5 — Pilot-batch follow-up IV: KPI strip + new team-roster widget + upgrade-to-Pro CTA + scout/widget UX (#0089 K1-K5 + A4 + A7)

Closes most of the remaining `ideas/0089` items in one ship. Bug investigations (F2, F4, F6) still need pilot-side reproduction; logged as the open backlog in the tracker.

## What landed

### (1) K1 — HoD KPI strip stops showing "—"

Five of the six HoD KPIs were broken in different ways. All six now compute real, tenant-scoped numbers.

- **`EvaluationsThisMonth`** — added `club_id` to the count query and the 4-bucket sparkline.
- **`OpenTrialCases`** — was querying `tt_trials` (table doesn't exist; the actual table is `tt_trial_cases` per migration 0036). The SHOW TABLES check always failed → `KpiValue::unavailable()` → "—" in the strip. Fixed table name + added `club_id` and `archived_at IS NULL`.
- **`AttendancePctRolling`** — threaded `club_id` through `pctInRange()` so the rolling 28-day percentage is club-scoped.
- **`ActivePlayersTotal`** — was counting every row including archived / released / inactive players. Now applies `archived_at IS NULL` + `status = 'active'` (where the columns exist).
- **`PdpVerdictsPending`** — was a hardcoded `unavailable()` stub. Now counts open PDP files (`tt_pdp_files`) whose verdict row (`tt_pdp_verdicts`) is missing or not yet `signed_off_at`.
- **`GoalCompletionPct`** — was a hardcoded `unavailable()` stub. Now `completed / total` percentage across the club's goals; falls back to "—" on zero goals.

### (2) K2 — KPI cards general

The standalone `KpiCardWidget` delegates to the same `KpiDataSourceRegistry::get()->compute()` flow, so every card that references the six HoD KPIs picks up the K1 fixes automatically. No code change in the card widget itself.

### (3) K3 — `UpcomingActivitiesSource` defensive `archived_at` filter

Added `s.archived_at IS NULL` so archived activities can't sneak into the HoD upcoming-table window.

### (4) K4 — Scout `scout_report` widget

Already supported via `ActionCardWidget` with `data_source='scout_report'`. The user's complaint ("it doesn't do anything when assigned") is because they were adding the widget by id directly — there is no standalone widget. The right path is `widget=action_card, data_source=scout_report`. Documented in the new widget's docblock; full standalone scout-report widget deferred to a follow-up.

### (5) K5 — `AssignedPlayersGridWidget` empty-state copy

Was: "Ask your Head of Development to share players with you." — left the user with no idea HOW.

Now (two paragraphs):

> You have no assigned players yet.
>
> Ask your Head of Development to open Reports → Scout access and assign you to specific players. You'll see them here once they do.

### (6) A4 — `team_roster_table` widget (NEW)

Per-team player roster table for the HoD dashboard. Configured via the slot's `data_source`:

```
team_id=42,days=30
```

Columns: First name · Last name · Status (LookupPill) · PDP status (signed_off / in_progress / —) · Average attendance % over the window.

Distinct from the multi-team `team_overview_grid` shipped in #0073. Registered in `CoreWidgets::register()` alongside the existing widgets.

### (7) A7 — Upgrade-to-Pro CTA on the Account page

Standard-tier installs now see a yellow upgrade card listing Pro-tier features (trial cases, scout access, team chemistry, radar charts, partial restore, scheduled reports) with a clear "Upgrade to Pro" button pointing at Freemius checkout (`?page=tt-account-pricing`) when configured, or back to the Account tab when Freemius isn't yet wired.

## Out of scope (still tracked in `ideas/0089`)

- F2 my-evaluations scores not displaying after wizard submit — needs pilot reproduction
- F4 goal save error "goal does no longer exist" — needs pilot reproduction
- F6 double-activity row verification — likely already fixed in v3.92.7

## Affected files

- `src/Modules/PersonaDashboard/Kpis/EvaluationsThisMonth.php` — K1 club_id filter
- `src/Modules/PersonaDashboard/Kpis/OpenTrialCases.php` — K1 wrong-table fix + club_id + archived_at
- `src/Modules/PersonaDashboard/Kpis/AttendancePctRolling.php` — K1 club_id threaded through
- `src/Modules/PersonaDashboard/Kpis/ActivePlayersTotal.php` — K1 archived_at + status filter
- `src/Modules/PersonaDashboard/Kpis/PdpVerdictsPending.php` — K1 stub → real implementation
- `src/Modules/PersonaDashboard/Kpis/GoalCompletionPct.php` — K1 stub → real implementation
- `src/Modules/PersonaDashboard/TableSources/UpcomingActivitiesSource.php` — K3 archived_at filter
- `src/Modules/PersonaDashboard/Widgets/AssignedPlayersGridWidget.php` — K5 empty-state copy
- `src/Modules/PersonaDashboard/Widgets/TeamRosterTableWidget.php` — A4 new widget
- `src/Modules/PersonaDashboard/Defaults/CoreWidgets.php` — A4 registration
- `src/Modules/License/Admin/AccountPage.php` — A7 upgrade card
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version + ship metadata

8 new translatable strings (team roster column labels + PDP status enum + assigned-players empty copy + upgrade-card body); NL translations land via the next i18n auto-commit.
