# TalentTrack v4.0.5 — HoD Upcoming Activities widget shows today's activities again (closes #858)

## Pilot report

> the hod dashboard, the upcoming activities list does not show anything while there are multiple teams with upcoming, planned activities.

## Root cause

`UpcomingActivitiesSource` used a time-inclusive lower bound:

```sql
WHERE CONCAT(session_date, ' ', COALESCE(start_time, '00:00:00')) >= NOW()
```

That excludes any activity which has already STARTED today, even if it hasn't ended. An HoD opening the dashboard at 09:00 on a day with an 08:00 training saw the widget empty.

For comparison, the coach hero (`UpcomingActivityRepository::nextForCoach`) uses a date-inclusive filter — same activity stays visible there.

## Fix

Align the HoD widget's filter with the coach hero. "Upcoming" now means "today or later":

```sql
WHERE session_date >= today
  AND session_date <= today + N days
```

One source-file change. `apply_demo_scope` unchanged.

Sibling audit (`grep CONCAT.*session_date.*start_time`): the too-strict pattern existed only here. No other surfaces affected.

## Files touched

- `src/Modules/PersonaDashboard/TableSources/UpcomingActivitiesSource.php` — `$from` date-only, query lower bound becomes `session_date >= %s`.
- `talenttrack.php` + `readme.txt` + `CHANGES.md` — version bump.

No migration. No schema. No translation.

## How to test

1. Schedule a training for today at 08:00. Open the HoD dashboard at any time after the start time. Widget shows the activity.
2. With multiple activities scheduled across the next 30 days, the widget renders the list ordered by `session_date ASC, start_time ASC`.
3. Demo-mode toggle: confirm the widget continues to filter via `apply_demo_scope` (no regression — that filter is untouched).

## Why patch (not minor)

Bug fix, no new behaviour. Per the v4.0.0 SemVer rule: patch.
