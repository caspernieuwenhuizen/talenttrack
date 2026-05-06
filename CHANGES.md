# TalentTrack v3.103.0 — Deferred polish wave 2: A residual + B + C bundled (#0080)

Bundles waves A residual, B, and C of #0080 into a single ship at the operator's direction. Wave D (Sessions sheet rename) is deferred pending pilot-install confirmation about in-flight `.xlsx` workbooks per spec note 161.

## Wave A residual — radar visual refresh

`QueryHelpers::radar_chart_svg()` picks up the persona-dashboard token system (`--tt-bg-soft`, `--tt-line`, `--tt-ink`, `--tt-accent`) with hardcoded fallbacks for surfaces that don't enqueue the dashboard stylesheet. A per-dataset `<radialGradient>` (centre dense, edge faint) renders under each polygon; gradient ids use `wp_generate_password(6, false, false)` to stay unique when multiple radars share a page. Axis lines softened (stroke-width 0.5 vs. the prior 0.75). Function signature unchanged — every existing consumer (rate cards, comparison view, persona-dashboard widget, evidence packets, etc., 11 sites) inherits the new look.

## Wave B1 — demo wipe live preview

`DemoDataPage::renderWipeSection()` builds a `$per_batch_counts` map (batch_id → category counts, plus `__all__`) and emits it via `data-tt-batch-counts="<json>"` on the form. Vanilla JS (~50 lines, translatable templates from `wp_json_encode(__())`) listens on the six checkboxes and the new batch dropdown and renders one of:

- "Will wipe ~%d rows across %d categories." (selection + non-zero total)
- "Nothing selected." (no checkboxes ticked)

Updates within 50 ms; no server roundtrip. The submit button stays interactive throughout — disabling on "nothing selected" was deferred (the operator can still get the visible feedback via the caption).

## Wave B2 — per-batch wipe scope

`DemoBatchRegistry::listBatches()` returns one row per distinct `batch_id` with the first-seen `created_at` timestamp + tag count, most-recent first. `allEntityIds()` gains an optional `?string $batch_id` parameter; null preserves the all-batches behaviour for back-compat callers.

`DemoDataCleaner::wipeData()` accepts a `?string $batch_id` and threads it through entity-id resolution; the corresponding `tt_demo_tags` deletion query also filters by `batch_id` so other batches' tags survive a scoped wipe. `categoryCounts()` accepts the same parameter so the live preview is per-batch accurate.

A new `<select id="tt-demo-batch-id">` ("All batches" + per-batch options labeled with the timestamp + tag count) sits above the cascade grid. `handleWipeData()` reads `$_POST['batch_id']`, treats `'all'` / `''` as null, and passes through to `wipeData()`.

## Wave B3 — `UserComparisonPage` N-user + per-cap drilldown

The two `wp_dropdown_users` pickers are replaced with up to 5 user slots. Slots 1 and 2 always show; "+ Add another user" reveals slots 3–5 one at a time (vanilla JS, no new framework). On submit the form posts `user_ids[]`; the legacy `user_a` / `user_b` URL params are still accepted so older bookmarks / help docs keep working.

The header card grid shifts to `grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))` so 5 user cards wrap gracefully. The capability table grows one column per picked user; the diff column flags any row where the effective state isn't unanimous.

Each capability row renders a `<details>` disclosure under each user's effective-state cell:

> matrix entity:activity / scope_kind=team / scope_value=42 / source row #N

The drilldown reads via the new `MatrixGate::describeAccess()` — same resolution path as `canAnyScope()` but returns the resolution metadata `(allowed, persona, scope_kind, scope_value, source_row_id)`. `MatrixRepository::rowIdFor()` reads from a parallel `idCache` populated alongside the existing module-class cache during `loadCache()`.

## Wave B4 — `RateActorsStep` mobile stack layout

The inline `<table>` is replaced with a CSS-grid card layout (`.tt-rate-grid` / `.tt-rate-row` / `.tt-rate-control` / `.tt-rate-input` / `.tt-rate-notes` / `.tt-rate-skip`). Mobile-first defaults: each player card stacks the quick-rate categories vertically, label on top, control beneath. `@media (min-width: 720px)` flips each row to a 180px-label + control two-column grid that mirrors the prior desktop layout.

Touch targets at the v3.50.0 floor: `.tt-rate-input` is `width: 96px; min-height: 48px; font-size: 16px`; `.tt-rate-notes` is `width: 100%; min-height: 72px`; the skip-row checkbox is 22×22 with the label `min-height: 48px`. `inputmode="numeric"` retained on the rating input. Explicit `<label for="...">` everywhere, replacing the previous `<th>`-as-pseudo-label pattern.

CSS lives in `FrontendWizardView::enqueueWizardStyles()` (the inline-style block already used for every other wizard step) so the rules ship without a new enqueue.

## Wave B5 — frontend lookup drag-reorder

`FrontendConfigurationView` lookup rows gain a drag-handle column (`<td class="tt-drag-handle">⋮⋮</td>`); the `<table>` gets `tt-sortable-table`, the `<tbody>` gets `data-tt-sortable="1"`, each `<tr>` gets `data-id="$row->id"`, and the order cell gets `tt-sort-order-cell`. After the table the view calls `\TT\Shared\Admin\DragReorder::renderScript('lookup', $type)` — the same SortableJS implementation the wp-admin lookup page has used since v3.0. Drag persists via the existing cap-gated AJAX endpoint at `wp_ajax_tt_drag_reorder`. The numeric `sort_order` input stays as a keyboard-only fallback.

## Wave C2 — matrix admin per-tile gate popover

`MatrixEntityCatalog::consumersOf()` is extended to surface tile gate metadata: `entity_declared` (string|null — present when the tile declared `entity` directly), `cap_callback` (string|null — described via `ReflectionFunction` for closures, `Class::method` for array callables, `function_name()` for string callables), and `view_slug`.

Each tile chip in the "Used by:" line on `MatrixPage::render()` is now a `<button class="tt-tile-chip">` with a sibling `<span class="tt-tile-popover" hidden>`. JS toggles popover visibility on click, closes others to keep one open at a time, and dismisses on click-outside / Escape. The popover shows:

- entity (with "(declared on tile)" or "(via cap mapping)" qualifier)
- cap
- mapped activity
- cap_callback source (reflectable) or "—"
- view slug (when present)
- precedence one-liner: "matrix when active; cap as fallback; cap_callback as second fallback."

Inline CSS in `<style>` block alongside the existing dirty-pill styles; no new asset enqueue.

## Wave C3 — sub-cap refactor on three REST controllers

### `ConfigRestController`

Replaces the umbrella `tt_view_settings` / `tt_edit_settings` permission_callbacks with per-key sub-cap routing.

`KEY_AREA_MAP` exact-matches each writable key to a sub-cap area: `branding` (every branding key + colour), `rating_scale` (`rating_min/max/step`), `feature_toggles` (`show_legacy_menus`). The `persona_dashboard.*` prefix routes to `feature_toggles` (it's a feature toggle in spirit). Unmapped keys still route to the umbrella so future drift falls back gracefully.

The route gates check "user holds at least one relevant area cap or the umbrella". Inside the handlers, each key is checked individually: `get_config()` filters out keys the user can't view; `save_config()` returns `keys_skipped[]` listing keys the user lacked permission on.

`CapabilityAliases` roll-up means academy admins (who hold every sub-cap) still pass the umbrella check; coaches who hold only `tt_edit_branding` can now write branding keys without being granted the full settings umbrella.

### `PdpFilesRestController`

Four "is admin?" proxy checks (`current_user_can('tt_edit_settings')` at lines 77, 109, 241, 250) collapse into a single `hasGlobalPdpAccess($activity)` helper. The helper checks, in order:

1. **Matrix grant** — `MatrixGate::can($user, 'pdp_file', $activity, SCOPE_GLOBAL)`. The precise semantic ("user has unrestricted PDP access").
2. **WordPress site admin** — `manage_options`. Portable fallback for installs whose matrix is dormant or partially seeded.
3. **Legacy umbrella** — `tt_edit_settings`. Preserved for back-compat with v3.0 callers.

Behaviour is identical for academy admins (matrix grants `pdp_file/rcd/global` to head_of_development and academy_admin) and for WP-administrator users; the historical "umbrella by accident" coupling is removed.

### `ThreadsRestController`

`delete()` and `canSeePrivate()` admin-proxy checks (`tt_view_settings` at lines 198 + 222) replaced with the same matrix-aware pattern: `hasGlobalThreadAccess($user, $activity)` checks `MatrixGate::can($user, 'thread_messages', $activity, SCOPE_GLOBAL)` → `manage_options` → `tt_view_settings`. The `|| user_can($user, 'tt_view_settings')` clause at line 228 (already returned `true` two lines above for admins) is dropped — dead code.

## What's NOT in this PR

- **Wave A1 / A2 / A3 license-feature gates.** Already shipped before the audit caught them — see SEQUENCE.md for the per-gate ship reference. The Wave A residual covered here is the visual-refresh half of A1 only.
- **Wave D Sessions sheet rename.** Deferred pending pilot-install confirmation about in-flight `.xlsx` workbooks per spec note 161. Will ship once the operator confirms no legacy workbooks are mid-flight.
- **CSV export from `UserComparisonPage`.** Spec dropped this on 2026-05-04 shaping; not asked again.

## Migrations

None. Every change is code-only; sub-cap roll-ups use `CapabilityAliases` already shipped in v3.71.0; matrix introspection reads from existing tables.

## Affected files

- `src/Infrastructure/Query/QueryHelpers.php` — radar SVG visual refresh.
- `src/Modules/DemoData/DemoBatchRegistry.php` — `listBatches()` + per-batch `allEntityIds()`.
- `src/Modules/DemoData/DemoDataCleaner.php` — per-batch `wipeData()` + `categoryCounts()`.
- `src/Modules/DemoData/Admin/DemoDataPage.php` — Batch dropdown, live preview JS, batch_id POST plumbing.
- `src/Modules/Authorization/MatrixGate.php` — `describeAccess()` + `firstScopeAssignment()`.
- `src/Modules/Authorization/Matrix/MatrixRepository.php` — `rowIdFor()` + parallel id cache.
- `src/Modules/Authorization/Admin/UserComparisonPage.php` — N-user picker, per-cap drilldown disclosures.
- `src/Modules/Authorization/Admin/MatrixEntityCatalog.php` — tile gate metadata on `consumersOf()`.
- `src/Modules/Authorization/Admin/MatrixPage.php` — tile-chip popover rendering + CSS/JS.
- `src/Modules/Wizards/Evaluation/RateActorsStep.php` — mobile-first card layout markup.
- `src/Shared/Frontend/FrontendWizardView.php` — `.tt-rate-*` CSS rules in the wizard inline stylesheet.
- `src/Shared/Frontend/FrontendConfigurationView.php` — drag-handle column + `DragReorder::renderScript('lookup', $type)` call.
- `src/Infrastructure/REST/ConfigRestController.php` — per-key sub-cap routing.
- `src/Modules/Pdp/Rest/PdpFilesRestController.php` — `hasGlobalPdpAccess()` helper, four sites updated.
- `src/Modules/Threads/Rest/ThreadsRestController.php` — `hasGlobalThreadAccess()` helper, two sites updated, redundant clause dropped.
- `languages/talenttrack-nl_NL.po` — 14 new NL msgids.
- `readme.txt`, `talenttrack.php`, `SEQUENCE.md` — version bump + ship metadata.
