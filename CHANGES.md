# TalentTrack v3.109.6 — Custom widget builder Phase 5: cap layer + per-widget cache + audit log + clear-cache (#0078 Phase 5)

Phase 5 of #0078 Custom widget builder. Hardens what Phases 2-4 shipped: dedicated cap layer, per-widget transient cache with operator-configurable TTL, audit-log entries on every save / publish / delete, manual clear-cache button, and source-cap inheritance at render time so a viewer without `tt_view_evaluations` can't see an evaluations-backed custom widget.

## What landed

### New caps + matrix entity

Two new caps registered via `LegacyCapMapper` bridging to a new `custom_widgets` matrix entity:

- `tt_author_custom_widgets` → `custom_widgets:change` (author + edit)
- `tt_manage_custom_widgets` → `custom_widgets:create_delete` (admin tier)

`MatrixEntityCatalog` registers the entity label so the operator-facing matrix admin shows "Custom widgets" as a row. `config/authorization_seed.php` grants `head_of_development` rc[global] and `academy_admin` rcd[global].

### Top-up migration `0077_authorization_seed_topup_custom_widgets`

Backfills existing installs with the two new tuples. Mirrors the 0063 / 0064 / 0067 / 0069 / 0074 pattern: walks `config/authorization_seed.php` and `INSERT IGNORE`s every `(persona, entity, activity, scope_kind)` row whose entity is `custom_widgets`. Existing rows including operator-edited grants stay untouched. Idempotent — safe to re-run on already-backfilled installs.

Per `feedback_seed_changes_need_topup_migration.md`: adding rows to the seed alone doesn't reach existing installs because migration 0026 only seeds on fresh install or via the admin "Reset to defaults" button.

### `CustomWidgetsModule::ensureCapabilities()`

Seeds the bridging caps onto `administrator` + `tt_club_admin` + `tt_head_dev` so role-based callers (admin pages, REST gates) keep working alongside the matrix layer. Mirrors the persona-dashboard editor cap-ensure pattern from #0060 sprint 2.

### Per-widget transient cache

`Modules\CustomWidgets\Cache\CustomWidgetCache` — per-widget cache:

- Keys: `tt_cw_data_<uuid>_<user_id>_v<version>` transients.
- The `_v<version>` suffix makes invalidation O(1) without transient-prefix scanning (WP doesn't support that reliably across object cache backends). Bumping the per-uuid version counter in `wp_options` orphans every prior cache entry — they expire naturally on TTL.
- Per-user keying so a future viewer-aware filter (e.g. "my players") doesn't bleed across users.
- TTL of 0 disables caching entirely (operator escape hatch from a slow-moving widget). Caps at `WEEK_IN_SECONDS` per WP transient hard-cap.

`CustomWidgetRenderer::renderWidget()` reads from cache before calling `$source->fetch()`; on miss, fetches and writes back. Save / update / archive automatically flush the per-widget cache via the service layer.

### Source-cap inheritance

Each shipped data source gains a `requiredCap()` method:

| Source | Required cap |
|---|---|
| `players_active` | `tt_view_players` |
| `evaluations_recent` | `tt_view_evaluations` |
| `goals_open` | `tt_view_goals` |
| `activities_recent` | `tt_view_activities` |
| `pdp_files` | `tt_view_pdp` |

The renderer detects via `method_exists` (additive — Phase 1 didn't ship the method on the `CustomDataSource` interface, so plugin-author sources without it stay backward-compatible) and refuses to fetch when the viewer can't read the underlying records. Empty stub renders ("You do not have access to this data.") instead of leaking rows.

This satisfies the spec's DOD: *"Cap-revoked viewer (no `tt_view_evaluations`) can't see an evaluations-backed custom widget."*

### Audit log

`CustomWidgetService` writes three discriminated events to `tt_audit_log`:

- `custom_widget.created` — on every successful create.
- `custom_widget.updated` — on every successful update.
- `custom_widget.archived` — on every successful archive.

Each carries `(uuid, name, data_source_id, chart_type)` payload. Audit failures never block the operator's action — the recorder is wrapped in a try/catch that swallows exceptions.

### Manual clear-cache

List view gains a per-row "Clear cache" button (admin-post + per-row nonce → `CustomWidgetCache::flush()` → redirect with `tt_msg=cache_cleared`). The existing REST endpoint `POST /custom-widgets/{id}/clear-cache` now actually flushes — Phase 2 shipped the route shape, Phase 5 wires the body.

### REST + admin page caps

`permRead()` and `permWrite()` on the REST controller accept either `tt_author_custom_widgets` (the Phase 5 cap) or `tt_edit_persona_templates` (back-compat fallthrough during the upgrade window so installs that haven't run migration 0077 yet stay functional). The admin page uses the same fallback pattern via a `canManage()` helper.

## What's NOT in this PR (Phase 6 only)

- **Phase 6 — Docs + i18n + README link**. `docs/custom-widgets.md` (EN+NL).

## Translations

Zero new NL msgids — copy reuses existing strings ("Clear cache" already exists for #0083 scheduled-reports cache flush; "You do not have access to this data." reuses an existing string), and the matrix label ("Custom widgets") was added by Phase 3.

## Notes

No new wp-cron schedules. No license-tier flips. The new schema migration is `0077` (one above the Phase 2 widget table at `0076`).
