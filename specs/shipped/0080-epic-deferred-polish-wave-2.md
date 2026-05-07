# #0080 — Deferred polish wave 2

## Problem

The last seven months of ships left a long tail of "deferred to follow-up" items scattered across SEQUENCE.md. An audit on 2026-05-04 found ~20 distinct deferrals; most have either (a) silently shipped via later work, (b) been escalated into a formal epic (#0010 / #0075 / #0076), or (c) rotted out as no longer relevant. **Ten items remain genuinely open and small enough to bundle.** This spec collects them so they can ship as four short waves rather than as ten one-off PRs that would each fight a parallel-PR version collision.

The gist of what's open:

- Four license-feature gates declared in `FeatureMap::DEFAULT_MAP` since v3.86.1 but never actually enforced at the surface that grants the feature (one drops out — `s3_backup` — until an S3 destination actually ships).
- Two demo-data UX polish items from v3.90.2.
- Two `UserComparisonPage` extensions from v3.89.0.
- One mobile-responsive layout for the `RateActorsStep` wizard from v3.78.0.
- One frontend lookup drag-reorder gap (wp-admin already has it via `DragReorder`).
- One per-tile "what gates this?" affordance on the matrix admin page.
- Sub-cap refactor on three REST controllers still using umbrella `tt_edit_settings`.
- One breaking sheet rename in the demo-data Excel template.

## Proposal

Ship as four small waves, each ~2–3h, each its own PR:

- **Wave A — License-feature gates wired (~2h).** Three of four. `s3_backup` dropped (no S3 destination ships yet — gate it when the destination ships).
- **Wave B — UX polish (~5–7h, can split across two PRs).** Demo wipe live preview, per-batch scope, comparison page extensions, mobile stack-rendering for `RateActorsStep`, frontend lookup drag-reorder.
- **Wave C — Architectural cleanup (~2–3h).** Per-tile "what gates this?" affordance, sub-cap refactor on three REST controllers.
- **Wave D — Demo-import sheet rename (~1h).** Hard rename `Sessions` → `Activities`, document in changelog.

Each wave has its own acceptance criteria and is shippable independently — one stuck wave doesn't block the others.

## Scope

### Wave A — License-feature gates

**A1 — `radar_charts` gate + visual refresh.** Surfaces that render radar SVGs (`QueryHelpers::radar_chart_svg()` consumers) wrap the call in a `LicenseGate::allows( 'radar_charts' )` check. Free-tier:

- The radar block is hidden.
- An `UpgradeNudge::inline()` notice replaces it: "Upgrade to Standard for radar charts" + a CTA to `tt-account&tab=plan`.

While we're in there: **the radar charts get a visual refresh**. Today they look stark and unfinished. The brief: keep the SVG (no library dependency), but pick up the persona-dashboard token system (`--tt-bg-soft`, `--tt-line`, `--tt-accent`, `--tt-ink`) so the chart inherits whatever Casper themes the install with; soften the axis lines; add a subtle filled-area gradient under the data polygon; render the category labels with the same typography stack as the rest of the dashboard. Investigate at least one credible reference (e.g. Spotify Wrapped's per-genre radars) before locking the new look.

**A2 — `undo_bulk` gate.** The bulk-operation safety pre-backup keeps running for everyone (it's defensive code, free for all). What gets gated is the **post-bulk in-app undo link + the `bulk_undo` admin-post handler itself**:

- `BulkUndoNotice::render()` checks `LicenseGate::allows( 'undo_bulk' )` — Standard+ sees the "Undo last bulk action" link. Free-tier sees a paywall variant: "Bulk-undo is part of the Standard plan. Upgrade to recover from accidents."
- `BackupSettingsPage::bulk_undo()` re-checks the gate; direct-URL access on free-tier returns 402 with `license_feature_undo_bulk` via the existing `LicenseGate::enforceFeatureRest()` shape.

**A3 — `partial_restore` gate.** The "Partial restore" link in `BackupSettingsPage::render()` (line 312) stays visible across all tiers, but clicks land on a paywall on free:

- Append `?paywall=partial_restore` discriminator to the URL when the user lacks the feature.
- The partial-restore picker view checks the gate at the top of `BackupSettingsPage::renderPartialRestorePicker()` (line ~577) and returns the paywall variant if blocked.
- `BackupSettingsPage::handleExecutePartialRestore()` re-enforces at the handler entry; the bypass-by-direct-URL path returns 402.

Why surface the link and paywall on click rather than hide entirely: makes the upgrade path discoverable, and the v3.86.1 ship pattern uses this for trial/scout/team-chemistry features already.

**A4 — `s3_backup` — DROPPED.** No S3 destination ships in the codebase today. Gate it when the destination ships, not now.

### Wave B — UX polish

**B1 — Demo wipe live JS preview of cascade-total.** `DemoDataPage::renderWipeForm()` already shows a static cascade row count per category from `DemoDataCleaner::categoryCounts()`. Add lightweight JS that listens on the six checkboxes and updates a "Will wipe ~N rows across <M categories>" caption next to the submit button. Server-side cascade math runs once; the JS replays the union per checkbox state on the client. No new REST endpoint — the cascade map is already in the localized JS payload.

**B2 — Demo wipe per-batch (`batch_id`) scope.** Add a Batch dropdown above the six-checkbox grid populated from `DemoBatchRegistry::listBatches()`. Picking a batch narrows the wipe to rows tagged with that `batch_id` in `tt_demo_tags`. Default is "All batches" (today's behaviour). Cascade preview respects the batch filter. Useful when an operator runs the generator multiple times with different presets and wants to roll back just the last run.

**B3 — `UserComparisonPage` N-user + per-cap drill-down.**

- **N-user.** Replace the two `wp_dropdown_users` pickers with a multi-select (or a `wp_dropdown_users` + repeating "+Add another user" affordance — pick whichever lands cleaner; the comparison page already has a small footprint). The render columns scale: one `User A`, `User B`, … column per picked user, plus the diff column at the right. Diff highlights any row where the effectives don't all match across the selected set.
- **Per-cap drill-down.** Each row gets a small inline disclosure ("which scope row grants this?") that, when clicked, reveals the matrix entity + activity + scope_kind + scope_value tuple that resolved the matrix column for each user. Reads via `MatrixGate::canAnyScope()`'s existing introspection (extend if needed — currently returns bool; add a method that returns `(allowed, source_row_id, scope_kind, scope_value)`).

**CSV export — DROPPED** per the 2026-05-04 shaping. If asked again, ~30 min add-on.

**B4 — `RateActorsStep` mobile stack layout.** Today the rate-actors page renders the activity → players grid via existing `@media (max-width: 720px)` rules — works but feels like a desktop table awkwardly squashed. The fix:

- New stack-based mobile rendering: each player becomes its own card with the quick-rate categories laid out vertically inside the card.
- Existing 720px breakpoint stays as the trigger.
- No swipe UI (deferred — separate spec if Casper asks).
- Touch targets at the v3.50.0 / `inputmode` retrofit floor (48px, 8px gaps).
- Test on a real Moto G class device or Chrome dev-tools "Moto G Power" emulation.

**B5 — Frontend lookup drag-reorder.** The frontend lookup admin (`FrontendConfigurationView` lines 200–280) has a numeric `sort_order` input today, with the comment "Drag-reorder is intentionally deferred to v2 — sort_order is editable as a numeric field; the user didn't answer the v1 question". The wp-admin lookup admin already uses `DragReorder` (see `ConfigurationPage` lines 589/785). Reuse the same script; wire to the existing `LookupsRestController` sort-order PUT endpoint.

### Wave C — Architectural cleanup

**C2 — Per-tile "what gates this?" affordance on matrix admin.** On the matrix admin page (`MatrixPage::render()`), the "Used by: <tile-list>" reverse-lookup landed in v3.86.0. Extend each tile chip to be hoverable / clickable, surfacing a small popover that shows: the tile's `entity` declaration, the `cap` (if declared), the `cap_callback` source location (if declared), the matrix scope_kind that controls visibility, and a one-liner explaining the precedence ("matrix when active; cap as fallback; cap_callback as second fallback"). Helps operators when they ask "why does coach X see this tile but coach Y doesn't" — pairs with the v3.89.0 `UserComparisonPage`.

**C3 — Sub-cap refactor on three REST controllers.**

- **`ConfigRestController`** (lines 73 / 78): umbrella `tt_view_settings` / `tt_edit_settings` → resolve per-key. Currently the controller writes any `tt_config` key. Split into per-key sub-cap routing: `branding.*` → `tt_edit_branding`, `feature_toggles.*` → `tt_edit_feature_toggles`, `lookup.*` → `tt_edit_lookups`, `translations.*` → `tt_edit_translations`. Same `CapabilityAliases` roll-up keeps existing umbrella holders working.
- **`PdpFilesRestController`** (lines 77 / 109 / 241): three "is admin proxy" `tt_edit_settings` checks. Replace with `tt_edit_pdp` where the route is functionally a PDP edit, or with a more specific cap if the admin-proxy path is genuinely cross-cutting (audit each).
- **`ThreadsRestController`**: today's guards (`guardRead` / `guardPost`) sometimes fall back to `tt_view_settings` / `tt_edit_evaluations` (lines 198 / 222 / 228). Audit each fallback against the access-control matrix; replace umbrella admin proxies with the specific cap that the route actually needs.

### Wave D — Demo-import sheet rename

**C4 — `Sessions` → `Activities` sheet rename (hard).** The demo-data Excel importer (`SheetSchemas::all()` line 125) declares the activities sheet as `'sheet' => 'Sessions'`. Rename to `'Activities'`. The legacy "session" → "activity" vocabulary sweep finished in v3.81.0; this is the one stored-vocabulary leftover.

Hard rename per the 2026-05-04 shaping:

- `SheetSchemas::all()`'s `'sessions'` schema entry sets `'sheet' => 'Activities'`.
- `TemplateBuilder` exports the new sheet name.
- `ExcelImporter` reads only `Activities`. Workbooks with a `Sessions` sheet emit a clear blocker: "Sheet 'Sessions' was renamed to 'Activities' in v3.92.0 — re-download the template or rename the sheet."
- CHANGES.md notes this as a breaking change for in-flight workbooks; readme.txt mentions in the version note.
- `docs/demo-data-excel.md` (EN + NL) gets a one-paragraph migration callout at the top.

## Wizard plan

**Exemption — none of the waves create or modify record-creation flows.** Wave A gates render paths; Wave B polishes existing wizard step rendering, the wipe form, and the comparison admin page; Wave C touches caps and admin affordances; Wave D renames a sheet. No new wizard, no new wizard step.

## Out of scope

- **`s3_backup` license gate** — defer until an S3 destination actually ships (no surface to gate today).
- **CSV export from `UserComparisonPage`** — `add if asked` was the original deferral; not asked again.
- **17 `cap_callback` tile refactor** (v3.86.0 deferral) — audit on 2026-05-04 confirmed all remaining `cap_callback` tiles already declare a matrix `entity`. The callback is now the fallback for matrix-dormant installs (`tt_authorization_active = 0`); not dead code; no refactor needed.
- **Translations columns on `tt_roles` / `tt_functional_roles` / `tt_eval_categories`** — belongs in #0010 multi-language epic when SaaS multi-locale is needed.
- **Playwright Firefox / WebKit / parallel workers / programmatic auth helper** — belongs in #0076 (Playwright coverage expansion).
- **Design system tokens (templates rebuild + tertiary/ghost/icon button variants + media/dashboard/utility/accessibility tokens)** — belongs in a #0075 follow-up.
- **Swipe-card mobile UI for `RateActorsStep`** — significant CSS + JS investment; the stack-based layout in B4 captures most of the value.
- **Wp-admin lookup drag-reorder** — already shipped via `DragReorder` in `ConfigurationPage` lines 589/785.

## Acceptance criteria

### Wave A

- [ ] Free-tier user opens any radar surface (player rate card, comparison view, persona dashboard radar widget) → radar block is hidden, replaced by an `UpgradeNudge::inline()` notice with a CTA to `?page=tt-account&tab=plan`.
- [ ] Standard / trial / Pro user sees the radar — and the new look ships across every consumer (`QueryHelpers::radar_chart_svg()` returns the refreshed SVG).
- [ ] Radar uses persona-dashboard CSS tokens (`--tt-bg-soft`, `--tt-line`, `--tt-ink`, `--tt-accent`); axis lines softened; data polygon has a subtle filled-area gradient.
- [ ] Free-tier user opens the bulk-undo notice on a list page → notice content reads "Bulk-undo is part of the Standard plan…" instead of the existing "Undo last bulk action" link.
- [ ] Free-tier user POSTs `tt_bulk_undo` directly → 402 response with `license_feature_undo_bulk` code.
- [ ] Free-tier user clicks "Partial restore" link in the backup detail page → lands on a paywall view, not the partial-restore picker.
- [ ] Free-tier user POSTs `tt_execute_partial_restore` directly → 402 response with `license_feature_partial_restore` code.
- [ ] Test in trial mode (`TrialState::start()`) → all three features unlock immediately, gates pass.

### Wave B

- [ ] Demo wipe form: ticking a checkbox updates the "Will wipe ~N rows across <M categories>" caption within 50 ms (no server roundtrip).
- [ ] Demo wipe form: untick all → caption reads "Nothing selected." Submit button disabled.
- [ ] Demo wipe form: a Batch dropdown appears above the grid, populated from `DemoBatchRegistry::listBatches()`. Default "All batches" matches today's behaviour.
- [ ] Picking a batch narrows the cascade preview + the actual wipe to that batch's `tt_demo_tags` rows.
- [ ] `UserComparisonPage`: third user can be added via "+ Add another user" link. Up to 5 users supported (cap to keep the table readable).
- [ ] `UserComparisonPage`: each capability row has a disclosure that, when clicked, reveals "matrix entity:activity / scope_kind=team / scope_value=42 / source row #N" for each compared user.
- [ ] `RateActorsStep` on a 360px viewport: each player renders as a card with quick-rate categories stacked vertically. No horizontal scroll. All controls ≥ 48 × 48 px with ≥ 8 px spacing.
- [ ] Frontend lookup admin: list rows show a drag handle column; dragging persists `sort_order` via the existing `LookupsRestController` PUT endpoint. Numeric input retained as a fallback for keyboard-only users.

### Wave C

- [ ] Matrix admin page: "Used by: <tile-list>" tile chips are now interactive — hover/click reveals a popover showing entity / cap / cap_callback source / scope_kind / precedence.
- [ ] `ConfigRestController` route handlers gate per-key on the matching sub-cap. Existing `tt_edit_settings` holders keep working via `CapabilityAliases`.
- [ ] `PdpFilesRestController` admin-proxy `tt_edit_settings` checks (3 sites) replaced with a more specific cap. No regression for existing admin users.
- [ ] `ThreadsRestController` `tt_view_settings` / `tt_edit_evaluations` fallbacks audited; replaced or documented why they stay.

### Wave D

- [ ] `SheetSchemas::all()`'s `'sessions'` entry has `'sheet' => 'Activities'`.
- [ ] Newly downloaded demo-data template `.xlsx` has a sheet titled `Activities` (no `Sessions` sheet).
- [ ] Importing a workbook with the legacy `Sessions` sheet name emits a clear blocker mentioning the rename.
- [ ] CHANGES.md flags this as a breaking change for in-flight workbooks.
- [ ] `docs/demo-data-excel.md` + `docs/nl_NL/demo-data-excel.md` get a migration callout.

## Notes

- **Test strategy.** Each wave is small enough to verify by hand on the dev install. License gates should also be tested under `DevOverride` for each tier (`Free` / `Standard` / `Pro`) to confirm gates flip. Playwright covers the radar paywall path only if the existing rate-card smoke test asserts on visible chart elements (it doesn't today; that's #0076 territory).
- **NL translations.** Each wave adds new operator-facing strings. Keep the nl_NL.po edit in the same PR per the standing rule. Estimate ~5–8 new msgids per wave.
- **Version sequencing.** All four waves bump per-ship (e.g. `v3.92.0` for Wave A, `v3.92.1` for Wave B, etc.). Each is its own changelog entry in `CHANGES.md` (replaces) + appended row in `readme.txt` + Done row in `SEQUENCE.md`.
- **Visual refresh on radar (A1) — risk.** Don't let the visual rework block the gate ship. If the new look isn't ready when the gate is, ship the gate first with the existing radar visual, then ship the visual refresh as Wave A2.
- **B4 mobile RateActorsStep — Casper feedback loop.** Test on his actual phone (he's the operator in residence) before locking the stack layout. The breakpoint feels right at 720 px today, but the card-stack layout might want a different breakpoint depending on category count.
- **Wave D rename — pilot install.** Ask before this ships whether anyone has a workbook in flight that uses the `Sessions` name. If yes, defer Wave D until they've imported the last legacy workbook. Hard rename means no soft fallback.
- **Why no `cap_callback` refactor.** The 2026-05-04 audit confirmed all 8 surviving `cap_callback` tiles already declare a matrix `entity`. The callback now serves matrix-dormant installs (`tt_authorization_active = 0`) as a safety fallback. Removing the callbacks would break those installs without a migration. The original v3.86.0 deferral is no longer relevant.

## Estimates

| Wave | Items | Estimate |
| - | - | - |
| A | A1 (gate + visual refresh), A2, A3 | 3–4h |
| B | B1, B2, B3, B4, B5 | 5–7h |
| C | C2, C3 | 2–3h |
| D | C4 | ~1h |
| **Total** | **10 items** | **~11–15h** |

Compressed under TalentTrack's typical 1/2.5 ratio: **~5–7h actual** spread across four PRs.
