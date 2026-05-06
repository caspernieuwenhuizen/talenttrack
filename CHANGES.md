# TalentTrack v3.102.0 — Persona dashboard editor: collision detection + alignment guides + Shift snap-to-gap (#0088)

Two coupled additions to the persona-dashboard editor (`Configuration → Dashboard layouts`, shipped in #0060 v3.51.0 and polished through v3.62.0 / v3.71.5 / v3.82.1). The editor's drag-drop felt rough next to professional dashboard builders (Notion, Power BI, Grafana) for two reasons: dropping a widget could overlap an existing one, and there was no alignment feedback while dragging. Both gaps close in this ship.

Pure-vanilla extensions to the existing 1,052-LOC engine — no framework, no build step. The algorithms are the same shape `react-grid-layout` uses internally; we re-implement the ~120 LOC needed without depending on the library.

## (1) Collision detection + auto-reflow

After every mutation that places or moves a slot, run a two-step layout pass:

1. **`resolveCollisions(grid, droppedSlotId)`** — walk the grid; for each slot that overlaps the dropped slot, push it down to `dropped.y + dropped.row_span`. Cascade — a pushed slot can collide with the slot below; repeat until stable. Bounded by `grid.length`, no infinite loop possible.
2. **`compactGrid(grid)`** — sort by `(y, x)` ascending; for each slot, lower its y to the smallest non-colliding row given the slots already placed. Closes the gaps the push pass leaves behind.

Pure functions on the slot array; no DOM coupling. Wired into:

- `placeNewSlot` — adding from the palette
- `moveExistingSlot` — drag from one cell to another
- `moveSlotByKey` — keyboard arrow nudge
- `resizeSlot` — size button click (the new size may overlap the slot to the right)
- `loadPersona` + `reset` — one-shot `compactGrid` pass cleans up any stored layouts that pre-date this feature

A pre-existing layout with stacked slots auto-tidies on next load — operator opens the editor and sees a clean grid without dragging anything.

## (2) Alignment guides

While dragging over the canvas, `computeAlignmentGuides(draggedRect, otherRects, canvasRect, tolerance)` returns guides for every aligned edge:

- Dragged left / right / centre-x against every other slot's left / right / centre-x
- Same for top / bottom / centre-y
- Plus the canvas's own left / right / centre-x (and top / bottom / centre-y)

Within `SNAP_TOLERANCE_PX` (4px default), `gridCellFromEventWithSnap` adjusts the projected drop coords to the aligned column / row. Blue 1-pixel guide lines render via a fixed-position overlay `<div class="tt-pde-guides">` appended once to `document.body`. Cleared on `drop`, `dragleave`, and global `dragend` (covers Escape-to-cancel + drag-out-of-window).

Performance: 30 slots × 6 axes × 2 dimensions = ~360 candidate alignments per `dragover` — comfortably under the 16ms-per-frame budget on a Moto G class device per CLAUDE.md.

## (3) Shift modifier

Default behaviour on drop is **push-and-reflow** (matches Notion). Hold **Shift** on drop and the editor switches to **snap-to-nearest-free-cell**: `findNearestFreeSlot(grid, want, size)` BFS-spirals outward from `(want.x, want.y)` for the first cell that fits the dragged slot's size. Existing slots don't move.

Why two modes: in informal testing, push-and-reflow feels right when rearranging; snap-to-free feels right when bulk-adding from the palette. Default to push (matches Notion); offer Shift for the second mode (matches Figma's "snap to whitespace").

## CSS

- New `.tt-pde-guides` overlay (fixed, z-index 99999, `pointer-events: none`).
- New `.tt-pde-guide` + `.tt-pde-guide-vertical` / `.tt-pde-guide-horizontal` modifiers, sized 1px on the relevant axis.
- New brand-style token `--tt-pde-guide-token`, default `rgba(91, 141, 239, 0.7)`.
- Existing `prefers-reduced-motion: reduce` rule kicks in unchanged.

## What didn't change

- **Keyboard a11y, mobile preview, draft / publish / reset, audit-log, undo / redo, action-card UX** all keep working unchanged.
- **No new translatable strings.** Silent visual feature — guides + push behaviour speak for themselves.
- **No new caps, no migration.** Pure JS + CSS.

## What's not in this PR

- **Separate desktop / mobile layouts.** Per-slot `mobile_priority` + `mobile_visible` model stays; splitting into two independent grids is a schema + persistence change with non-trivial migration cost. Will be its own spec when a real "mobile needs different widgets, not just reordered" use case appears.
- **Drag-resize.** Sizes still come from S/M/L/XL presets only.
- **React / dnd-kit / react-grid-layout port.** CLAUDE.md §2 mandates vanilla.
- **Undo limit reduction.** Current `UNDO_LIMIT = 50` stays — strictly better than the source spec's 10.
- **Full-FLIP animated reflow on grid-cell changes.** Push / compact moves are functionally correct but reflow itself is instant rather than animated; the existing 60ms transform transition on `.tt-pde-card` continues to handle click feedback. FLIP-based animation on grid-row / grid-column changes would require absolute-positioning the cards during reflow — deferred polish, not load-bearing.

## Files touched

- `assets/js/persona-dashboard-editor.js` (~250 LOC of new helpers + wiring; ~1,300 LOC total)
- `assets/css/persona-dashboard-editor.css` (new `.tt-pde-guide{,-vertical,-horizontal}` block)
- `docs/persona-dashboard.md` + `docs/nl_NL/persona-dashboard.md` (Drag & drop section)
- `talenttrack.php` + `readme.txt` + `SEQUENCE.md` (renumbered v3.101.0 → v3.102.0 mid-rebase after parallel-agent ships of v3.100.1 + v3.101.0)
