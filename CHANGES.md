# TalentTrack v4.1.5 — Analytics explorer drilldown to fact rows (closes #874)

## Scope

Second of three follow-ups under #0083 Child 3. When the user picks no group-by, the explorer now renders a paginated table of the **underlying** fact rows below the chart — same data the chart visualises, in row form. Pilot users can move from headline → trend chart → individual rows in one screen.

## Changes

### `FactQuery::rows()` + `FactQuery::countRows()` (new)

Two new public methods on the analytics query engine:

```php
public static function rows( string $factKey, array $filters = [], int $limit = 50, int $offset = 0 ): array
public static function countRows( string $factKey, array $filters = [] ): int
```

Both reuse the existing `applyFilters` / tenancy / time-column-join semantics from `execute()`, just without aggregation. `rows()` SELECTs `f.id AS _id` plus every dimension's expression aliased by its key; orders by `time DESC, id DESC`. `countRows()` runs the same WHERE clause through `SELECT COUNT(*)` — capped at 5,000 to match the existing outer cap on `run()`.

### `FrontendExploreView::renderDrilldownTable()` (new)

Rendered below the "Pick a dimension above" prompt when `$group_by === ''`.

- Columns: one per fact dimension. FK values resolved to labels via `DimensionValueResolver` so players show as names, teams as team names, etc.
- Row link: derived from `Fact::entityScope`:
    - `player` → `?tt_view=players&id=…`
    - `team` → `?tt_view=teams&id=…`
    - `activity` → `?tt_view=activities&id=…`
    - Built via `RecordLink::detailUrlForWithBack()` so the `tt_back` pill returns the user to the same explorer URL.
- Pager: `&page=N`, 50 rows per page. Carries every other current query param so KPI + filters + page round-trip on share.
- Empty state: `.tt-empty` panel when zero rows.

### Placeholder

Footer text drops "drilldown to fact rows" from the remaining-follow-ups list. One follow-up remains: PDF export (#875).

## Out of scope

- Inline row editing — always read-only; clicking the row link is the way in.
- CSV export of the drilldown specifically — the existing `?action=export_csv` exports the aggregated rows; if a "raw drilldown CSV" is wanted, that's a follow-up (see #874 body).
- Time-series chart (already shipped in #873).
- PDF export (covered by sibling #875).

## Verification

- Open `?tt_view=explore` on an attendance KPI with no group-by → drilldown table renders with player / team / status / month columns + a per-row link to the player profile.
- Apply a date filter → table re-pages from row 1; total count drops.
- Page 2 → `&page=2` in URL; back-pill returns to the page 1 view (no), but reload reproduces page 2 exactly.
- Pick a group-by → drilldown table disappears; group-by table appears (existing behaviour).
- Filter to a window with no rows → `.tt-empty` panel.

## Versioning

Patch bump (4.1.4 → 4.1.5). Same epic-feature series as the chart ship (#873).

## Closes

- #874 — Analytics explorer — drilldown to fact rows
