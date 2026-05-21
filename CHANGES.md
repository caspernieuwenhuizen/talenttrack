# TalentTrack v4.0.7 — Player Compare: header layout + radar + trend chart fixed (closes #878)

## Pilot report

> compare players, the layout has issues. the radar is the same for the two players (which is impossible). also the trend in graph is missing a line for the overall rating, only including the main categories

## Root causes

Three defects in `FrontendComparisonView` (and one in the stats service it pulls from), shipped together since they share the same view.

### 1 — Header column-stack

The per-player header (avatar + name) was rendering vertically on desktop. `.tt-fcompare-cell` (the table-cell container) had no `display: flex`, so its child `tt-fcompare-headerplayer` — which carries `flex-direction: row` — never got a flex context. The header div also redundantly had `tt-fcompare-label` on it, which a sibling rule sets to `display: block`, undoing any row layout that did apply.

### 2 — Radar shows the wrong player

`renderChartScripts()` was iterating per player slot but reading `$radar_sets[0]` for every slot. Result: both columns rendered Player A's radar. The data structure from `PlayerStatsService::getRadarSnapshots()` is keyed by player ID and returns `{labels, datasets: [{label, values}]}` (associative — last entry = most recent snapshot), so the consumer needs to read `$radar_sets[$pid]` and pull the latest via `end($snap['datasets'])`.

### 3 — Trend chart missing aggregate line

`PlayerStatsService::getTrendSeries()` returned one line per main category (Technique, Tactics, Physical, Mental, …) but no aggregate. The compare view's trend tab was a tangle of thin lines per player with nothing to read at a glance. Pilot expected the overall trend on top, with the per-category breakdown beneath as supporting detail.

## Fix

### CSS (`FrontendComparisonView` inline styles)

```css
.tt-fcompare-cell {
    display: flex;
    align-items: center;
    /* … existing rules … */
}
```

### Header div — drop the redundant `tt-fcompare-label`

```php
// before:
echo '<div class="tt-fcompare-label tt-fcompare-headerplayer">' . …;
// after:
echo '<div class="tt-fcompare-headerplayer">' . …;
```

### Radar consumer — key by player ID, take the latest snapshot

```php
// before:
$snap = $radar_sets[0] ?? null;
// after:
$snap = $radar_sets[ $pid ] ?? null;
if ( $snap && ! empty( $snap['datasets'] ) ) {
    $latest = end( $snap['datasets'] );
    $values = $latest['values'] ?? [];
}
```

### Trend — prepend an "Overall" series

After populating the per-main-category series, compute the per-date mean across all points and prepend it as the first series:

```php
$overall_points = array_fill( 0, count( $labels ), null );
foreach ( $labels as $idx => $_ ) {
    $vals = [];
    foreach ( $series as $ser ) {
        $pt = $ser['points'][ $idx ] ?? null;
        if ( $pt !== null ) $vals[] = (float) $pt;
    }
    if ( count( $vals ) > 0 ) {
        $overall_points[ $idx ] = round( array_sum( $vals ) / count( $vals ), 2 );
    }
}
array_unshift( $series, [
    'main_id' => 0,
    'label'   => __( 'Overall', 'talenttrack' ),
    'points'  => $overall_points,
] );
```

## Scope

Pure presentation + one service-layer aggregation. No schema change, no REST contract change, no capability change. The new "Overall" series is identified by `main_id = 0` (no main category has id 0) so any other consumer of `getTrendSeries()` can ignore it or treat it as the aggregate.

## Verification

- Compare page renders the player header row horizontally at desktop and at 360px.
- Each player column's radar shows that player's latest snapshot (test by comparing two players with different main-category profiles).
- Trend chart leads with an "Overall" line; per-main-category lines render beneath it.

## Closes

- #878 — Player Compare: layout + radar + trend chart issues
