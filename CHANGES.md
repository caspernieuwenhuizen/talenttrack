# TalentTrack v2.14.0 — Epic 2 Sprint 2A: Player Rate Card

## What's new

First piece of Epic 2 lands: a proper **player rate card**. Every player now has a one-page summary synthesizing every evaluation that player has ever had — three headline numbers, per-main-category breakdown with trend arrows, expandable subcategory detail, a line chart showing rating evolution over time, and a radar chart showing the shape of the last few evaluations. Filterable by date range and evaluation type.

Two ways to reach it:
- **TalentTrack → Player Rate Cards** — top-level page with a player picker
- **TalentTrack → Players → (click a player) → "Rate card" tab** — embedded

Both render the same component. Pick whichever fits your workflow.

## The headline

Three big numbers at the top:

1. **Most recent** — that player's latest evaluation's overall rating, with the date. What you see if you just want "how did they do last time."
2. **Rolling average** — mean of the last 5 evaluations' overalls. Degrades gracefully to "last 3" or whatever's available if fewer exist. The near-term performance signal — noise-filtered latest.
3. **All-time average** — mean across every evaluation in the filtered range. The long-view number.

Side by side, these tell a story. Player with Most recent = 4.2, Rolling = 3.9, All-time = 3.5 is on an upward trajectory. Flip those numbers and you have a concern.

Each number is the weighted overall (uses whatever weight config the player's age group has) — same algorithm the evaluation form preview and the evaluation detail view use. No divergence.

## Main category breakdown

A 4-row table: Technisch, Tactisch, Fysiek, Mentaal, each showing:
- **All-time** — mean of their effective ratings (direct or sub-rollup) across all filtered evaluations
- **Most recent** — their effective rating in the most recent evaluation
- **Trend** — arrow + label indicating whether they're improving, declining, stable, or on too-little data

Trend detection is deliberately simple: split the player's filtered evaluations for that category into an older half and a newer half, compare their means, call it "up" if newer is 0.15+ higher, "down" if 0.15+ lower, "flat" otherwise. Single-rating noise shouldn't flip the arrow — 0.15 is roughly "more than one coach's 'good day' bias." Requires at least 2 data points per category.

Each row is clickable (▸ / ▾) to expand a subcategory breakdown: per-sub mean across filtered evaluations, with an "N evaluations counted" hint (since not every sub gets rated in every evaluation). Collapsed by default — keeps the view clean.

## Charts

**Trend over time** (line chart, Chart.js): one line per main category. X-axis is evaluation date; Y-axis is the rating scale (1-5 by default). Legend at the bottom is clickable to toggle lines on and off — useful for isolating a single dimension's progression. Gaps (evaluations where a main wasn't rated) are drawn as continuous lines through the point.

**Shape over last N evaluations** (radar chart, Chart.js): overlays the last 3 evaluations' per-main values on a radar. Shows how the player's "shape" has shifted — are they becoming more balanced, or more specialized in specific dimensions.

Chart.js loads from a CDN (`cdn.jsdelivr.net`). If the CDN is blocked (some clubs have restrictive network policies), the canvases get replaced with a "chart library unavailable" message and the text tables still show everything. No hard dependency on the library loading successfully.

## Filters

A form bar above the content:
- **Vanaf** (from date)
- **T/m** (to date)
- **Type** (evaluation type: all, training, match, friendly, or whatever types the admin has configured)
- **Filters toepassen** / **Wissen**

All filters combine (AND). Filter state lives in the URL so you can bookmark or share a filtered rate card. The same filters apply to the headline numbers, the main breakdown, the subcategory accordion, and both charts — everything reflects the filtered set consistently.

## What's computed, what's stored

Nothing new is stored. No `player_overall_rating` column, no aggregate cache table. Every number on the rate card is computed from existing `tt_evaluations` + `tt_eval_ratings` rows at read time.

Performance: two SQL queries for the evaluation list, one per evaluation for the per-main effective ratings (via `effectiveMainRatingsFor`), one for the subcategory rollup. At typical club scale (50 evaluations per player tops, usually much less), this is noise on the page-load timing. If anyone ever has a player with hundreds of evaluations and the page slows, we batch — but the architecture reserves that as a future optimization rather than complicating the first cut.

## Schema / migrations

**None.** This release adds zero tables, zero columns, zero migrations. Pure analytics on existing data.

## The seed-guard fix

Rolled in here rather than shipping as a separate point release: `Activator::seedEvalCategoriesIfEmpty()` now bails if any main category (in any language, with any key) already exists. Before, it checked each canonical English key individually and would re-insert English duplicates on sites where the admin had Dutch-keyed mains. Fixes the "Technisch, Tactisch, Fysiek, Mentaal appearing twice" symptom observed after 2.13.0 activation.

## Files in this release

### New
- `src/Infrastructure/Stats/PlayerStatsService.php` — analytics service
- `src/Infrastructure/Stats/index.php`
- `src/Modules/Stats/StatsModule.php` — module wiring
- `src/Modules/Stats/Admin/PlayerRateCardsPage.php` — top-level admin page
- `src/Modules/Stats/Admin/PlayerRateCardView.php` — shared rendering component
- `src/Modules/Stats/Admin/index.php`
- `src/Modules/Stats/index.php`

### Modified
- `talenttrack.php` — version 2.14.0
- `src/Core/Activator.php` — seed-guard fix
- `src/Modules/Players/Admin/PlayersPage.php` — Rate card tab on edit view
- `src/Shared/Admin/Menu.php` — Player Rate Cards submenu entry
- `config/modules.php` — StatsModule registered
- `languages/talenttrack-nl_NL.po` + `.mo` — ~30 new strings

### Deleted
(none)

## Install

Extract the ZIP into `/wp-content/plugins/`, preserving the existing `talenttrack/` directory structure. Deactivate + reactivate the plugin. Activation is fast — no schema changes. The seed-guard fix applies immediately; any sites that had the duplicate-mains symptom but haven't been manually cleaned yet will still need the manual cleanup (see Sprint 2A pre-work notes), since the guard prevents future duplicates but doesn't remove past ones.

## Verify

1. **TalentTrack → Player Rate Cards** — new menu entry. Dropdown picks a player. Pick one who has evaluations and the card renders immediately below.
2. **TalentTrack → Players → (open any player) → "Rate card" tab** — same card, embedded.
3. Pick a player with 5+ evaluations across multiple dates. Headline shows Most recent, Rolling, All-time. Main table shows trend arrows. Click a main row — subs expand. Trend chart renders as a multi-line plot. Radar overlays the last 3 evaluations.
4. Set Vanaf = 3 months ago. Rate card filters. Headline numbers update.
5. Set Type = Match only. Numbers and charts now reflect only match evaluations.
6. Wissen — back to unfiltered.

## Out of scope (slated for future Epic 2 sprints)

- **Team rate card** — aggregate of all players in a team. Sprint 2B.
- **Comparative views** — player A vs player B side by side. Sprint 2C.
- **Attendance integration** — "player's rate card during the matches they actually played" etc. Later.
- **Export / PDF / print** — rate card → paper. Later.
- **Player-facing dashboard rate card** — logged-in player sees their own card on the player dashboard. Might slot in next or later.
- **Sortable/exportable list columns from rate card data** — later.

## Design notes worth recording

- **Two entry points, one component.** `PlayerRateCardView::render()` is called from both `PlayerRateCardsPage::render()` and `PlayersPage::render_form()` (when `?tab=ratecard`). Adding a third entry point later (Epic 2 roadmap: a coach dashboard) is additive — new page, same render call.
- **Filters are GET params, not POST.** Bookmarkable URLs. Refresh-safe. Same pattern used across the rest of the plugin.
- **Trend detection's split-halves approach** was chosen over linear regression deliberately. Regression gives a slope that's hard to communicate ("slope +0.03 per evaluation" isn't meaningful to a coach). Split-halves gives a discrete signal (better / worse / same) that matches how humans actually reason about "are they improving."
- **Chart.js 4.x via CDN.** No bundling yet. If any customer reports CDN restrictions, we'll ship a bundled copy in a later sprint.
- **Canvas height fixed at 300px.** Looks right on desktop, still readable on tablet. Mobile display isn't a target for the admin surface (most admins work from desktop).
- **Subcategory rollups filter on the same date/type range as mains.** This matters when a coach starts using subs partway through the season — the subcategory breakdown respects the filter so they don't see "Short pass: 3.2 (from 2 evaluations)" alongside "Tactical: 3.8 (from 40 evaluations)" without understanding why one count is so small.
