<!-- type: feat -->

# Persona dashboard editor — collision detection + auto-reflow + alignment guides

## Problem

The persona-dashboard editor (`Configuration → Dashboard layouts`, shipped in #0060 v3.51.0, polished through v3.62.0 / v3.71.5 / v3.82.1) lets staff drag KPI cards and widgets onto a 12-column canvas with snap-to-grid, size presets, undo/redo, draft/publish/reset, mobile preview, and keyboard a11y. Two things still feel rough compared to professional dashboard builders (Notion, Power BI, Grafana):

1. **Slots can overlap.** `placeNewSlot` and `moveExistingSlot` write the dropped slot's `(x, y)` straight into `state.template.grid` without checking whether the destination cell already contains another slot. The CSS Grid renders both at the same coordinates and one visually clobbers the other; saving persists the overlap. The user has to manually drag the colliding slot out of the way.
2. **No alignment feedback while dragging.** Pointer drives the cell snap, but there's no indication that the dragged slot's left edge lines up with another slot's left edge, or that two slots share a centre line. Users hunt for clean rows / columns by eye.

Operator complaint surfaced indirectly during the v3.82.1 #8 fix (drop coords were ignored — fixed) and the v3.62.0 polish bundle (drag visual feedback added, but only as a generic highlight on the drop target). Now that the dropped slot lands where the cursor was, overlap is the next-most-visible problem.

## Proposal

Two coupled additions to `assets/js/persona-dashboard-editor.js`, both pure-vanilla extensions to the existing engine. No framework, no build step, no new dependencies — fits the `CLAUDE.md` § 2 mandate.

### (1) Collision detection + auto-reflow

After every `placeNewSlot` / `moveExistingSlot` / keyboard nudge, run a **layout pass** before `commit()`:

1. Walk `state.template.grid`. For each pair, check rectangle overlap on `(x, x+col_span) × (y, y+row_span)`.
2. If the dropped slot collides with one or more existing slots, **push** the colliders downward — set each collider's `y` to `dropped.y + dropped.row_span`. Cascade — a pushed slot may itself collide with the slot below; repeat until stable. Bounded depth = `grid.length` (no infinite loop possible).
3. **Compact** vertically — for each slot in row order, lower its `y` to the smallest value that doesn't introduce a new collision. Closes gaps left by the push pass and matches the Notion/Grafana "always-compact" feel.
4. Hero / task bands are single-slot lanes; they're outside the grid pass.

Algorithm is the same shape `react-grid-layout` uses internally (`compact()` + `moveElement()` with `preventCollision: false`); we don't need React for it.

### (2) Visual alignment guides

On `dragover`, while `state.dragging` is set:

1. Compute the dragged slot's projected position (in pixels from the canvas top-left, derived from the current cursor cell).
2. Walk `state.template.grid`. For each other slot, compute its left / right / centre-x / top / bottom / centre-y in pixels.
3. If the dragged slot's left/right/centre-x is within `SNAP_TOLERANCE_PX` (default 4px) of any other slot's left/right/centre-x, render a vertical 1px guide line at that x. Same for horizontal y guides.
4. Also emit guides for the canvas's own left edge, right edge, and centre-x.
5. Guides are absolutely-positioned `<div>`s appended to a `.tt-pde-guides` overlay layer; cleared on `dragend` / `drop`.

Snapping behaviour: when a guide is shown for an edge alignment within `SNAP_TOLERANCE_PX`, `gridCellFromEvent` adjusts the projected `x` to the aligned column so the drop lands exactly on the guide.

### (3) Animated reflow

Slots get `transition: transform 150ms ease` on `.tt-pde-slot`. The compact / push passes set the slot's `transform: translate(...)` for the new position; CSS animates the move. Honors `@media (prefers-reduced-motion: reduce)` per CLAUDE.md.

## Scope

- New module-internal helpers in `assets/js/persona-dashboard-editor.js`:
  - `slotsCollide(a, b)` — pure rectangle test.
  - `compactGrid(grid)` — sort by `(y, x)`, lower each slot's `y` to first non-colliding row.
  - `resolveCollisions(grid, droppedSlotId)` — push-down cascade rooted at the dropped slot.
  - `findNearestFreeSlot(grid, want, size)` — BFS outward from `(want.x, want.y)` for snap-to-nearest-available fallback when the dropped cell collides AND the user is holding `Shift` (default behaviour stays push-and-reflow; Shift = "find a gap instead").
  - `computeAlignmentGuides(draggedRect, otherRects, canvasRect, tolerance)` — pure function returning `[{axis, x|y, sources: ['left'|'right'|'centre-x'|'top'|'bottom'|'centre-y']}]`.
  - `renderAlignmentGuides(guides)` / `clearAlignmentGuides()` — DOM diff against the guides overlay.
- New CSS in `assets/css/persona-dashboard-editor.css`:
  - `.tt-pde-slot { transition: transform 150ms ease; }` (gated under `@media (prefers-reduced-motion: no-preference)`).
  - `.tt-pde-guide { position: absolute; pointer-events: none; background: var(--tt-pde-guide-token); }` plus `--vertical` / `--horizontal` modifiers.
  - `--tt-pde-guide-token` brand-style token, default `#5b8def` at 70% opacity.
- Wire into existing call sites: `placeNewSlot`, `moveExistingSlot`, `moveSlotByKey`, `resizeSlot` (the resize-clamp from v3.62.0 polish — same engine should re-run after a resize).
- Run `compactGrid` on load to clean up any pre-existing overlaps in stored layouts (single one-shot pass at `loadTemplate` time).

## Wizard plan

Exemption — this is editor-internal UX, not a record-creation flow. The persona dashboard editor itself is exempt from the wizard rule (it's a settings panel that mutates one entity, the dashboard template) and these additions don't change that posture.

## Out of scope

- **Separate desktop / mobile layouts.** Discussed during shaping; the shipped model (one grid + per-slot `mobile_priority` + `mobile_visible`) stays. Splitting into two independent grids is a schema + persistence change with non-trivial migration cost; will be its own spec if a real "mobile needs different widgets, not just reordered" use case appears.
- **Drag-resize.** Sizes still come from S/M/L/XL presets only (per the existing `colsForSize()` mapping); no edge-handle resize. The spec called for "no manual resizing" too — aligned.
- **React / dnd-kit / react-grid-layout port.** CLAUDE.md § 2 mandates vanilla JS, no build step. The algorithms here run fine in vanilla.
- **Undo limit reduction.** Current `UNDO_LIMIT = 50`; the source spec asked for 10. We keep 50 (strictly better).
- **Custom widgets in the palette.** Tracked separately as #0078 (~120h epic, not started).
- **Persistence shape change** (`{dashboardId, personaId, layouts: {desktop, mobile}}`). Current keying on persona + role + draft/published stays.

## Acceptance criteria

- [ ] Dropping a widget onto an occupied cell pushes the colliding widget(s) down without overlap. Saving persists the post-push positions.
- [ ] After any move / drop / nudge, the grid is vertically compact — no slot has a free row directly above it that would still keep it inside the canvas without colliding.
- [ ] Pre-existing overlapping layouts (from before this spec ships) auto-resolve on next load via the one-shot `compactGrid` pass; user sees a clean layout without having to drag anything.
- [ ] Alignment guides appear during drag when the dragged slot's left / right / centre-x edge lines up with another slot's matching edge (or the canvas centre / edges). Within `SNAP_TOLERANCE_PX`, the drop snaps to the aligned column.
- [ ] Same for horizontal alignment (top / bottom / centre-y).
- [ ] Guides clear on `dragend`, `drop`, and `Escape`-to-cancel.
- [ ] Push / compact moves animate smoothly via CSS transform transitions; respects `prefers-reduced-motion`.
- [ ] Holding `Shift` during drop switches behaviour from push-and-reflow to snap-to-nearest-free-cell (no other slots move).
- [ ] Keyboard arrow nudge (existing `moveSlotByKey`) re-runs the layout pass — nudging into an occupied cell pushes the occupant.
- [ ] Performance budget: 30 slots + alignment-guide computation completes in < 16ms per `dragover` event on the operator's pilot install (Moto G class, 4× CPU throttle per CLAUDE.md). Verified with `console.time` measurements during dev.
- [ ] No new translatable strings (the feature is silent-by-design — it's all visual).
- [ ] Existing keyboard a11y, mobile preview, draft/publish/reset flow, audit-log, undo/redo all keep working unchanged.
- [ ] `docs/persona-dashboard.md` (EN + NL) gains a short "Drag & drop" section explaining the push behaviour and the Shift modifier.

## Notes

- **Why the layout pass runs after every mutation, not as a separate "tidy" button**: the operator's mental model from the source spec (Notion / Power BI / Grafana) is that overlap simply isn't possible. A "tidy" button preserves the foot-gun. Cheaper to never permit overlap.
- **Why Shift = snap-to-free-cell instead of the default**: in informal testing, push-and-reflow feels right when the user is rearranging, snap-to-free feels right when the user is bulk-adding from the palette. Default to push (matches Notion); offer Shift for the second mode (matches Figma's "snap to whitespace" instinct).
- **Why no separate mobile grid (gap #3 from shaping)**: per-slot `mobile_priority` + `mobile_visible` already covers the operator's pilot need ("hide the radar chart on phones, reorder the my-tasks tile up"). No data point yet for "mobile needs different widgets, not just reordered." Revisit when such a case lands.
- **Why no React rewrite**: 1,052 LOC vanilla editor is already shipped, working, mobile-first, no build step. Porting to React would require introducing a build pipeline (Vite / esbuild / wp-scripts), changing the enqueue path, and revalidating every browser the operator's pilot uses. Zero user-visible benefit. The algorithms here are the same ones `react-grid-layout` ships internally — pulling them out of a JSX wrapper is fine.
- **Algorithm reference**: classic 2D bin-packing-with-push, same shape as react-grid-layout's `utils.js` `compact()` + `moveElement(prevent_collision: false)`. We don't depend on the library; we re-implement the ~120 LOC needed in vanilla.
- **Estimated**: ~6–10h for the full ship. Gap #1 alone is ~3-4h; gap #2 alone is ~2-3h; bundling avoids two separate retest passes.
- **Branch + version**: ships as a `feat` against the next clean main. Version slot will be whatever's free at PR time (see the parallel-PR-collisions feedback memory).
