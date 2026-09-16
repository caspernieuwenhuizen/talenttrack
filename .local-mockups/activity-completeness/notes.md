# Activity completeness readout — notes

Two things in one directory, because they are the same problem seen twice:

1. **The gap** — the scenarios in which an activity reaches `completed` with no
   attendance recorded, and nothing anywhere says so.
2. **The readout** — the `N/N` counts on the activity list that make the gap
   visible at a glance (`index.html`).

Investigated 2026-09-16 on `main` @ `ebfaaff0`.

---

## Part 1 — where attendance goes missing

### The design intent, so the deviations are legible

`ActivitiesRestController::set_status` states the contract outright:

> `completed` is accepted here ONLY when the guided evaluation wizard is
> switched off […] With the wizard on it stays rejected: completion is the
> wizard's final save, and **a second path would let an activity complete with
> no attendance recorded**.

So the intended invariant is: *the only route to `completed` passes through
attendance capture.* Every scenario below is a route that gets around it.

### S1 — Planned attendance makes the wizard skip its own attendance step ★

**This is the reported symptom.** `tt_attendance` stores *both* the planned
roster and the actual register, separated only by `record_type`
(`expected` / `actual`, migration `0121`). Expected rows are written with real
statuses — `plannedStatusMap()` maps Expected→Present, Not coming→Absent,
Maybe→Excused ([ActivitiesRestController.php:1548](../../src/Infrastructure/REST/ActivitiesRestController.php#L1548)).

`AttendanceStep::activityHasAttendance()` does **not** filter on `record_type`:

```sql
SELECT 1 FROM {$p}tt_attendance WHERE activity_id = %d AND club_id = %d LIMIT 1
```

[AttendanceStep.php:487](../../src/Modules/Wizards/Evaluation/AttendanceStep.php#L487)

So a coach who ticked the expected roster when creating the activity has rows,
and `notApplicableFor()` silently drops the whole attendance step
([AttendanceStep.php:24](../../src/Modules/Wizards/Evaluation/AttendanceStep.php#L24)).
The wizard lands straight on `RateConfirmStep`, which opens with the sentence
**"Attendance is saved."** — it was not. Skip → `completeActivityIfNotTerminal()`
→ activity `completed`, zero `actual` rows, no warning at any point.

Same missing filter in the step's pre-fill query
([AttendanceStep.php:76](../../src/Modules/Wizards/Evaluation/AttendanceStep.php#L76)),
and in `RateConfirmStep::countRatable()`
([RateConfirmStep.php:140](../../src/Modules/Wizards/Evaluation/RateConfirmStep.php#L140)),
which therefore counts planned "Expected" rows as players present to rate.

### S2 — The same step, when it *does* render, can overwrite the plan ★

`AttendanceStep::validate()` finds the row to update with no `record_type`
filter either ([AttendanceStep.php:413](../../src/Modules/Wizards/Evaluation/AttendanceStep.php#L413)),
so on the `_attendance_force_render` path it `UPDATE`s the **expected** row
instead of inserting an actual one. The coach sees the register saved; the row
keeps `record_type = 'expected'`; every report — all of which filter to
`'actual'` — still reads zero. Worse than S1, because the data is there and
still does not count.

### S3 — A match finished on the match sheet without match prep ★

`route_finish` flips the activity to `completed` and *then* calls
`recomputeAttendanceAndMinutes()`
([MatchExecutionRestController.php:887](../../src/Modules/MatchExecution/Rest/MatchExecutionRestController.php#L887)),
which bails on its second line:

```php
$prep = $prep_repo->findByActivity( $activity_id );
if ( ! $prep ) return false;
```

[MatchExecutionRepository.php:958](../../src/Modules/MatchExecution/Repositories/MatchExecutionRepository.php#L958)

The status write is unconditional and already done. A coach who starts a match
without doing match prep gets a completed match with **no attendance and no
minutes**, and a `false` return value nobody reads.

### S4 — Wizard off: "Mark completed" is a bare status flip

By design (#2407) — with the wizard off, `POST /activities/{id}/status` accepts
`completed`, and the grids that record attendance never touch status. The
confirm copy says *"Record attendance first if you have not"*, which is a
request, not a check. Nothing verifies it.

### S5 — Creating or editing an activity straight into `completed`

The flat form / wp-admin page / REST `PUT` all accept
`activity_status_key = completed` as an ordinary field. On **create** there is a
mitigation — `seedCompletedRosterPresent()` (#1636) seeds the roster as present.
On **update** there is none: planned → completed via the edit form writes no
attendance at all.

### S6 — Imports

`ExcelImporter` writes `activity_status_key = 'completed'`
([ExcelImporter.php:492](../../src/Modules/Import/Excel/ExcelImporter.php#L492)) and
`TournamentsRestController` does the same at line 934. Both are legitimate, but
both produce completed activities whose register may never be filled.

### S7 — An activity with no team, or an empty roster

`AttendanceStep::render()` builds the roster from
`tt_players WHERE team_id = <activity.team_id>`. A club-wide activity
(`team_id = 0`) yields no players, so no inputs post, so `validate()`'s
`if ( $aid > 0 && ! empty( $att ) )` guard writes nothing — silently — and the
flow completes as normal.

### S8 — …and the alert that was supposed to catch all of this doesn't

`AttendanceUnrecordedAlert` exists for exactly this failure (#2631) and has the
**same missing `record_type` filter**:

```sql
AND NOT EXISTS (
    SELECT 1 FROM {$p}tt_attendance att
     WHERE att.activity_id = a.id AND att.status IS NOT NULL AND att.status <> ''
)
```

[AttendanceUnrecordedAlert.php:80](../../src/Modules/Alerts/Definitions/AttendanceUnrecordedAlert.php#L80)

An expected row satisfies the `EXISTS`, so **S1 and S2 — the two most common
cases — are invisible to the one alert built to report them.** That is the
"*and it does not report so*" half of the bug report.

Secondary: the alert gates on `a.plan_state = 'completed'`, but `plan_state`
was added `DEFAULT 'completed'` and only the planner sets it explicitly — the
conclusion #2521 drew when it moved reporting onto `activity_status_key`
([Infrastructure/Query/ActivityLifecycle.php](../../src/Infrastructure/Query/ActivityLifecycle.php)).
So the alert also fires on activities nobody completed.

### Summary

| # | Scenario | Attendance lost | Currently reported |
| --- | --- | --- | --- |
| S1 | Expected roster makes the wizard skip attendance | all | no (S8) |
| S2 | Wizard overwrites the expected row, stays `expected` | all, invisibly | no (S8) |
| S3 | Match finished with no match prep | all + minutes | yes, by the alert |
| S4 | Wizard-off "Mark completed" | all | yes |
| S5 | Edit form sets status to completed | all | yes |
| S6 | Excel / tournament import | all | yes |
| S7 | Team-less activity or empty roster | all | yes |

---

## Part 2 — the readout (`index.html`)

Right-aligned `N/N` per completed activity. Training shows attendance only;
matches show attendance **and** minutes. Three presentations behind the picker,
plus today's baseline.

### Variants

- **A · Right rail — recommended.** A fourth grid column between the body and
  the chevron (`44px 1fr auto auto`), one line per measure. Right-aligned and
  tabular-numeric, so a column of counts is scannable vertically — which is the
  whole ask. The `ATT` / `MIN` labels drop below 480px; the icon carries it.
- **B · Meta chips.** Pills appended to the existing meta line. Zero layout
  change, wraps naturally, cheapest port — but the counts sit at a different x
  on every row, so they do not scan.
- **C · Footer strip.** A dashed-top strip with a 44px micro-bar per measure.
  Most legible, most explicit, and adds ~30px of height to every past card.

**Recommendation: A**, with C worth a look if the counts turn out to need the
bar to be read quickly.

### The "fix" affordance

A completed activity at `0/N` is a task, not a statistic, so the gap state
carries a link to the attendance grid for that activity. It renders **outside**
the card's tap-to-open `<a>`, exactly like `AlertChip` (#2633) and the
"Complete activity" quick-action (#2245) already do — a nested anchor is
invalid markup. Toggle it off in the picker to see the counts alone.

### What the numbers mean

- **Attendance numerator**: rows in `tt_attendance` for the activity with
  `record_type = 'actual'`, `is_guest = 0`, `status <> ''`.
- **Attendance denominator**: the team's current non-archived roster.
- **Minutes numerator**: of those rows, the ones with `minutes_played IS NOT NULL`.
- **Minutes denominator**: rows with status Present or Late — a player who was
  absent is not missing minutes. Matches only.
- Rendered only on `activity_status_key = 'completed'`. Not on planned (nothing
  is late yet), not on cancelled, not on meetings.

### Port acceptance

- [ ] One batched query for the whole page — `GROUP BY activity_id` over the
      rendered ids, seeded like `ActivityGridLink::primeAnchor()` already is.
      Not a per-card read; `renderActivityCard()` runs inside the bucket loop.
- [ ] Roster sizes batched the same way, one query for the teams on the page.
- [ ] The count is a **projection**, so it belongs in a domain service
      (`ActivityRegisterProgress`, beside `ActivityRatingProgress`), not in the
      view — CLAUDE.md §4.
- [ ] Exposed on the activities REST payload as
      `register: { attendance: {recorded, expected}, minutes: {…} }` so a
      non-WordPress front end draws the same row.
- [ ] Screen readers get the full sentence, not "14 slash 14" — `.sr-only`
      alongside the glyph, as in the mockup.
- [ ] No hover-dependence; counts are static text. The fix link meets 48×48.
- [ ] Renders at 360px with no horizontal scroll (variant A is the tight one —
      check a long title truncating, not wrapping).
- [ ] Dutch: *Aanwezigheid* / *Minuten*, short labels *Aanw.* / *Min.*

### Open questions

1. **Denominator = current roster.** A player transferred out in March makes a
   September training read `13/14` forever. Alternative: count the planned
   (`expected`) roster where one exists, falling back to the team roster.
2. **Does a `0/N` count belong on planned activities too**, as a "nothing
   planned yet" hint? The mockup says no — an activity that has not happened
   cannot have a missing register.
3. **Guests.** Excluded from both numerator and denominator in the mockup, the
   same scope the minutes reports use (#2193).
