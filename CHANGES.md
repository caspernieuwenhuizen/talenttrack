# TalentTrack v3.99.1 — Persona dashboard editor: collision detection + auto-reflow + alignment guides (#0088)

Closes #0088. Three coupled UX additions to the persona-dashboard editor (`Configuration → Dashboard layouts`, shipped in #0060 v3.51.0 + polish through v3.82.1). Compared the third-party canvas-builder spec against the shipped 1,052-LOC editor; most acceptance criteria already shipped (drag, snap, undo/redo with limit 50, persistence, mobile preview, keyboard a11y, S/M/L/XL presets). Three real gaps remained — this PR closes them, all in vanilla JS per `CLAUDE.md` §2 (no React, no build step, no new dependencies).

## What this PR does

### (1) Collision detection + auto-reflow

The previous `placeNewSlot` / `moveExistingSlot` wrote the dropped `(x, y)` straight into `state.template.grid` without overlap check. Slots could stack on the same cell and saving persisted the overlap; the operator had to manually drag the colliding slot out of the way.

Now four pure-function helpers run after every mutation:

- `slotsCollide(a, b)` — closed-half-open rect-overlap test on grid coords. Slots that share an edge (`a.x + a.col_span === b.x`) do not collide.
- `resolveCollisions(grid, droppedId)` — push-down cascade rooted at the just-placed / just-moved slot. Anything colliding with `dropped` gets `y = dropped.y + dropped.row_span`. Then chain — a pushed slot may now collide with the slot below it; repeat until stable. Bounded by `grid.length²` so termination is guaranteed.
- `compactGrid(grid)` — vertical compact: sort by `(y, x)`, lower each slot's `y` to the smallest value that doesn't introduce a new collision. Closes gaps left by the push pass and matches the Notion / Power BI / Grafana "always-compact" feel.
- `reflow(grid, droppedId)` — convenience wrapper that runs both passes.

Algorithm shape matches `react-grid-layout`'s `compact()` + `moveElement(prevent_collision: false)` — re-implemented in ~120 LOC vanilla.

Wired into every mutation site: `placeNewSlot`, `moveExistingSlot`, `moveSlotByKey` (keyboard arrow nudge), and the size-segmented control's resize click handler (a larger size may now overlap neighbours).

### (2) Visual alignment guides

On `dragover`, `computeAlignmentGuides()` compares the dragged slot's left / right / centre-x / top / bottom / centre-y in pixels against every other slot's matching edges, plus the canvas's own edges + centre. Matches within `ALIGN_TOLERANCE_PX` (4px) render as 1px overlay lines.

Implementation:

- Pure-function `slotRectPx(slot, canvasRect)` computes pixel-space rect from grid coords.
- `computeAlignmentGuides(draggedRect, otherSlots, canvasRect, tolerance)` returns `[{axis: 'v'|'h', pos: <px>}]` deduped on rounded position.
- `renderAlignmentGuides(guides)` diff-renders absolutely-positioned 1px `<span>`s into a new `.tt-pde-guides` overlay layer. Pointer-events disabled on the layer + each guide so they never absorb drag events.
- Cleared on `dragend` / `drop` / `dragleave` from the canvas / `Escape`. A catch-all document-level `dragend` listener handles drags released outside any registered drop target.

Performance: 30 slots × 6 edges = 180 pair tests per `dragover` event. Sub-millisecond on the operator's pilot install; well inside the spec's 16ms budget.

### (3) Animated reflow

FLIP technique on `commit()`:

1. `captureSlotRectsForFlip()` — before re-render, capture each slot's `getBoundingClientRect()`.
2. `renderAll()` — re-render with the new positions.
3. `playFlipAnimations()` — for each slot whose position changed by >= 1px, set `transform: translate(prev - now)` with `transition: none`, force a reflow via `void el.offsetHeight`, then in the next animation frame set `transition: transform 150ms ease, box-shadow 0.12s ease` and clear the transform. CSS transitions back to identity.

`prefers-reduced-motion: reduce` short-circuits `playFlipAnimations()`. The CSS transition on `.tt-pde-card` was already gated under that media query for the existing `box-shadow` transition; the new bumped `transform 150ms ease` falls under the same gate.

### (4) Shift modifier — snap to nearest free cell

Holding `Shift` while dropping switches from the default push-and-reflow behaviour to `findNearestFreeSlot()` — BFS outward from `(want.x, want.y)` for the first cell that fits the size without colliding. Default = Notion / Power BI feel; Shift = Figma "snap to whitespace" feel.

Useful when you're bulk-adding widgets and want them to slot in beside existing ones instead of shoving them around.

### (5) Load-time compact pass

`loadPersona()` runs `compactGrid()` once after `annotate()`. Layouts authored before this release (and possibly containing overlapping widgets from earlier editor versions) auto-resolve on first open — operator sees a tidy grid instead of stacked cards. Idempotent on already-clean grids.

### (6) Side-effect bug fix — `canvas` was undefined at module scope

The v3.82.1 `gridCellFromEvent` referenced `canvas` at module scope, but `canvas` was only declared (`var canvas = ...`) inside `renderCanvas()`. The reference always resolved to undefined and the function silently returned null on every call — every drop fell through to the legacy bottom-left fallback. Promoted `canvas` to module scope; the v3.82.1 cursor-coord-drop fix now actually takes effect, and the new alignment-guide layer can reach the same element.

## Out of scope (deferred per shaping)

- **Separate desktop / mobile layouts.** Current per-slot `mobile_priority` + `mobile_visible` model stays. Splitting into two independent grids is a schema + persistence change with non-trivial migration cost; will be its own spec when a real "mobile needs different widgets, not just reordered" use case appears.
- **React / dnd-kit / react-grid-layout port.** `CLAUDE.md` §2 mandates vanilla JS, no build step. The algorithms here run fine in vanilla.
- **Undo limit reduction.** Source spec asked for 10; current `UNDO_LIMIT = 50` stays (strictly better).
- **Custom widgets in the palette.** Tracked separately as #0078 (~120h epic, not started).
- **Persistence shape change** (`{dashboardId, personaId, layouts: {desktop, mobile}}` payload from the source spec). Current keying on persona + role + draft/published stays.

## Translations

No new translatable strings. The feature is silent-by-design — every cue is visual (guide lines, animated reflow, snap behaviour). `docs/persona-dashboard.md` (EN + NL) gains a *Drag & drop* / *Slepen & loslaten* section explaining the push behaviour, alignment guides, and the Shift modifier.

## Affected files

- `assets/js/persona-dashboard-editor.js` — collision/reflow + alignment-guide + FLIP helpers; wired into `placeNewSlot`, `moveExistingSlot`, `moveSlotByKey`, the resize click handler, `loadPersona`, and the canvas dragover/dragleave/drop handlers.
- `assets/css/persona-dashboard-editor.css` — `.tt-pde-guides` overlay + `.tt-pde-guide--vertical|horizontal` line classes + transform-transition bump on `.tt-pde-card`.
- `docs/persona-dashboard.md` + `docs/nl_NL/persona-dashboard.md` — new *Drag & drop* / *Slepen & loslaten* section.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.
- `specs/0088-feat-persona-dashboard-canvas-improvements.md` → `specs/shipped/0088-feat-persona-dashboard-canvas-improvements.md` — moved per `specs/README.md`.

Renumbered v3.97.2 → v3.98.1 → v3.98.2 → v3.99.1 after parallel-agent ships of #0068 Team Blueprint Phase 1 (v3.98.0), #0086 Workstream A docs (v3.98.1), and #0081 onboarding-pipeline children 2b+3+4 (v3.99.0) took the prior slots.
