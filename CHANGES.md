# TalentTrack v3.110.175 — Coach-dashboard KPI deep-links honour their compute window (closes #771)

## Pilot report

Chat 2026-05-20:

> When I click on the attendance for my team KPI card it should show only those activities contributing to the number I see

## Root cause

`src/Modules/PersonaDashboard/Kpis/MyTeamAttendancePct.php`:

- `compute()` rolls over a **28-day window** — `act.session_date >= today - 28 days AND act.session_date <= today`. The percentage on the card is honest about its window.
- `linkView(): string { return 'activities'; }` returns only a view slug.
- `AbstractKpiDataSource::linkUrl()`'s default builder then rebuilds a bare `?tt_view=activities` URL with **no filters**.
- The destination renders the activities list's default ordering — every activity the coach has access to, all-time.

So the card promises "28 days" and the destination shows "everything." Same shape on `MyTeamAvgRating` (90-day window for evaluations).

## Fix

Both KPIs now:

1. Declare a `private const WINDOW_DAYS` (28 for attendance, 90 for rating).
2. Expose a `private static function windowDates(): array` returning `[ 'from' => 'Y-m-d', 'to' => 'Y-m-d' ]`.
3. `compute()` consumes those instead of inline `strtotime` calls.
4. Override `linkUrl(RenderContext $ctx): string` to add `filter[date_from]` against the SAME `from` date (+ `filter[date_to]` for attendance; rating leaves the upper bound off because evaluation lists are "newest first" by default and there's no cap on the compute window's right edge either).

```php
// MyTeamAttendancePct after the fix:
private const WINDOW_DAYS = 28;

public function compute( int $user_id, int $club_id ): KpiValue {
    // …
    [ 'from' => $from, 'to' => $to ] = self::windowDates();
    $start = $from . ' 00:00:00';
    $end   = $to   . ' 23:59:59';
    // …query uses $start / $end as before…
}

public function linkUrl( RenderContext $ctx ): string {
    [ 'from' => $from, 'to' => $to ] = self::windowDates();
    return add_query_arg(
        [ 'filter' => [ 'date_from' => $from, 'date_to' => $to ] ],
        $ctx->viewUrl( $this->linkView() )
    );
}

private static function windowDates(): array {
    return [
        'from' => gmdate( 'Y-m-d', strtotime( '-' . self::WINDOW_DAYS . ' days' ) ),
        'to'   => gmdate( 'Y-m-d' ),
    ];
}
```

## Why this is architecturally correct

The `WINDOW_DAYS` constant + `windowDates()` helper is a single source of truth — the window value cannot drift between the KPI's compute result and its deep-link's filter. Same principle as the v3.110.173 dispatcher refactor: when two co-dependent values must stay in sync, collapse them into one.

The destination REST endpoints already accepted `filter[date_from]` + `filter[date_to]` against the matching `session_date` / `eval_date` columns. This ship just wires the KPI cards to use them. No REST change, no view change, no schema change.

The `linkUrl()` extension point already existed (`PdpVerdictsPending` uses it for `filter[status]=open`). The pattern is established — `linkView()` stays as the back-compat default; `linkUrl()` is the override when query args are needed.

## Behaviour

- Click "My team attendance %" on the coach dashboard → `?tt_view=activities&filter[date_from]=<today-28d>&filter[date_to]=<today>`. Activities list renders with the date filter pre-filled; row count matches the KPI denominator universe.
- Click "My team avg rating" → `?tt_view=evaluations&filter[date_from]=<today-90d>`. Evaluations list renders pre-filtered to the last 90 days.
- No other KPI is affected. Academy-wide and player-context KPIs were already correct (most have no rolling window) or land on overviews where a filter wouldn't apply.

## Files touched

- `src/Modules/PersonaDashboard/Kpis/MyTeamAttendancePct.php` — `WINDOW_DAYS` constant, `windowDates()` helper, `linkUrl()` override.
- `src/Modules/PersonaDashboard/Kpis/MyTeamAvgRating.php` — same pattern, 90-day window.
- `talenttrack.php` — 3.110.174 → 3.110.175.
- `readme.txt` — Stable tag + changelog entry.
- `CHANGES.md` — this file.

No DB migration, no REST shape change, no new i18n strings, no auth change.

## Test plan

- [ ] On coach dashboard, click "My team attendance %" KPI → lands on activities list with date range pre-set to last 28 days
- [ ] Date-range filter dropdown shows the populated From / To values
- [ ] Row count is in the same neighbourhood as the KPI's denominator (won't be exact — KPI counts attendance rows, list counts activities — but the universe should match)
- [ ] On coach dashboard, click "My team avg rating" KPI → lands on evaluations list with date filter set to last 90 days
- [ ] PdpVerdictsPending click still works (`filter[status]=open`) — regression check on the pre-existing `linkUrl()` consumer
- [ ] Academy-wide KPIs that have a `linkView` but no `linkUrl` override still work (e.g. clicking "Active players total" → players list unfiltered, as before)
