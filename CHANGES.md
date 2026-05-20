# TalentTrack v3.110.177 — "My team attendance %" KPI excludes planned / cancelled activities from both compute + deep-link (closes #775)

## Pilot follow-up

After v3.110.175 (`#771` — KPI deep-links honour their compute window):

> but does it also take into account other scope context; for example only completed activities because planned and cancelled activties do not count towards the number?

Sharp catch. The v3.110.175 fix made the deep-link honour the 28-day window but left activity-state scope unaddressed.

## What was still wrong

- `MyTeamAttendancePct::compute()` counts rows in `tt_attendance`. Planned activities (no attendance rows yet) didn't contribute in practice, but **cancelled-after-attendance-marked** activities could still slip into the denominator.
- `MyTeamAttendancePct::linkUrl()` from v3.110.175 added `filter[date_from]` + `filter[date_to]` but no `plan_state` filter, so the destination activities list showed **every** activity in the 28-day window — including planned, draft, scheduled, and cancelled — even though they don't contribute to the KPI.

The number on the card and the row count of the destination list could disagree, and the pilot's coach mental model ("only activities that actually happened count") wasn't reflected in either place.

## Fix — symmetric on both sides

New constant in `MyTeamAttendancePct.php`:

```php
private const ACTIVITY_STATES_COUNTING = [ 'completed', 'in_progress' ];
```

### `compute()` adds the state predicate

The SQL now also constrains `act.plan_state IN ('completed', 'in_progress')`. Any attendance row attached to a `planned` / `draft` / `scheduled` / `cancelled` activity is excluded from the KPI's denominator. Covers the cancelled-after-attendance-marked edge case.

### `linkUrl()` adds the same filter to the destination

The activities REST endpoint already parses comma-separated values on `filter[plan_state]` via its `$allowed = [ 'draft', 'scheduled', 'in_progress', 'completed', 'cancelled' ]` whitelist. No REST shape change needed.

## Why this is architecturally correct

Both `compute()` and `linkUrl()` consume the SAME `ACTIVITY_STATES_COUNTING` constant — drift between the KPI's number and the filtered list is structurally impossible. Same single-source-of-truth principle as:

- v3.110.173 — bool-returning dispatchers (switch case IS the registration; no second allowlist to drift)
- v3.110.175 — `WINDOW_DAYS` constant (shared between compute and linkUrl)

Now there are two shared constants in `MyTeamAttendancePct`:

- `WINDOW_DAYS = 28` — the rolling window
- `ACTIVITY_STATES_COUNTING = [ 'completed', 'in_progress' ]` — the scope

Both used by both methods. The KPI's universe and the destination filter's universe are guaranteed to match.

## Why `completed` AND `in_progress` (not just `completed`)

The pilot said "only completed", but `in_progress` is a meaningful subset of completed-in-spirit:

- `in_progress` activities have attendance rows being marked DURING the session itself. Those rows reflect real presence calls. Excluding them under-counts the recent attendance signal during a session that just happened.
- `completed` activities are the typical case: session over, attendance finalised.

Both excluded by the new gate: `draft`, `scheduled`, `planned` (pre-attendance), `cancelled` (post-decision to not run).

## What's NOT affected

- `MyTeamAvgRating` — evaluations have no `plan_state` concept. The existing `e.archived_at IS NULL` filter is already the equivalent gate.
- Academy-wide KPIs (`AttendancePctRolling`, etc.) — out of scope for this fix.

## Files touched

- `src/Modules/PersonaDashboard/Kpis/MyTeamAttendancePct.php` — new constant, compute SQL adds `AND plan_state IN (…)`, linkUrl adds `filter[plan_state]=…`.
- `talenttrack.php` — 3.110.176 → 3.110.177.
- `readme.txt` — Stable tag + changelog entry.
- `CHANGES.md` — this file.

No DB migration, no REST shape change, no new i18n strings, no auth change, no UI change.

## Test plan

- [ ] On the coach dashboard, "My team attendance %" KPI displays a percentage. Note it.
- [ ] Click the KPI → activities list opens with `date_from`, `date_to`, AND `plan_state` filters all pre-set.
- [ ] List shows ONLY activities with `plan_state` in `completed` or `in_progress`. Zero planned, draft, scheduled, or cancelled rows visible.
- [ ] Mentally compute attendance from visible rows → matches the KPI percentage.
- [ ] Edge case: if any cancelled activity in the 28-day window had attendance rows on it, the KPI percentage decreased after this ship (those rows are no longer in the denominator).
- [ ] Regression check: "My team avg rating" still works as before (no plan_state filter applied).
- [ ] Regression check: clicking other KPIs still routes correctly with their own filters.
