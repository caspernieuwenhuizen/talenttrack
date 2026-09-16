# Weekday on an activity's date — design notes

## The gap

An activity is a *scheduled event*: a coach thinks in "Friday training", not
"the 11th". Today neither the activities list row nor the activity detail hero
prints a weekday anywhere.

It is already printed in eight other places, which is what makes the omission
read as an inconsistency rather than a missing feature:

| Surface | Format | Source |
| --- | --- | --- |
| Today bucket header | `D j M` → "Today · Fri 11 Sep" | `FrontendActivitiesManageView::formatTodayHeader()` |
| Dashboard "Upcoming activities" table | `D j M, H:i` | `PersonaDashboard\TableSources\UpcomingActivitiesSource` |
| Team planner column heads | `D`, `D M j` | `Planning\Frontend\FrontendTeamPlannerView` |
| Planner printables / PDF export | `ucfirst( l )` | `TeamPlannerWeeklyPrintable`, `TeamPlanningPdfExporter` |
| Persona landing greeting | `l, j F` | `PersonaLandingRenderer` |

## Why the formatter, not a pill

`TTDate` is the single date chokepoint (#1481), and **none of its seven presets
contains `D` or `l`** — `system`, `dmy_dash`, `dmy_slash`, `dmy_dot`,
`mdy_slash`, `iso`, `long`. Every weekday in the table above is therefore a
hand-rolled `wp_date()` call that ignores the operator's configured preset.

A weekday chip in `.tt-act-detail__pills` was considered and rejected: that row
carries lookup-backed *classification* (activity type, game subtype, status),
each colour-coded from `tt_lookups` via `LookupPill`. A weekday is not a
classification — a chip there reads as a fourth status, separates the day from
the date it belongs to, and fixes only the detail page.

Proposed instead: `TTDate::dateWithDay()`, prefixing the configured preset with
`D`. No `ucfirst` inline — `wp_date` already returns correct per-locale casing
(Dutch `vr`, English `Fri`). The two planner printables `ucfirst` only because
there the weekday heads a column.

## The list row is the harder half

The list card has **no date text at all** — the stacked `.tt-act-date` tile *is*
the date. So `dateWithDay()` has nothing to attach to there and the weekday has
to enter the tile.

Two constraints:

1. `grid-template-columns: 44px 1fr auto` (`frontend-activities-manage.css:181`).
   The tile is ~44×44 and already taller than the card body (title 18px + meta
   20px ≈ 38px), so the tile is what sets row height.
2. **The month cannot be dropped to make room.** Buckets are relative periods —
   Past / Needs attention / Today / This week / Next week / Later this month /
   **Later** — so a week straddling a month boundary, and the whole "Later"
   bucket, would lose which month they are in.

### Measured (live, in the mockup — not estimated)

| Variant | Row height | Tile |
| --- | --- | --- |
| Current | 70px | 44×44 □ |
| **A□ — weekday / day / month, square** | **78px, uniform** | 52×52 □ |
| A — the same three lines at 44px | 83px, uniform | 44×57 |
| B — weekday joins the month line | 88px at a 56px column (wraps); needs 68px | 68×48 |
| C — weekday before the day number | 88px at a 56px column (wraps); needs 64px | 64×48 |
| D — weekday in the meta tail | 70–88px **ragged** — wraps on long rows only | 44×44 □ |

The first pass assumed B, C and D left row height unchanged. Rendering them
disproved it: at a plausible 56px column both B and C wrap, and D's tail grows
past the 360px meta line on rows that carry a location. A is the only variant
that is both uniform and the smallest growth.

## Settled 2026-09-16

**Variant A□ — weekday / day number / month, in a tile that stays square:**

```
┌────────┐
│  FRI   │  .tt-act-date__w   10px, 700, uppercase, 0.7 opacity   NEW
│   11   │  .tt-act-date__d   18px, 800, tabular-nums
│  SEP   │  .tt-act-date__m   10px, 600, uppercase
└────────┘
   52×52, aspect-ratio: 1
```

The weekday leads because it is what a coach actually scans for; the day number
keeps the visual centre of the tile; the month anchors the bottom. Top-to-bottom
reading order matches how the date is spoken ("Friday the 11th, September").

**The tile stays a square.** Three lines need ~42px of type, so the square grows
44 → 52px. Counter-intuitively this makes rows *shorter* than the 44-wide
rectangle (78px vs 83px): centring three lines inside a fixed square lets the
gaps tighten, where the rectangle's stacked margins accumulate. Held with
`aspect-ratio: 1` plus flex centring rather than a hardcoded height, so the
square survives whatever a locale does to line widths.

Costs, against today: **+8px row height, +8px grid column.** The column goes
`44px 1fr auto` → `52px 1fr auto`, so the title ellipsises 8px sooner.

Two changes to the existing spans:

- **DOM order swaps.** `renderActivityCard()` emits `__m` then `__d` today; the
  new order is `__w`, `__d`, `__m`. This is a rewrite of the emit order, not an
  append.
- **`__m` drops 12px → 10px** so three lines clear 44px of inner height. It
  keeps its weight, casing and letter-spacing.

Also settled:

- Detail surfaces get the weekday **joined to the date**, not as a pill —
  `TTDate::dateWithDay()`.
- Scope is **scheduled events only**. `TTDate::date()` is untouched.

## Port acceptance

- `TTDate::dateWithDay()` added; existing `TTDate::date()` untouched, so
  birthdates, sign-off stamps and audit rows keep the plain preset.
- Scope is scheduled events only: activities list + detail, match prep,
  match analysis, match execution, activity pickers.
- `FrontendTeamDetailView:877` prints a **raw ISO string** today
  (`$date_text = (string) $r->session_date;`) — no formatter at all. Fix while
  in there.
- `FrontendPlayerDetailView::dateBadge()` (2729–2738) builds its badge with
  non-localised `gmdate()`. Same tile idiom, same bug class.
- The activity detail **match** branch of `renderDetailFacts()` has no Date
  fact at all (only Opponent / Home-Away / Kick-off / Formation).
- Renders at 360px with no horizontal scroll; tile stays ≥ 44px wide and the
  row's tap target stays ≥ 48px.
- Locale check: NL two characters, DE two, FR four plus a full stop. The tile
  is centred, so the widest case governs.
