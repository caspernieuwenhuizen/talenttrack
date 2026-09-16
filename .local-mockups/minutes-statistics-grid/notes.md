# Minutes + statistics grid

Design-of-record for adding **manual goals and assists** to the minutes grid,
and for renaming that surface from *Minutes* to *Minutes + statistics*.

Open `index.html` in a browser. Picker at the top toggles the three variants
plus today's baseline for diffing.

**Status: settled. Variant A is the chosen design; the implementation issue is
#3094.** B and C stay in the file as the record of what was considered.

## The gap

`GoalContributionQuery` (#2859) reads goals and assists from
`tt_match_execution_goal_events`, and the **live match sheet is the only thing
that writes that table**. A coach who does post-match admin on a Sunday evening
rather than running a stopwatch on the touchline therefore has players whose
minutes are complete and whose output is permanently blank — on the player
record, in the reports, and in every future aggregation.

The plugin measures a player's *exposure* and a coach's *judgement*. Their
*output* is the third leg, and today it only exists for clubs who run matches
live.

## Settled

| | |
| --- | --- |
| **Surface** | The existing minutes grid, relabelled **Minutes + statistics** — the card title, the `Attendance \| Minutes` mode nav, the breadcrumb and the lead paragraph. No new `?tt_view=` slug; the URL stays `?tt_view=minutes-grid`. |
| **Storage** | Widen `tt_match_execution_goal_events`: add `activity_id`, make `execution_id` / `half` / `minute_in_half` nullable, backfill `activity_id` from the execution. One store for every goal in the system. A manually recorded goal honestly carries no minute rather than a fabricated one. |
| **Live columns** | Editable, kept as a correction — the same contract the minutes cells already have on an execution-owned column, and the same `live` badge explains it. |
| **Entry** | **Variant A**, sub-columns. The grid's premise is "the Excel-familiar alternative to the wizard", and A is the only variant a spreadsheet user needs no explanation for. |
| **Stat set** | Goals + assists, and nothing else. No own-goals column; cards would need a store of their own; a clean sheet is derived, so it belongs in a report. |
| **Labels** | The mode-nav pill reads **Minutes + stats**; heading, card title, breadcrumb, feature label and docs all read **Minutes + statistics**. |
| **Keepers** | Not special-cased. Hiding columns by position surprises the one week a keeper scores from a corner, and it would make the grid depend on position data being right. |
| **Chip memory** | Per user (`user_meta`), not per club — it is a display preference. |

## The three variants

### A · Sub-columns — `Min ｜ G ｜ A` per match

Every value on the page. No interaction to discover; a coach reads *down* a
column to see who scored across the season. Tab runs Min → G → A → next match,
which is how the spreadsheet this grid deliberately imitates behaves.

The **column chips** above the header are what make it survivable: Goals and
Assists switch off, the grid collapses to today's width, and the choice is
remembered per user. Those two are the whole chip row.

*Cost:* three times the columns. Six matches is 18 plus totals. It scrolls,
which the grid already does, but a 20-match season is a long scroll.

### B · One box, popover on focus

The grid at rest is today's grid — same width, same speed. Only cells that
actually hold output carry a small `2·1` badge. Focus opens a popover with
Minutes / Goals / Assists, and typing `+` inside the minutes box jumps straight
to Goals, so `72+2+1` is one uninterrupted keystroke run.

*Cost:* the values are hidden until focused, so "who scored in February" cannot
be scanned. Most new JavaScript of the three.

### C · Expanding row band

The row is the unit. Minutes on the closed row; `▸` opens two sub-rows carrying
goals and assists across every match at once. Rows that already hold output
open on load.

*Cost:* vertical rather than horizontal. Eight players fully open is 24 rows,
and comparing two players means scrolling between two open bands.

## The footer row (all three)

`Toegekend / stand` — attributed goals summed per column against
`tt_activities.home_score`, with a warning marker on a mismatch. It costs one
row and makes the data self-auditing: `2/3` says a goal in that match has no
scorer against their name yet, which is exactly the drift migration 0235's
docblock was written about.

Manual entry **never writes the scoreline**. The scoreline is what happened;
attribution is what we know about it, and letting the second silently rewrite
the first is how the two came to disagree in the first place.

## Resolved 2026-08-29

All four open questions are answered and folded into the table above and into
#3094: variant A; goals + assists only; short pill, full heading; no keeper
special-casing.

Left deliberately for later, if they ever earn an issue of their own:

- **Cards.** Yellow/red need a store that does not exist yet.
- **Clean sheets.** Derived from `away_score` + keeper minutes, so a report
  rather than a column a coach types into.
- **The table name.** `tt_match_execution_goal_events` becomes a misnomer the
  moment it holds goals with no execution. Renaming it is a bigger job than
  #3094; the migration docblock says so and leaves it.

## Port notes for the executor

- Production classes reused verbatim: `.tt-agrid-*` from
  `assets/css/frontend-attendance-grid.css` and
  `assets/css/frontend-minutes-grid.css`.
- New classes in this mockup: `.tt-agrid__sub`, `.tt-agrid-stat`,
  `.tt-agrid-stat-in`, `.tt-agrid-cell--sep`, `.tt-agrid-statpick`,
  `.tt-agrid-statchip`, `.tt-agrid-badge`, `.tt-agrid-pop`,
  `.tt-agrid__expand`, `.tt-agrid-recon`. They belong in
  `frontend-minutes-grid.css`, not in the shared attendance sheet.
- The mockup's inline `<style>` and `<script>` are mockup-only. Production
  enqueues per CLAUDE.md §2; no inline styling in `src/**/*.php`.
- Every stat input needs `inputmode="numeric"` and an `aria-label` naming the
  player, the match and which statistic — the mockup has them.
- Copy in the mockup is Dutch to match the pilot install; the msgids are
  English and the `nl_NL.po` entries ship in the same PR.
