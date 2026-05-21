# TalentTrack v3.110.203 — Lookup editor switches to a mobile-collapsible master-detail layout (closes #830)

## Why

Before this release, the Configuration → Lookups editor for a single category rendered linearly:

1. Table of rows at the top.
2. Add/Edit form stacked underneath.
3. Editing a row reloaded the page with `?edit=N` and scrolled the form into view.

On a category with ~5+ rows plus the six-locale translation block (which expands to ~12 inputs per row when `show_desc=true`), the form sat 1.5–2 screens down. An academy operator had to scroll to discover the editor existed, then scroll up to confirm a save took effect, then scroll back down to edit the next row. Pilot report (2026-05-20): *"the form is too far down to be useful when I'm working through a dozen rows."*

## What changed

`FrontendConfigurationView::renderLookupCategoryEditor()` is rewritten as a two-pane layout:

- **Left rail (`.tt-lookup-md-rail`)**: lists every row. Drag-handle, optional colour swatch, name, sort order, delete pill. Selected row highlighted. "+ Add new" button sticky at the top of the rail.
- **Right pane (`.tt-lookup-md-pane`)**: the edit-or-add form. Same fields as before (name, sort_order, optional description, optional pill colour, per-locale translations block). Save / Cancel pinned to the bottom of the pane via `FormSaveButton::render()` with a `cancel_url` per CLAUDE.md § 6.

Layout breakpoint: 768px. Above → two-column grid (`minmax(0, 2fr) minmax(0, 3fr)`). Below → stacked. On mobile a row tap reveals the pane and hides the rail; a `← Back to list` pill in the pane header returns to the rail. `?edit=N` deep-links also open the pane on initial load.

## Row click = in-place form populate, no page reload

Each rail row carries its full payload as `data-row-*` attributes:

- `data-row-name`, `data-row-sort`, `data-row-desc`, `data-row-color`, `data-row-locked`
- `data-row-tx` — JSON blob of every translation (`locale → field → value`) for that row.

A small JS handler reads those attributes on click and writes them into the form's inputs by name selector + the existing `[data-tt-tx-locale][data-tt-tx-field]` per-locale selectors. The URL is updated via `history.replaceState` so a refresh keeps the selected row, but there's no full navigation.

## Translations bulk-load

To make in-place populate fast and keep the page render cheap, a new private helper `loadTranslationsForLookupIds()` does **one** `SELECT entity_id, field, locale, value FROM tt_translations WHERE entity_type='lookup' AND entity_id IN (…)` per page render, regardless of how many rows are in the rail. The result is keyed `id → locale → field → value` and shared between the server-side render path (currently-editing row) and the JSON blob baked into the rail rows.

This replaces N+1 calls to `TranslationsRepository::allFor()` (one per row) that would have made the new layout O(rows × locales) at render time.

## Acceptance ticked

- [x] Two-pane layout on ≥768px, stacked on <768px
- [x] Row click loads form values in-place (no full page reload)
- [x] Translation block scrolls inside the right pane (`max-height: 70vh; overflow-y: auto`), not the whole page
- [x] Drag-reorder (`DragReorder::renderScript`) still works in the rail — same `data-tt-sortable` hook
- [x] Save/Cancel pinned at the bottom of the right pane via `FormSaveButton::render()` with `cancel_url`
- [x] All 18 currently-registered categories render correctly (10 original + 8 from v3.110.201) with both `show_desc=true` and `show_color=true` variants
- [x] No regression on the Translate engine button (still calls `/translations/preview` and fills the per-locale fields)

## What's unchanged

- REST contract (`/lookups/<type>` POST / `/lookups/<type>/<id>` PUT / DELETE).
- Cap gate (`tt_edit_settings`).
- Validation, sanitisation, audit logging — all repository-side.
- The `?edit=N` URL pattern as a deep-link entry point.
- Drag-reorder endpoint + wp-admin caller (the same `DragReorder::renderScript` wires it up).

## How to test

1. Open Configuration → Lookups → any category (try *Evaluation types* — 6 rows × 6 locales × 2 fields is a heavy case).
2. ≥768px: two-pane layout. Click any row → form on the right populates immediately, no flash, no reload. URL gains `?edit=<id>`. Translation inputs show the row's per-locale values.
3. Click "+ Add new" → form blanks; URL drops `?edit`; Save button label changes to "Add row".
4. <768px (DevTools mobile preset, 360px): rail fills the viewport. Tap a row → rail hides, pane fills the viewport with a "← Back to list" pill. Tap Back → rail returns.
5. Save / Cancel — Save lands; Cancel returns to the base category URL (drops `?edit`). Both pinned at the bottom of the pane footer.
6. Drag-reorder: grab a row's `⋮⋮` handle, drop in a new position. Server save fires (same path as before); reload preserves the new order.
7. Delete: click the row's red `×`. Confirmation prompt. On confirm, reload, row gone.

## Effort

~120 lines of PHP delta, ~110 lines of inline CSS, ~80 lines of JS for the row-click / blank-form / mobile pane-toggle handlers. One new private helper. Single file touched (`FrontendConfigurationView.php`).
