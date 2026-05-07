<!-- audience: admin, dev -->

# Custom widgets

Compose your own dashboard widgets without writing code. Pick a registered data source (Players, Evaluations, Goals, Activities, PDP), choose columns and filters, pick a chart type, save — then drag the widget onto any persona dashboard from the editor palette.

Custom widgets live next to the shipped widget catalogue. They obey the same persona context, mobile-priority, and bento-grid sizing rules; the difference is that an admin authors them at runtime instead of a developer shipping them in code.

## When to use a custom widget

Use a custom widget when the question you want a dashboard to answer doesn't already have a shipped widget for it. Examples:

- *"Top 10 active players in the U13 squad"* — table over `players_active`, filter `team_id`.
- *"Average evaluation rating per coach in the last 30 days"* — KPI over `evaluations_recent`, aggregation `avg_overall`.
- *"Goals per principle"* — bar chart over `goals_open`, group-by principle.
- *"PDP files signed off this season"* — KPI over `pdp_files`, filter `season_id` + `status=signed_off`.

If the answer can already be reached via a shipped widget (KPI cards, data tables) plus existing data sources, prefer the shipped path — fewer moving parts.

## Authoring a widget

The builder lives at **TalentTrack → Custom widgets** (also reachable from the Configuration tile-landing). Cap-gated on `tt_author_custom_widgets`. Six steps:

1. **Source** — pick which data source the widget reads. Each source declares its columns, filters, and aggregations; everything downstream re-renders when the source changes.
2. **Columns** — for table widgets, pick which columns appear. KPI / bar / line widgets ignore the column list (they show one aggregated value or one series).
3. **Filters** — narrow down the rows. Filters are source-declared (e.g. `team_id`, `date_from`, `status`); leave one blank to skip it.
4. **Format** — pick a chart type: Table, KPI (single big number), Bar chart, or Line chart. Non-table types also need an aggregation (count / avg / sum / distinct).
5. **Preview** — saves the widget as a draft and renders the actual data the dashboard would show.
6. **Save** — give the widget a name (1-120 chars) and pick a cache TTL in minutes (default 5; set to 0 to disable caching).

## Surfacing a widget on a persona dashboard

1. Open **TalentTrack → Dashboard layouts** (cap `tt_edit_persona_templates`).
2. In the editor's palette, drag the **Custom widget** tile onto the canvas.
3. In the right-hand properties panel, the *Data source* dropdown lists every saved custom widget by name. Pick yours.
4. Save / publish the persona layout.

## How permissions work

Custom widgets respect two layers:

- **Authoring cap** — `tt_author_custom_widgets` (HoD + admin); `tt_manage_custom_widgets` adds delete authority (admin only). Both bridge to a `custom_widgets` matrix entity, so per-club tweaks happen in the matrix admin.
- **Source-cap inheritance at render time** — every shipped data source declares the underlying read cap (`players_active` → `tt_view_players`, `evaluations_recent` → `tt_view_evaluations`, etc.). A viewer without the underlying cap sees a "You do not have access to this data." stub instead of the rendered widget. This is enforced in `CustomWidgetRenderer`; you can't bypass it by composing a custom widget.

A parent without `tt_view_evaluations` can't see an evaluations-backed custom widget on someone else's dashboard, even if an admin placed it there. The same gate that protects the underlying record list protects the widget.

## Caching

Each custom widget has a per-widget transient cache keyed on `(uuid, user_id)`:

- **TTL is per-widget** — set on the Save step; default 5 minutes.
- **TTL of 0 disables caching** — the renderer fetches fresh on every render. Use this for fast-moving widgets where the cache cost outweighs the save.
- **Save / update / archive auto-invalidate** the cache.
- **Manual flush** — every row in the list view has a "Clear cache" button. The button bumps the per-uuid version counter, orphaning every prior cache entry; subsequent renders fetch fresh.

The version-counter pattern means cache flush is O(1) regardless of how many users have rendered the widget.

## Audit log

Every save / update / archive writes a row to `tt_audit_log`:

| Action | Carried payload |
|---|---|
| `custom_widget.created` | uuid, name, data_source_id, chart_type |
| `custom_widget.updated` | (same) |
| `custom_widget.archived` | (same) |

Audit is one of the lookups for "who changed this widget last" investigations; the dashboard editor itself is also audited (#0060), so the full path from custom-widget edit to persona-template publish is reconstructable.

## Out-of-scope (today)

These deliberately don't ship:

- **Free-text SQL access** — too risky on a multi-tenant SaaS. Authors compose against the registered data source classes only.
- **Visual SQL builder** — significant additional UI; revisit if data-source classes prove too rigid.
- **Per-version widget history** — the audit log captures who/when/what changed, but there's no rollback in v1.
- **Pie / donut / radar charts** — already covered by shipped widgets where useful.
- **Cross-source joins** — each widget reads exactly one data source. Operators wanting joined data ask for a new data source class to be added.
- **Author-defined custom data sources via UI** — only PHP-registered sources in v1.
- **Per-row drilldown links from a custom widget table → record detail page** — v1 ships read-only; clickable rows defer to a v2 follow-up.

## Adding a new data source (developer task)

A plugin author can register additional sources by implementing `\TT\Modules\CustomWidgets\Domain\CustomDataSource` and calling `CustomDataSourceRegistry::register()` from a `boot()` hook:

```php
add_action( 'init', function () {
    \TT\Modules\CustomWidgets\CustomDataSourceRegistry::register( new MyCustomSource() );
}, 25 );
```

The interface declares five methods: `id()` (snake_case stable id used as the `data_source_id` foreign key), `label()` (translatable picker label), `columns()` (list of `[key, label, kind]`), `filters()` (list of `[key, label, kind, options?]`), `fetch( $user_id, $filters, $column_keys, $limit )`, `aggregations()` (list of `[key, label, kind, column?]`).

Sources should also implement `requiredCap(): string` so the renderer's source-cap inheritance kicks in. The interface doesn't require it (additive after Phase 1), but every shipped source has it. A source with no cap returns the empty string.

Inside `fetch()` the source MUST scope to `\TT\Infrastructure\Tenancy\CurrentClub::id()` and apply demo-mode scope. The registry can't enforce that — it doesn't know which `tt_*` table the source reads.

## Feature flag

The whole module is opt-in via `tt_custom_widgets_enabled`. As of v3.109.7 (Phase 6 closes #0078) the flag stays **off by default** so existing installs aren't surprised by a new admin page on next upgrade; flip it on per club with:

```
wp option update tt_custom_widgets_enabled 1
```

…or set the same key on `tt_config` per club. Once the flag is on, the admin page lights up at TalentTrack → Custom widgets, the REST routes register, and the editor palette gains the *Custom widget* tile.
