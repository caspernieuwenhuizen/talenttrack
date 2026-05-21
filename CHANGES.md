# TalentTrack v4.1.2 — Behaviour-pending dashboard widget (closes #871, partial #867)

## Pilot context

Sub-ship B under epic #867. Coaches have no signal that behaviour is part of weekly hygiene — a coach who finishes the season without recording a behaviour rating produces zero data for the player-status calc's behaviour input, silently degrading the composite score to `unknown` (grey) without ever surfacing the gap.

This sub-ship adds a coach + HoD dashboard widget that pulls the work to the user, instead of waiting for them to find it.

## Changes

### New `behaviour_pending` preset on `DataTableWidget`

Three columns: Player, Team, Since (days since last rating, or "never"). 10 rows max. Empty state is positive: *"Up to date — all your players have a recent behaviour rating."*

### New `BehaviourPendingSource`

`src/Modules/PersonaDashboard/TableSources/BehaviourPendingSource.php`. Implements `TableRowSource`.

- Reads `tt_config.behaviour_staleness_days` (default 14).
- Scopes by cap:
    - `tt_manage_players` (HoD + admin) → all active players in the club.
    - `tt_rate_player_behaviour` only (coach) → players on teams the coach is assigned to, via `QueryHelpers::get_teams_for_coach()`.
    - Anyone else → empty set (widget renders the positive empty state, which is fine — they wouldn't see the widget on their template anyway).
- Correlated subquery for the latest `rated_at` per player; HAVING clause filters to `latest IS NULL OR DATEDIFF(today, latest) > N`.
- Ordered: never-rated first, then oldest-rated first.
- Row link points at `?tt_view=players&id=N&action=log-behaviour`. The `action` param is reserved for sub-ship #870's hero popover — once #870's JS wires up auto-open, clicking a row will drop the user into the behaviour popover on landing. Today the param is silently ignored, which is the documented pre-A behaviour from the issue spec.

### Registered in `CoreWidgets`

```php
TableRowSourceRegistry::register( 'behaviour_pending', new BehaviourPendingSource() );
```

### Wired into the templates

`CoreTemplates::coach()` — new slot at row 6, XL (full width), height 2:

```php
$grid->add( new WidgetSlot( 'data_table', 'behaviour_pending', Size::XL, 0, 6, 2, 41 ) );
```

`CoreTemplates::headOfDevelopment()` — new slot at row 9, XL height 2. Navigation tile rows shifted from `y=9 + floor(i/4)` to `y=11 + floor(i/4)` to clear the new widget (height-2 widget consumes rows 9-10).

### `DataTableWidget` catalogue + preset config

Added `behaviour_pending` to both `dataSourceCatalogue()` (so widget editors can pick it) and `presetConfig()` (so the widget knows its columns + see-all link + empty message).

## Out of scope

- A separate "Potential review pending" widget for HoDs — quarterly cadence doesn't justify a 14-day staleness widget. Future enhancement if asked.
- Bulk-rate from the widget — that's #872, sub-ship C.
- Hero popover auto-open on `action=log-behaviour` — out of scope for B; the param is already plumbed for A to consume in a future polish.

## Verification

- Coach with 4 players, 2 rated yesterday, 2 unrated for 30+ days → widget shows the 2 unrated rows.
- HoD → widget shows all stale players across all teams in club scope.
- Scout (no `tt_rate_player_behaviour`) → source returns empty; widget renders the positive empty state.
- Row click → lands on the player profile (pre-#870 wiring: hero buttons visible, popover not auto-opened).
- Mobile 360px: rows tap-targets ≥ 48px (inherited from `.tt-pd-row-link`); no horizontal scroll.

## Versioning

Patch bump (4.1.1 → 4.1.2). Still inside the `4.1.x` epic-feature series opened by #869.

## Closes

- #871 — Behaviour discoverability — B: dashboard pending widget
- Partial: #867 — one sub-ship remaining (#872, bulk grid)
