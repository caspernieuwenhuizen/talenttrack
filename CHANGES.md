# TalentTrack v3.109.1 — Analytics deferred follow-ups: team + activity Analytics tabs + explorer Export CSV (#0083 follow-up)

Three deferred items from the #0083 Reporting framework epic now lit up. The #0083 Child 4 ship at v3.104.4 deferred the team + activity tab integrations ("they need the same wiring on more complex layouts than the player detail's tab list"); the #0083 Child 6 ship at v3.106.1 deferred the explorer Export CSV button ("callable but no UI yet"). All three are direct, low-risk hookups of existing infrastructure — no schema, no caps, no policy.

## What landed

### Team-detail Analytics teaser

`FrontendTeamDetailView` gains a private `renderAnalyticsTeaser( int $team_id )` and one new call site after `renderChemistryTeaser()` in the team's hero render path. The teaser delegates to `EntityAnalyticsTabRenderer::render('team', $team_id)` which already supports the `team` scope (filter key `team_id_eq`).

Defensive guard: `class_exists('\\TT\\Modules\\Analytics\\Frontend\\EntityAnalyticsTabRenderer')` short-circuits when the Analytics module is disabled, so the team detail page renders identically to v3.109.0 in that mode.

### Activity-detail Analytics section

`FrontendActivitiesManageView::renderDetail()` gains the same shape — defensive `class_exists` guard, `<section class="tt-activity-analytics">`, then `EntityAnalyticsTabRenderer::render('activity', $session->id)`. Activity scope was already wired in the renderer's `filterKeyForScope()` switch as `activity_id_eq`.

Both surfaces inherit the renderer's persona-context filtering (parent → `PLAYER_PARENT` only; coach → `COACH` + `PLAYER_PARENT`; HoD/Admin → all contexts) and the threshold-flagged red headline behaviour from Child 4 — no new policy code.

### Explorer Export CSV button

`FrontendExploreView::render()` detects `?action=export_csv` early and routes to a new private `streamCsv( Kpi $kpi )` helper. The helper:

- builds the same `$filters` + `$group_by` that the on-screen view consumes (so a shared `&action=export_csv` link reproduces the export exactly),
- calls `CsvExporter::raw( $kpi->factKey, $dim_keys, [ $kpi->measureKey ], $filters, $kpi->label )` from #0083 Child 6's UTF-8-BOM `fputcsv` streamer,
- emits `nocache_headers()` + `Content-Type: text/csv; charset=UTF-8` + `Content-Disposition: attachment; filename="<kpi-key>-<YYYY-MM-DD>.csv"`,
- echoes the body and `exit;`s before the dashboard chrome can wrap the response.

The button itself sits above the Group-by selector inside the explorer's main render path and round-trips the full URL state via `add_query_arg()`. The deferred-follow-ups blue-info block at the bottom of the explorer drops "CSV" from its remaining list; PDF stays.

## What's NOT in this PR

- **Bulk migration of the 26 legacy KPIs** to fact-driven `Kpi` declarations — the resolver fallback path keeps them working until that follow-up ships.
- **Remaining 49 of the spec's "top 15 per entity" set** — 6 of 55 shipped at v3.104.2, 49 still queued.
- **Static-analysis test** for "every player/team-FK table is registered as a fact" — Child 2 follow-up.
- **PDF export** — DomPDF lands with the player evaluation PDF use case under #0063 Export module foundation.
- **XLSX export** — PhpSpreadsheet lands with #0063 evaluations Excel use case.
- **Chart.js time-series chart on the explorer** — ships when the explorer earns it.
- **Trend arrow + delta vs previous period on the entity teasers** — the renderer surfaces today's value only.

## Translations

One new NL msgid (Export CSV button label). The "Analytics" section heading reuses an existing msgid.

## Notes

No schema changes. No new capabilities. No new wp-cron schedules. No license-tier flips. The activity tab inherits `tt_view_activities` already gated at the page level; the team tab inherits the same role gating the team detail page already enforces; the explorer download honours the same `read` cap the explorer page already requires.

Renumbered v3.108.1 → v3.108.2 → v3.109.1 across multiple rebases as parallel-agent ships took the v3.108.1 / v3.108.2 / v3.108.3 / v3.108.4 / v3.108.5 / v3.109.0 slots.
