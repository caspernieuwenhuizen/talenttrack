# TalentTrack v3.104.5 — Central analytics surface + matrix entity (#0083 Child 5, desktop_only)

Fifth child of #0083 (Reporting framework). Adds the central exploration surface for users who think "I want to explore data" rather than "I want to look at a specific player." Reachable at `?tt_view=analytics`. Cap-gated; HoD + Admin by default.

## What landed

### `Modules\Analytics\Frontend\FrontendAnalyticsView`

Renders an academy-wide KPI grid pulling every `ACADEMY`-context KPI from `KpiRegistry::byContext()`. Each card click-throughs to `?tt_view=explore&kpi={key}` (the dimension explorer from Child 3). Threshold flagging mirrors the entity tab (Child 4) — red headline when the KPI declares `goalDirection` + `threshold` and the value falls on the wrong side.

CSS-grid responsive layout (`auto-fit, minmax(220px, 1fr)`). Card markup is identical to the per-entity tab — same component vocabulary across both surfaces.

Two-column layout with entity selector tiles on the left lands in a follow-up; today the page surfaces academy-wide KPIs only. Coaches reach analytics through the per-entity tabs (Child 4) on the players + teams + activities they have access to; they don't get the central exploration view because their analytical work is bounded to their teams.

### New cap + matrix entity

- **`tt_view_analytics`** in `LegacyCapMapper::MAPPING` — bridges to `analytics:read`.
- **`analytics` matrix entity** seeded in `config/authorization_seed.php` with `r[global]` for both `head_of_development` and `academy_admin`.
- **`MatrixEntityCatalog`** registers the entity label so the operator-facing matrix admin surfaces "Analytics" as a row that operators can edit.

### Top-up migration `0074_authorization_seed_topup_analytics`

Backfills existing installs with the two new tuples. Same INSERT IGNORE pattern as 0063 / 0064 / 0067 / 0069. Idempotent — safe to re-run on already-backfilled installs.

### Surface registrations

- **`mobile_class = desktop_only`** in `CoreSurfaceRegistration::registerMobileClasses()` — phone-class user agents see the polite "Open on desktop" page from #0084 Child 1.
- **Slug ownership** `analytics` → `AnalyticsModule` in `CoreSurfaceRegistration::registerSlugOwnerships()` for the module-disabled friendly notice.
- **Dispatch arm** `analytics_central_slugs = ['analytics']` added in `DashboardShortcode::render()`.

## What's NOT in this PR

- **Two-column layout with entity selector** on the left (player / team / activity / season / scout tiles → entity-instance picker). Per spec the layout is the canonical end-state; today's grid is the simpler shape that ships now. Layout extension follows when an operator asks.
- **Cross-context KPI surfacing on the central page.** The view shows `ACADEMY`-context KPIs only. `COACH`-context KPIs surface on per-entity tabs (Child 4) where they belong; a future tabbed extension on the central view could expose them too.
- **Export + scheduled reports** — Child 6 ships export across the framework.

## Affected files

- `src/Modules/Analytics/Frontend/FrontendAnalyticsView.php` — new.
- `src/Shared/Frontend/DashboardShortcode.php` — dispatch arm.
- `src/Shared/CoreSurfaceRegistration.php` — slug ownership + `mobile_class = desktop_only`.
- `src/Modules/Authorization/LegacyCapMapper.php` — `tt_view_analytics` mapping.
- `src/Modules/Authorization/Admin/MatrixEntityCatalog.php` — `analytics` label.
- `config/authorization_seed.php` — HoD + Admin seed rows.
- `database/migrations/0074_authorization_seed_topup_analytics.php` — new.
- `languages/talenttrack-nl_NL.po` — 3 new msgids.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## Translations

3 new translatable strings covering the central-view copy.

## Player-centricity

Five children in, the loop closes — facts → KPIs → explorer → entity tab → central surface. The central view is the answer to "I want to explore data" without a specific player in mind. HoD reviewing the academy's quarterly numbers lands here. Coach with a focused question still goes player-first via Child 4. Both paths converge on the same explorer, share the same KPIs, and obey the same cap-and-matrix gates.
