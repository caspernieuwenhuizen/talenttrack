# TalentTrack v3.109.7 — Custom widget builder Phase 6: docs (EN+NL) + module-docblock cleanup, closes #0078

Sixth and final phase of #0078 Custom widget builder. **Closes #0078.** Documentation + ship-completion polish; no functional change.

## What landed

### `docs/custom-widgets.md` + `docs/nl_NL/custom-widgets.md`

Full operator + developer guide. Both files marked `audience: admin, dev`. Sections:

- **When to use a custom widget** — concrete examples mapped to the five reference data sources, plus the rule of thumb to prefer shipped widgets when an existing one already answers the question.
- **Authoring a widget** — the six-step builder flow (Source → Columns → Filters → Format → Preview → Save) and what each step persists.
- **Surfacing on a persona dashboard** — drag the *Custom widget* tile onto the editor canvas, pick the saved widget by name from the data-source dropdown.
- **Permissions** — two-layer model: authoring cap `tt_author_custom_widgets` (HoD + admin) + render-time source-cap inheritance (parent without `tt_view_evaluations` can't see an evaluations-backed widget on someone else's dashboard).
- **Caching** — per-widget transient cache keyed on `(uuid, user_id)` with operator-set TTL; manual flush via the "Clear cache" button + REST endpoint; cache flush is O(1) via the version-counter pattern.
- **Audit log** — `custom_widget.{created, updated, archived}` rows in `tt_audit_log` with the `(uuid, name, data_source_id, chart_type)` payload.
- **Out-of-scope** — explicit list of what deliberately doesn't ship in v1: free-text SQL, visual SQL builder, per-version widget history, pie/donut/radar charts, cross-source joins, UI-defined data sources, per-row drilldown links.
- **Adding a new data source (developer)** — recipe for plugin authors to register a `CustomDataSource` class via `CustomDataSourceRegistry::register()` from a `boot()` hook. Notes the `requiredCap()` convention (additive after Phase 1, every shipped source has it) + the tenancy + demo-mode requirements every implementation must honour.
- **Feature flag** — `tt_custom_widgets_enabled` stays default-off; flip per club with `wp option update tt_custom_widgets_enabled 1` or via `tt_config`.

### Module docblock cleanup

`CustomWidgetsModule` docblock now reflects the closed-epic state: phase-by-phase ship history with versions (Phase 1 v3.106.2 → Phase 6 v3.109.7) and the rationale for keeping the feature flag default-off (existing installs aren't surprised by a new admin page on upgrade).

### Spec frontmatter

`specs/0078-epic-custom-widget-builder.md` — `status: ready` → `status: shipped`, with the per-phase version trail in `shipped_in:`.

### SEQUENCE.md

- In-progress section updated to mark the epic as closed and list every phase's ship version.
- Ready section row removed (the epic line that pointed at "~95h remaining" is no longer accurate).
- Per-phase Done rows already in place from the prior PRs (Phases 2-5).

## What's NOT in this PR

- **Zero code change.** Phase 6 is documentation + cleanup only.
- **Feature flag stays default-off.** Operators flip it on per club; the spec's DOD says "default off so beta installs can opt in" and we're keeping that posture for upgrades.

## Translations

Zero new translatable strings — every doc string is doc-only (Markdown rendered by the `Help & Docs` page) and not surfaced through `__()`. The 33 NL msgids that the rest of the epic shipped (across Phases 3-5) are already in `nl_NL.po`.

## Notes

This closes #0078. Future work in this area lands as new specs (e.g. v2 drilldown links, custom data sources via UI, additional chart types).
