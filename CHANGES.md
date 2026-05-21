# TalentTrack v4.0.4 — HoD Team Overview widget: layout + drill-down filter bugs (closes #857)

## Pilot report

Two distinct defects on the HoD dashboard's Team Overview widget:

1. **Layout overrun**: expanding a team's player list inline bleeds below the widget into the next grid row, overlapping the widget underneath.
2. **Drill-down scope drift**: clicking a team's "View all" link lands on the full club roster, not just that team's players.

## Root causes

### Defect 1 — widget height-locked while content grows

`TeamOverviewGridWidget` lives in a grid cell with `row_span = 3`. The shared `.tt-pd-widget { height: 100% }` rule (`persona-dashboard.css:179`) locks the widget to the cell's calculated height. The inline-expand JS removes `hidden` from `.tt-pd-team-card-body`, the nested flex column grows vertically, and the outer widget container can't grow to match — content visually bleeds.

Mobile at ≤480px sidesteps the issue because the flex-column stack at `persona-dashboard.css:126-130` doesn't use the grid constraint.

### Defect 2 — URL/filter protocol mismatch

`TeamOverviewGridWidget` built the drill-down URL as `?tt_view=players&team_id={id}`. But `FrontendListTable::stateFromQuery()` consumes filters via `$_GET['filter'][...]`, and `PlayersRestController` accepts `filter[team_id]=N` not raw `team_id=N`. The raw param was silently dropped → no filter applied → full club roster.

## Fix

Two surgical edits:

1. **CSS** — new rule in `assets/css/persona-dashboard.css` right after the team-card body block:

   ```css
   .tt-pd-widget-team_overview_grid { height: auto; }
   ```

   Only this widget kind is affected. Other widgets that share the height-100% rule (DataTableWidget, MiniPlayerListWidget) don't use inline expand — they have their own internal scroll and stay unchanged.

2. **Widget URL** — `TeamOverviewGridWidget::renderTeamCard()`:

   - Was: `'team_id' => $s->team_id`
   - Now: `'filter[team_id]' => $s->team_id`

   Matches the existing filter protocol used by `FrontendListTable`. No changes needed to the destination view or REST controller.

## What this is NOT

- Not a matrix scope bug. The HoD's `players` scope is `global` by design — they're allowed to see all players. The drill-down's job is to scope to the *clicked team*, not the *HoD's responsibility area*.
- Not a per-install data defect. Reproduces universally.

## Files touched

- `src/Modules/PersonaDashboard/Widgets/TeamOverviewGridWidget.php` — URL fix.
- `assets/css/persona-dashboard.css` — new `.tt-pd-widget-team_overview_grid { height: auto }` rule.
- `talenttrack.php` + `readme.txt` + `CHANGES.md` — version bump.

No migration. No schema. No translation. No REST contract change.

## How to test

1. As an HoD: open the dashboard. Expand any team in the Team Overview widget. The widget grows to fit; no overlap with the row below.
2. Click the team's "View all players" link. Land on `?tt_view=players&filter[team_id]=N`. Only that team's players in the list.
3. Resize to 360px (mobile). Both behaviours still work — the stack layout doesn't need the override but doesn't regress.
4. Sanity-check other widgets that share the height-100% rule (DataTableWidget, MiniPlayerListWidget): no visual change.

## Why patch (not minor)

Two bug fixes, no new behaviour. Per the v4.0.0 SemVer rule: patch.
