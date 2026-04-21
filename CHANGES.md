# TalentTrack v2.20.0 — Player Comparison + Access Control Tiles + Reports Tile Launcher

## Summary

Three items, all UX-polish with concrete functional additions:

1. **Player Comparison** — new Analytics page for 4-player side-by-side comparison, cross-team supported
2. **Access Control tiles + menu placement fix** — the Roles & Permissions, Functional Roles, and Permission Debug pages were orphaned from menu groups and from the dashboard tile grid; now properly grouped under an "Access Control" separator + dashboard tile group
3. **Reports redesigned as tile launcher** — the old combined form is retained as a "legacy" tile; two new first-class report types shipped (Team rating averages, Coach activity)

No schema changes. No migrations.

## Item 1 — Player Comparison

### What it does

Dedicated page at `Analytics → Player Comparison`. Four slot dropdowns, each with the full player roster searchable as `LastName, FirstName — Team (age group)`. Cross-team comparison works without constraint — comparing a U15 LB against a U13 ST is not only possible, it's the point.

### Comparison surface

Stacked vertically for readability:

- **Cards row** — FIFA-style player cards (size `sm`) side-by-side at the top, visual anchor
- **Basic facts table** — team, age group, positions, foot, jersey #, height, weight, DOB — one row per attribute, one column per player
- **Headline numbers** — Most recent / Rolling (last 5) / All-time / Evaluation count — same shape as the Rate Card headline tiles
- **Main category averages table** — union of all main categories with one row per category and one column per player, blank cells when a player has no data for that category
- **Radar overlay chart** — all 4 players as coloured datasets on one radar, Chart.js
- **Trend overlay chart** — all 4 players' overall rating over time on one line chart

### Mixed age groups handling

When the selected players span multiple age groups, a blue notice appears above the comparison:

> Mixed age groups in this comparison. Overall ratings use age-group-specific category weights, so the numbers below are not perfectly apples-to-apples — they reflect what each player's own coaching staff uses.

The numbers themselves use each player's age-group-weighted overall (honest — that's the real number; unweighted would be a fabrication). The notice makes the limitation explicit.

### Filters

Date range + evaluation type, same as the Rate Card page. All 4 slots use the same filter values so the comparison is consistent.

### Permissions

`tt_view_reports` (coaches + admins). Matches the Rate Card page.

### Technical

Reuses `PlayerStatsService` helpers — no new aggregation logic. `getHeadlineNumbers()`, `getMainCategoryBreakdown()`, `getTrendSeries()`, `getRadarSnapshots()` are called once per player. Chart.js for both overlay charts, loaded from the same CDN as the Rate Card page. Single JS block at the bottom of the page wires both charts from PHP-rendered JSON payloads.

## Item 2 — Access Control tiles + menu placement fix

### The problem

TalentTrack has three admin pages that govern authorization:
- **Roles & Permissions** (`tt-roles`) — grant/revoke TalentTrack caps per WP user
- **Functional Roles** (`tt-functional-roles`) — map club roles (head coach, physio) to authorization roles
- **Permission Debug** (`tt-roles-debug`) — "what can this user actually do?" inspector

All three were registered by `AuthorizationModule::registerMenu()` — a self-contained module bootstrap that added them to the TalentTrack submenu at runtime. Same story for the People page via `PeopleModule::registerMenu()`.

Two consequences of this pattern:

1. The pages appeared in the admin **sidebar** but at the flat bottom, **below all the menu-group separators** established in 2.17.0 — not inside any visual group
2. They were **invisible on the dashboard tile grid** because that grid was hardcoded in `Menu::dashboard()`, and the grid author (me) had no way of knowing these orphan pages existed

A user complaint about "missing Authorization tiles" exposed this. I'd missed the whole module.

### The fix

**Menu registrations centralized.** `Menu::register()` now owns the submenu registrations for People (People group) and the three Authorization pages (new Access Control group). `AuthorizationModule::registerMenu()` and `PeopleModule::registerMenu()` are now no-ops — they still exist so any bootstrap order or external code that calls them doesn't fatal, but they do nothing. The admin_post handlers in `AuthorizationModule::register()` remain untouched.

**New Access Control separator** added between Configuration and Help & Docs in the sidebar. Same fake-submenu-slug pattern as other group separators (`tt-sep-access`).

**New Access Control tile group** on the dashboard, between Configuration and Help. Three tiles:
- Roles & Permissions — `tt-roles`
- Functional Roles — `tt-functional-roles`
- Permission Debug — `tt-roles-debug`

Red accent color (`#b32d2e`) distinguishes the group visually — access control has consequences, worth the attention-getting hue.

### Group naming

"Access Control" chosen over "Authorization" / "Roles & Permissions" — balances clarity with brevity. Non-technical club admins understand "who has access to what," which is exactly what the group is about.

## Item 3 — Reports redesigned as tile launcher

### Before

Reports page was a single form: report-type dropdown, player multi-select, run button. Discoverability was bad — you had no visual sense of what the plugin could do without opening the dropdown. Adding a new report type meant cluttering the dropdown further.

### After

Landing page is a tile grid. Three tiles to start:

1. **Player Progress & Radar** (icon: `dashicons-chart-line`) — blue. The former combined form, preserved so existing muscle memory and any bookmarks continue to work. Lives at `?report=legacy`.
2. **Team rating averages** (icon: `dashicons-shield`) — green. NEW. A table: teams as rows × main categories as columns × average rating in each cell. Plus an Evaluation count column so you can gauge data density per team. Archived players and evaluations excluded.
3. **Coach activity** (icon: `dashicons-welcome-write-blog`) — purple. NEW. Evaluations saved per coach over a configurable window (7 / 30 / 90 / 180 / 365 days). Sortable by volume; shows last-evaluation timestamp per coach.

Each tile routes to `?page=tt-reports&report=<slug>`. Back button returns to the launcher. Shell is minimal — each report is its own render method inside `ReportsPage`, easy to add more.

### Help link

The launcher header gets a "? Help on this topic" link pointing at `?page=tt-docs&topic=reports`. The 2.21.0 help wiki will deliver the content; this sprint just wires the link.

### Scope parked

Per the sprint scope decision: build the shell + 2 new simple reports, don't try to pack more in. Future sprints add tiles without redesign work. Ideas on the shelf:
- Attendance summary per session / per team
- Goal progress by status per player
- Evaluations per team per month (trend)
- Player development timeline per team
- Export-oriented reports (CSV/XLSX download of various datasets)

## Help link foundation

Placeholder links added on Reports and Player Comparison pages pointing at `?page=tt-docs&topic=<slug>`. The 2.21.0 help wiki will render these. Links work today — they route to the existing Help & Docs page, which will be expanded in the next release.

## Files in this release

### New
- `src/Modules/Stats/Admin/PlayerComparisonPage.php` — 4-player comparison admin page

### Modified
- `talenttrack.php` — version 2.20.0
- `src/Shared/Admin/Menu.php` — People + 3 Authorization pages now registered here; Access Control separator + tile group; Player Comparison submenu + tile
- `src/Modules/Authorization/AuthorizationModule.php` — `registerMenu` removed (boot no longer adds admin_menu hook); admin_post handlers retained
- `src/Modules/People/PeopleModule.php` — `registerMenu` now a no-op
- `src/Modules/Reports/Admin/ReportsPage.php` — full rewrite as tile launcher; legacy form retained at `?report=legacy`; two new reports added
- `languages/talenttrack-nl_NL.po` + `.mo` — ~36 new strings

### Deleted
(none)

## Install

Extract `talenttrack-v2_20_0.zip`. Move contents into your `talenttrack/` folder. Deactivate + reactivate.

**No migrations.** All existing data carries forward. No breaking changes.

## Verify

### Access Control
1. Dashboard. New "Access Control" tile group visible between Configuration and Help. Three red-accented tiles: Roles & Permissions / Functional Roles / Permission Debug.
2. Submenu sidebar. New "ACCESS CONTROL" uppercase separator between Configuration and Help & Docs. Underneath: the same three links.
3. Click each tile/link — the existing pages render (Roles & Permissions, Functional Roles, Permission Debug).

### Player Comparison
4. Analytics → Player Comparison. Page loads.
5. Pick 2 players from different teams. Click Compare. Cards render side-by-side, facts table + headlines + categories + radar + trend all populate.
6. Pick 4 players spanning multiple age groups. Notice: "Mixed age groups in this comparison..." appears.
7. Apply a date-range filter. Comparison respects it consistently across all 4 players.

### Reports tile launcher
8. Analytics → Reports. Tile launcher with 3 tiles.
9. Click Team rating averages. Table renders with teams as rows, categories as columns.
10. Click back. Launcher again.
11. Click Coach activity. Window selector (7/30/90/180/365). Switch window — page reloads with new counts.
12. Click back. Launcher again.
13. Click Player Progress & Radar — old combined form appears. Run a progress report — still works.

### Menu placement (regression check)
14. Submenu sidebar reads top-to-bottom: Dashboard, PEOPLE / Teams Players **People**, PERFORMANCE / Evaluations Sessions Goals, ANALYTICS / Reports Player Rate Cards **Player Comparison** Usage Statistics, CONFIGURATION / Configuration Custom Fields Evaluation Categories Category Weights, **ACCESS CONTROL / Roles & Permissions Functional Roles Permission Debug**, Help & Docs.

## Known caveats

- **Comparison overall ratings are weighted per age group.** Not a bug, intentional — see the inline notice. If a club genuinely needs unweighted comparison, future work.
- **Coach activity report counts by `coach_id`.** If a coach is the wp_user who saved the evaluation. Doesn't account for "this evaluation was about a player this coach trains" — that's a different metric.
- **Team ratings report doesn't respect filters.** Shows lifetime averages. Adding a date-range filter is a future polish item.
- **No PDF/export from new reports yet.** Browser print works reasonably for the two new reports but there's no dedicated export button. Candidate for a future sprint.

## Design notes

- **Why centralize menu registration instead of letting modules self-register.** Modules still know their own pages (via their page classes, handlers, etc.) but sidebar grouping is a cross-cutting concern — who goes where, in what order, under which separator. Distributing that knowledge to each module would mean either duplicating the group list everywhere or losing grouping entirely (which is what happened). Centralizing it in `Menu::register` is the simplest fix and makes the grouping audit-able at a glance.
- **Why retain the legacy Reports form instead of just replacing it.** Someone's depending on it. Sharp knife not in scope here. The tile label explicitly groups it as "Player Progress & Radar" so future users who expect charts know where they are.
- **Why 4 slots on Player Comparison, not arbitrary N.** At N=2 or 3, table layouts work fine horizontally. At N=5+ the radar chart becomes visually unreadable (too many overlapping datasets) and the tables start needing horizontal scroll on normal screens. 4 is the Goldilocks number for side-by-side.
- **Why "? Help on this topic" links are live now, not waiting for the 2.21.0 wiki.** Dead links are worse than placeholder links. The existing Help & Docs page accepts `topic` parameters (even if it doesn't use them yet); when the wiki ships, these links automatically start working properly. No page edits needed at that point.

## v2.21.0 preview (confirmed)

- **Help wiki** (item 3 from sprint 2.19 backlog) — markdown-based, 18 topics, contextual links on all admin pages, per-release update discipline
- Possibly: additional report tiles now that the pattern exists
