# TalentTrack v3.104.4 — Analytics tab on player profile (#0083 Child 4)

Fourth child of #0083 (Reporting framework). Adds the discovery surface that closes the "users have to know there's a separate Analytics page" gap from the spec problem statement: a coach looking at Lucas can now ask "how is Lucas doing on attendance" by clicking the new tab on the player file.

## What landed

### `Modules\Analytics\Frontend\EntityAnalyticsTabRenderer`

Reusable component that renders a KPI grid for `(scope, entity_id)`. Three pieces:

1. **KPI selection** — `KpiRegistry::forEntity($scope)` returns every KPI scoped to that entity type, filtered to the persona's `context`:
   - Parent on their child's profile → `PLAYER_PARENT` only.
   - Coach (anyone with `tt_view_evaluations` or `tt_view_player_notes`) → `COACH` + `PLAYER_PARENT`.
   - HoD / Admin (`tt_edit_settings`) → all three contexts.
2. **Per-card value** — `KpiResolver::value( $kpi_key, [ <scope>_id_eq => $entity_id ] )` runs the KPI scoped to this entity. Threshold flagging: red headline when `goalDirection` + `threshold` apply and the value falls on the wrong side.
3. **Click-through** — each card is a link to `?tt_view=explore&kpi={key}&filter_<scope>_id_eq={entity_id}`. The dimension explorer (Child 3) opens scoped to this entity automatically.

CSS-grid layout (`auto-fit, minmax(220px, 1fr)`) wraps to single-column on phones and stretches to 5+ columns on wide desktops. Value formatting matches the explorer: percent / minutes / rating units render with their suffixes.

### Player detail view tab

`FrontendPlayerDetailView::tabs()` adds an `'analytics'` entry. The tab list is only extended when the analytics renderer class is loadable (defensive against module-disable scenarios — if the Analytics module is disabled the tab disappears entirely rather than rendering an error page).

The dispatch arm in `render()` calls `EntityAnalyticsTabRenderer::render( 'player', $player_id )`.

## What's NOT in this PR

- **Team-detail and activity-detail tab integrations.** They need the same wiring on more complex page layouts; deferred to a follow-up so the player surface (the highest-leverage one) lands first.
- **KPI count badges on the tab.** The existing `PlayerFileCounts::for()` infrastructure could light up an "alerts" count when threshold-flagged KPIs exist; deferred.
- **Central analytics surface** at `?tt_view=analytics` — Child 5.
- **Export + scheduled reports** — Child 6.

## Affected files

- `src/Modules/Analytics/Frontend/EntityAnalyticsTabRenderer.php` — new.
- `src/Shared/Frontend/FrontendPlayerDetailView.php` — adds the `'analytics'` tab + dispatch arm.
- `languages/talenttrack-nl_NL.po` — 1 new msgid.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## Translations

1 new translatable string ("No analytics available for this entity yet."). The tab label "Analytics" was already in the .po from earlier shipped surfaces. Every label inside the rendered KPI cards is wrapped in `__()` from the KPI registrations and surfaces here unchanged.

## Player-centricity

This child closes the discovery loop. Sprint 1's fact registry indexes "what we can ask"; Sprint 2's KPI platform answers "what's interesting to ask"; Sprint 3's explorer drills into specifics. Sprint 4 brings all of that to the surface where the user already is — the player profile. A coach reviewing Lucas's progress now has analytics one tap away. The cap-and-matrix gates that protect the player record extend automatically through the KPI's `context` field.
