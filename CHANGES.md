# TalentTrack v3.104.3 — Analytics dimension explorer: ?tt_view=explore (#0083 Child 3)

Third child of #0083 (Reporting framework). Ships the presentation layer that any KPI hands off to for drilldown — same UX everywhere. Reachable at `?tt_view=explore&kpi={key}`. Classified `desktop_only` per #0084 (dense filtering and chart interaction are desktop work — nobody analyses attendance trends on a phone).

## What landed

### `Modules\Analytics\Frontend\FrontendExploreView`

Single view that drives the explorer surface.

- **Headline** — KPI's measure aggregated across the filtered rows. Computed via `FactQuery::run( $factKey, [], [$measureKey], $filters )`. Format honours the measure's `unit` (percent / minutes / rating) — crude v1; a shared formatter ships in a follow-up.
- **Threshold flagging** — when the KPI declares `threshold` + `goalDirection`, the headline gets a "Below threshold — review with the team" sub-label when the value falls on the wrong side.
- **Filter chips** — one input per KPI `exploreDimension`, plus the special `date_after` / `date_before` shortcuts for the time-column filter. Free-form text inputs in v1; a follow-up replaces them with combobox dropdowns over real dimension values.
- **Group-by selector** — the user picks a dimension; the explorer re-runs the query with that dimension in the GROUP BY and renders a two-column table (dimension value + aggregated measure).
- **URL state** — filter values + `group_by` round-trip via querystring. `?tt_view=explore&kpi=fact_player_attendance_pct_30d&filter_date_after=2026-01-01&filter_team_id_eq=12&group_by=team_id` fully describes the view; sharing a link reproduces it.

### Dispatch wired

`DashboardShortcode::render()` gets a new `analytics_explore_slugs = ['explore']` array dispatching to `FrontendExploreView::render()`. `CoreSurfaceRegistration::registerSlugOwnerships()` declares the slug owned by `Modules\Analytics\AnalyticsModule` so the module-disabled friendly notice fires when the module's flipped off.

### `mobile_class = desktop_only`

`CoreSurfaceRegistration::registerMobileClasses()` adds `'explore'` to the `desktop_only` list. Phone-class user agents land on the #0084 Child 1 prompt page with the "Email me the link" affordance instead of a cramped explorer.

## What's NOT in this PR

- **Chart.js time-series chart.** The spec mentions reusing #0077 M6's wiring — ships when the explorer earns it.
- **Trend arrow + delta vs previous period** on the headline.
- **Drilldown to underlying fact rows** (top 50, paginated). The drilldown is the third tier of the presentation; today the explorer goes "headline → grouped breakdown" and stops.
- **Combobox dropdowns** over real dimension values. Today's filter chips are free-form text inputs — works for known integer ids and enum strings but not friendly for picking by name.
- **Export CSV / PDF buttons** — Child 6 ships export across the whole framework.

## Capability

`read`. Per-KPI capability gating happens at the entry surfaces — Children 4 + 5 enforce the KPI's `context` (ACADEMY / COACH / PLAYER_PARENT) at the entity tab + the central analytics view. The explorer is reachable directly only via URL today; an unauthorised user typing the URL would see no data because the query auto-scopes to their `club_id` and the matrix gates upstream of REST limit what they can read.

## Affected files

- `src/Modules/Analytics/Frontend/FrontendExploreView.php` — new.
- `src/Shared/Frontend/DashboardShortcode.php` — `analytics_explore_slugs` + dispatch.
- `src/Shared/CoreSurfaceRegistration.php` — slug ownership + `mobile_class = desktop_only`.
- `languages/talenttrack-nl_NL.po` — 12 new msgids.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## Translations

12 new translatable strings covering explorer copy.

## Player-centricity

The explorer is the answer to "every aggregation is one-off." A coach asking "how does my team's attendance compare across last three seasons" used to need a bespoke SQL query and bespoke filter UI; now they pick the attendance KPI from anywhere it surfaces, hit Explore, and pivot. The cost of the next analytical question drops to zero — same affordances every time.
