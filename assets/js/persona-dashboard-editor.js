/*
 * Persona dashboard editor (#0060 sprint 2) — vanilla JS, no build step.
 *
 * Layers:
 *   - state    : current persona, in-flight template, undo/redo, dirty flag
 *   - render   : palette · canvas · properties panel
 *   - drag     : HTML5 drag-drop + keyboard space-to-grab fallback
 *   - rest     : PUT draft, POST publish, DELETE reset
 *   - modals   : confirm dialogs (publish, reset)
 *
 * Re-renders the whole canvas on every state change. The canvas is small
 * (rarely > 30 widgets) so a diff layer would be more code than it saves.
 */
(function () {
    'use strict';

    var BOOT = window.TT_PDE_Bootstrap || null;
    if (!BOOT) return;
    var I18N = BOOT.i18n || {};

    // ─── State ───────────────────────────────────────────────────

    var state = {
        persona: BOOT.templates && Object.keys(BOOT.templates)[0] || '',
        template: null,         // current in-flight PersonaTemplate (object form)
        baseline: null,         // last-saved (draft or default) snapshot for dirty check
        undoStack: [],          // newest at end
        redoStack: [],
        selectedSlotId: null,   // synthetic id assigned at render time
        mobilePreview: false,
        grabbedSlotId: null,    // keyboard a11y grab state
        renderToken: 0          // bumped on every render so async reads can drop
    };

    var UNDO_LIMIT = 50;

    // Each placed slot gets a synthetic id for selection + a11y; not persisted.
    var slotIdCounter = 0;
    function freshSlotId() { return 'pde-slot-' + (++slotIdCounter); }

    // ─── Bootstrap helpers ───────────────────────────────────────

    function widgetById(id) {
        return BOOT.widgets.find(function (w) { return w.id === id; }) || null;
    }
    function kpiById(id) {
        var ctx = ['academy', 'coach', 'player_parent'];
        for (var i = 0; i < ctx.length; i++) {
            var list = BOOT.kpis_by_context[ctx[i]] || [];
            var found = list.find(function (k) { return k.id === id; });
            if (found) return found;
        }
        return null;
    }
    function personaLabel(p) { return BOOT.persona_labels[p] || p; }

    // Returns a deep copy. JSON round-trip is fine for our shapes.
    function clone(o) { return JSON.parse(JSON.stringify(o)); }

    // ─── Template helpers ────────────────────────────────────────

    function defaultTemplateFor(persona) {
        var bundle = BOOT.templates[persona];
        if (!bundle) return emptyTemplate(persona);
        return clone(bundle.default);
    }
    function activeTemplateFor(persona) {
        var bundle = BOOT.templates[persona];
        if (!bundle) return emptyTemplate(persona);
        if (bundle.draft) return clone(bundle.draft);
        if (bundle.published) return clone(bundle.published);
        return clone(bundle.default);
    }
    function emptyTemplate(persona) {
        return {
            version: 1,
            persona_slug: persona,
            club_id: BOOT.club_id,
            status: 'draft',
            hero: null,
            task: null,
            grid: []
        };
    }

    function newSlot(widgetId, dataSource, size) {
        var w = widgetById(widgetId);
        return {
            widget: dataSource ? widgetId + ':' + dataSource : widgetId,
            size: size || (w ? w.default_size : 'M'),
            x: 0, y: 0,
            row_span: 1,
            mobile_priority: w ? w.default_priority : 50,
            mobile_visible: true,
            persona_label: '',
            __id: freshSlotId()
        };
    }

    // Adds synthetic ids to template slots after load.
    function annotate(template) {
        if (template.hero) template.hero.__id = freshSlotId();
        if (template.task) template.task.__id = freshSlotId();
        (template.grid || []).forEach(function (s) { s.__id = freshSlotId(); });
        return template;
    }

    function colsForSize(size) {
        return ({ S: 3, M: 6, L: 9, XL: 12 })[size] || 6;
    }
    function splitRef(ref) {
        if (!ref) return ['', ''];
        var i = ref.indexOf(':');
        if (i === -1) return [ref, ''];
        return [ref.substring(0, i), ref.substring(i + 1)];
    }

    // ─── Layout pass — collision + compact (#0088) ───────────────
    //
    // Editor invariant: no two slots overlap on the 12-col grid. After
    // every mutation that places or moves a slot, we run a two-step
    // pass:
    //
    //   1. resolveCollisions(grid, droppedId) — push any colliders of
    //      the dropped slot downward; cascade so the pushed slots'
    //      colliders also move. Bounded by grid.length, no infinite
    //      loop possible.
    //   2. compactGrid(grid) — for each slot in (y, x) order, lower
    //      its y to the smallest value that still avoids collision.
    //      Closes the gaps the push pass leaves behind.
    //
    // Pure functions — they mutate the slots' x / y but don't touch
    // any DOM. CSS transition: transform 150ms ease (added in
    // persona-dashboard-editor.css) animates the moves.

    function slotsCollide(a, b) {
        if (a === b || a.__id === b.__id) return false;
        var aw = colsForSize(a.size), bw = colsForSize(b.size);
        var ah = Math.max(1, a.row_span | 0 || 1);
        var bh = Math.max(1, b.row_span | 0 || 1);
        return !(
            a.x + aw <= b.x ||
            b.x + bw <= a.x ||
            a.y + ah <= b.y ||
            b.y + bh <= a.y
        );
    }

    function resolveCollisions(grid, droppedSlotId) {
        if (!grid || grid.length < 2) return grid;
        var dropped = grid.filter(function (s) { return s.__id === droppedSlotId; })[0];
        if (!dropped) return grid;
        // BFS: each iteration pushes the dropped slot's colliders to
        // (dropped.y + dropped.row_span); pushed slots become roots in
        // the next iteration. Bounded by grid.length so even a
        // pathological cascade can't loop.
        var roots = [dropped];
        for (var iter = 0; iter < grid.length && roots.length > 0; iter++) {
            var nextRoots = [];
            for (var r = 0; r < roots.length; r++) {
                var root = roots[r];
                var rootBottom = root.y + Math.max(1, root.row_span | 0 || 1);
                for (var i = 0; i < grid.length; i++) {
                    var s = grid[i];
                    if (s.__id === root.__id) continue;
                    if (slotsCollide(root, s)) {
                        s.y = rootBottom;
                        nextRoots.push(s);
                    }
                }
            }
            roots = nextRoots;
        }
        return grid;
    }

    function compactGrid(grid) {
        if (!grid || grid.length < 2) return grid;
        // Sort by (y, x) ascending so earlier slots get to claim the
        // smallest y first. Then for each slot, walk y down from its
        // current value to 0, stopping at the first y where it
        // doesn't collide with any slot that comes before it in the
        // sorted order.
        grid.sort(function (a, b) {
            if (a.y !== b.y) return a.y - b.y;
            return a.x - b.x;
        });
        for (var i = 0; i < grid.length; i++) {
            var s = grid[i];
            var minY = 0;
            for (var y = s.y - 1; y >= 0; y--) {
                var trial = { __id: s.__id, x: s.x, y: y, size: s.size, row_span: s.row_span };
                var collides = false;
                for (var j = 0; j < i; j++) {
                    if (slotsCollide(trial, grid[j])) { collides = true; break; }
                }
                if (collides) { minY = y + 1; break; }
            }
            s.y = minY;
        }
        return grid;
    }

    /**
     * Shift-drop fallback — find the nearest free cell that fits a
     * slot of the given size, starting at `(want.x, want.y)` and
     * spiralling outward via BFS. Always returns a valid cell (worst
     * case: bottom of the grid).
     */
    function findNearestFreeSlot(grid, want, size) {
        var span = colsForSize(size);
        var rowSpan = (size === 'XL' && want.row_span) ? want.row_span : 1;
        var startX = Math.max(0, Math.min(12 - span, want.x | 0));
        var startY = Math.max(0, want.y | 0);

        function fits(x, y) {
            var probe = { __id: '__probe__', x: x, y: y, size: size, row_span: rowSpan };
            for (var i = 0; i < grid.length; i++) {
                if (slotsCollide(probe, grid[i])) return false;
            }
            return true;
        }
        if (fits(startX, startY)) return { x: startX, y: startY };

        var seen = {};
        var queue = [[startX, startY]];
        seen[startX + ',' + startY] = true;
        // Bound: 12 cols × (max y + 4 rows headroom). Worst case BFS
        // sweeps the whole canvas; in practice it terminates in ~10
        // iterations.
        var maxY = 0;
        for (var k = 0; k < grid.length; k++) {
            var bottom = grid[k].y + Math.max(1, grid[k].row_span | 0 || 1);
            if (bottom > maxY) maxY = bottom;
        }
        var ymax = maxY + 4;
        while (queue.length > 0) {
            var head = queue.shift();
            var hx = head[0], hy = head[1];
            var deltas = [[1, 0], [-1, 0], [0, 1], [0, -1]];
            for (var d = 0; d < deltas.length; d++) {
                var nx = hx + deltas[d][0], ny = hy + deltas[d][1];
                if (nx < 0 || nx > 12 - span || ny < 0 || ny > ymax) continue;
                var key = nx + ',' + ny;
                if (seen[key]) continue;
                seen[key] = true;
                if (fits(nx, ny)) return { x: nx, y: ny };
                queue.push([nx, ny]);
            }
        }
        // Fallback — append at bottom-left.
        return { x: 0, y: maxY };
    }

    // ─── Alignment guides (#0088) ────────────────────────────────
    //
    // While dragging over the grid canvas, compute guide lines where
    // the dragged slot's left / right / centre-x aligns with another
    // slot's matching edge (or the canvas's own edges). Same for
    // vertical: top / bottom / centre-y. Within SNAP_TOLERANCE_PX
    // (default 4px) of any candidate alignment, the drop snaps to
    // that column.
    //
    // Pure function: computeAlignmentGuides() takes the dragged rect
    // + other slots' rects + canvas rect, returns an array of guides
    // `{axis: 'vertical'|'horizontal', coord: <px>}`. The DOM render
    // is a separate concern handled by renderAlignmentGuides().

    var SNAP_TOLERANCE_PX = 4;

    /**
     * @param dragged   {x, y, width, height} — projected pixel rect
     * @param others    list of {x, y, width, height}
     * @param canvas    {x, y, width, height}
     * @param tolerance px
     * @return list of {axis: 'vertical'|'horizontal', coord: number, snap: number}
     *         where `snap` is the value to snap the dragged rect's
     *         corresponding axis to (the dragged x/y, not the guide
     *         line itself — the guide is drawn at coord, the snap
     *         repositions the dragged slot).
     */
    function computeAlignmentGuides(dragged, others, canvas, tolerance) {
        if (!dragged || !canvas) return [];
        tolerance = tolerance || SNAP_TOLERANCE_PX;
        var guides = [];

        var draggedLeft   = dragged.x;
        var draggedRight  = dragged.x + dragged.width;
        var draggedCx     = dragged.x + dragged.width / 2;
        var draggedTop    = dragged.y;
        var draggedBottom = dragged.y + dragged.height;
        var draggedCy     = dragged.y + dragged.height / 2;

        var vCandidates = []; // {coord, snapDx}
        var hCandidates = [];

        function addV(targetX, draggedAxisVal) {
            // Snap dx so dragged's matching axis aligns to targetX.
            vCandidates.push({ coord: targetX, snapDx: targetX - draggedAxisVal });
        }
        function addH(targetY, draggedAxisVal) {
            hCandidates.push({ coord: targetY, snapDy: targetY - draggedAxisVal });
        }

        // Canvas edges.
        addV(canvas.x,                    draggedLeft);
        addV(canvas.x + canvas.width,     draggedRight);
        addV(canvas.x + canvas.width / 2, draggedCx);
        addH(canvas.y,                    draggedTop);
        addH(canvas.y + canvas.height,    draggedBottom);
        addH(canvas.y + canvas.height / 2, draggedCy);

        // Other-slot edges — left/right/centre-x of each.
        for (var i = 0; i < others.length; i++) {
            var o = others[i];
            var oLeft  = o.x;
            var oRight = o.x + o.width;
            var oCx    = o.x + o.width / 2;
            var oTop   = o.y;
            var oBot   = o.y + o.height;
            var oCy    = o.y + o.height / 2;

            // dragged's left snaps to other's left/right/centre.
            addV(oLeft,  draggedLeft);
            addV(oRight, draggedLeft);
            // dragged's right snaps to other's left/right/centre.
            addV(oLeft,  draggedRight);
            addV(oRight, draggedRight);
            // dragged's centre snaps to other's centre.
            addV(oCx,    draggedCx);

            addH(oTop,   draggedTop);
            addH(oBot,   draggedTop);
            addH(oTop,   draggedBottom);
            addH(oBot,   draggedBottom);
            addH(oCy,    draggedCy);
        }

        // Filter to within tolerance + dedupe by rounded coord.
        var seenV = {};
        for (var v = 0; v < vCandidates.length; v++) {
            var c = vCandidates[v];
            if (Math.abs(c.snapDx) > tolerance) continue;
            var key = Math.round(c.coord);
            if (seenV[key]) continue;
            seenV[key] = true;
            guides.push({ axis: 'vertical', coord: c.coord, snap: c.snapDx });
        }
        var seenH = {};
        for (var h = 0; h < hCandidates.length; h++) {
            var ch = hCandidates[h];
            if (Math.abs(ch.snapDy) > tolerance) continue;
            var keyH = Math.round(ch.coord);
            if (seenH[keyH]) continue;
            seenH[keyH] = true;
            guides.push({ axis: 'horizontal', coord: ch.coord, snap: ch.snapDy });
        }
        return guides;
    }

    var guideOverlay = null;
    function ensureGuideOverlay() {
        if (guideOverlay) return guideOverlay;
        guideOverlay = document.createElement('div');
        guideOverlay.className = 'tt-pde-guides';
        guideOverlay.setAttribute('aria-hidden', 'true');
        document.body.appendChild(guideOverlay);
        return guideOverlay;
    }

    function renderAlignmentGuides(guides, canvasRect) {
        var overlay = ensureGuideOverlay();
        overlay.innerHTML = '';
        if (!guides || guides.length === 0) return;
        for (var i = 0; i < guides.length; i++) {
            var g = guides[i];
            var el = document.createElement('div');
            el.className = 'tt-pde-guide tt-pde-guide-' + g.axis;
            if (g.axis === 'vertical') {
                el.style.left = g.coord + 'px';
                el.style.top = canvasRect.y + 'px';
                el.style.height = canvasRect.height + 'px';
            } else {
                el.style.top = g.coord + 'px';
                el.style.left = canvasRect.x + 'px';
                el.style.width = canvasRect.width + 'px';
            }
            overlay.appendChild(el);
        }
    }

    function clearAlignmentGuides() {
        if (guideOverlay) guideOverlay.innerHTML = '';
    }

    // ─── Live drag preview (#0060 polish — v3.110.92) ────────────
    //
    // While dragging over the grid canvas, existing slots animate out
    // of the way so the operator sees the final layout BEFORE releasing.
    // Builds a hypothetical preview grid every dragover, runs the same
    // collision/compact passes a real drop would run, then applies
    // `transform: translate(dx, dy)` to each card based on the preview
    // delta from its current grid position. CSS transition makes the
    // movement smooth. On drop the transforms are cleared and the
    // re-render places cards at the new grid positions — visually
    // seamless because the transforms equalled the eventual positions.
    //
    // Lightweight: O(n²) for n ~= 30 slots per preview, plus per-card
    // style writes only when the transform actually changes (tracked
    // in lastPreviewTransforms).
    var lastPreviewTransforms = {};

    function previewDragLayout(ev) {
        if (!state.template) return;
        var dragKind = currentDragKind();
        if (!dragKind) return;
        var canvas = getCanvas();
        if (!canvas) return;
        var canvasRect = canvas.getBoundingClientRect();
        if (canvasRect.width <= 0) return;
        var sz = dragSize();
        var coords = gridCellFromEvent(ev, sz);
        if (!coords) return;

        var colPx = canvasRect.width / 12;
        var rowPx = 60;

        var grid = state.template.grid || [];
        var preview = clone(grid);
        var probeId;

        if (dragKind === 'move') {
            probeId = window.__ttPdeDrag && window.__ttPdeDrag.slotId;
            if (!probeId) return;
            preview.forEach(function (s) {
                if (s.__id === probeId) {
                    s.x = coords.x;
                    s.y = coords.y;
                }
            });
        } else {
            probeId = '__probe__';
            preview.push({
                __id: probeId,
                widget: 'preview',
                size: sz,
                x: coords.x,
                y: coords.y,
                row_span: 1,
                mobile_priority: 50,
                mobile_visible: true,
                persona_label: ''
            });
        }

        resolveCollisions(preview, probeId);
        compactGrid(preview);

        var origById = {};
        grid.forEach(function (s) { origById[s.__id] = s; });

        // For move-drags, skip transforming the slot the user is dragging
        // — the browser's drag image already follows the cursor, and
        // double-moving the source card would visually conflict with it.
        // Other slots still animate around it.
        var skipId = (dragKind === 'move') ? probeId : null;
        var seen = {};
        preview.forEach(function (s) {
            if (s.__id === '__probe__') return;
            if (s.__id === skipId) return;
            var orig = origById[s.__id];
            if (!orig) return;
            var dx = (s.x - orig.x) * colPx;
            var dy = (s.y - orig.y) * rowPx;
            var t = (dx !== 0 || dy !== 0)
                ? 'translate(' + dx + 'px, ' + dy + 'px)'
                : '';
            seen[s.__id] = true;
            if (lastPreviewTransforms[s.__id] !== t) {
                var card = canvas.querySelector('[data-tt-pde-slot="' + s.__id + '"]');
                if (card) card.style.transform = t;
                lastPreviewTransforms[s.__id] = t;
            }
        });
        // Clear stale transforms — slots that previously had a preview
        // shift but no longer do.
        Object.keys(lastPreviewTransforms).forEach(function (id) {
            if (!seen[id]) {
                var card = canvas.querySelector('[data-tt-pde-slot="' + id + '"]');
                if (card) card.style.transform = '';
                delete lastPreviewTransforms[id];
            }
        });

        // Ghost at the probe's preview position (where the drop will land).
        var probe = null;
        for (var i = 0; i < preview.length; i++) {
            if (preview[i].__id === probeId) { probe = preview[i]; break; }
        }
        if (probe) {
            showDragGhost(probe.x, probe.y, sz);
        }
    }

    function clearPreviewTransforms() {
        var canvas = getCanvas();
        if (canvas) {
            canvas.querySelectorAll('.tt-pde-card').forEach(function (c) {
                c.style.transform = '';
            });
        }
        lastPreviewTransforms = {};
        hideDragGhost();
    }

    function showDragGhost(x, y, size) {
        var canvas = getCanvas();
        if (!canvas) return;
        var ghost = document.getElementById('tt-pde-drag-ghost');
        if (!ghost) {
            ghost = document.createElement('div');
            ghost.id = 'tt-pde-drag-ghost';
            ghost.className = 'tt-pde-drag-ghost';
            ghost.setAttribute('aria-hidden', 'true');
            canvas.appendChild(ghost);
        }
        ghost.style.gridColumn = (x + 1) + ' / span ' + colsForSize(size);
        ghost.style.gridRow = (y + 1) + ' / span 1';
        ghost.style.display = 'block';
    }

    function hideDragGhost() {
        var ghost = document.getElementById('tt-pde-drag-ghost');
        if (ghost) ghost.style.display = 'none';
    }

    /**
     * Snap the projected (col, row) cell to the nearest aligned
     * column/row when guides are within tolerance. Mutates `coords`
     * and returns it; no-op when no guide qualifies.
     */
    function snapToGuides(coords, ev, dragSize) {
        var canvas = getCanvas();
        if (!canvas || !state.template) return coords;
        var canvasRect = canvas.getBoundingClientRect();
        if (canvasRect.width <= 0) return coords;
        var colPx = canvasRect.width / 12;
        var rowPx = 60;
        var span = colsForSize(dragSize);
        var rowSpan = 1;

        var draggedRect = {
            x: canvasRect.x + coords.x * colPx,
            y: canvasRect.y + coords.y * rowPx,
            width: span * colPx,
            height: rowSpan * rowPx
        };
        var others = collectGridSlotRects(canvasRect, colPx, rowPx, dragMovingSlotId());
        var guides = computeAlignmentGuides(draggedRect, others, canvasRect, SNAP_TOLERANCE_PX);

        renderAlignmentGuides(guides, canvasRect);

        // Apply the smallest-magnitude vertical / horizontal snap so
        // the drop lands on the aligned column/row.
        var bestV = null, bestH = null;
        for (var i = 0; i < guides.length; i++) {
            var g = guides[i];
            if (g.axis === 'vertical') {
                if (!bestV || Math.abs(g.snap) < Math.abs(bestV.snap)) bestV = g;
            } else {
                if (!bestH || Math.abs(g.snap) < Math.abs(bestH.snap)) bestH = g;
            }
        }
        if (bestV) {
            var newX = Math.round(((draggedRect.x + bestV.snap) - canvasRect.x) / colPx);
            coords.x = Math.max(0, Math.min(12 - span, newX));
        }
        if (bestH) {
            var newY = Math.round(((draggedRect.y + bestH.snap) - canvasRect.y) / rowPx);
            coords.y = Math.max(0, newY);
        }
        return coords;
    }

    function collectGridSlotRects(canvasRect, colPx, rowPx, excludeSlotId) {
        var out = [];
        var grid = (state.template && state.template.grid) || [];
        for (var i = 0; i < grid.length; i++) {
            var s = grid[i];
            if (excludeSlotId && s.__id === excludeSlotId) continue;
            out.push({
                x: canvasRect.x + s.x * colPx,
                y: canvasRect.y + s.y * rowPx,
                width: colsForSize(s.size) * colPx,
                height: Math.max(1, s.row_span | 0 || 1) * rowPx
            });
        }
        return out;
    }

    function dragMovingSlotId() {
        var d = window.__ttPdeDrag;
        return (d && d.kind === 'move') ? d.slotId : null;
    }

    function dragSize() {
        var d = window.__ttPdeDrag;
        if (!d) return 'M';
        if (d.kind === 'move') {
            var hit = state.template ? findSlotAndBand(d.slotId) : null;
            return hit ? hit.slot.size : 'M';
        }
        if (d.kind === 'add-widget') {
            var w = widgetById(d.paletteId);
            return (w && w.default_size) || 'M';
        }
        return 'M'; // KPI default
    }

    function getCanvas() {
        return document.querySelector('[data-tt-pde="canvas"]');
    }

    // ─── Undo / redo ─────────────────────────────────────────────

    function commit() {
        if (!state.template) return;
        state.undoStack.push(clone(state.template));
        if (state.undoStack.length > UNDO_LIMIT) state.undoStack.shift();
        state.redoStack = [];
        renderAll();
    }
    function undo() {
        if (state.undoStack.length === 0) return;
        var prev = state.undoStack.pop();
        state.redoStack.push(clone(state.template));
        state.template = prev;
        // Re-annotate so widget selection still works after undo.
        state.template = annotate(stripIds(state.template));
        state.selectedSlotId = null;
        renderAll();
    }
    function redo() {
        if (state.redoStack.length === 0) return;
        var next = state.redoStack.pop();
        state.undoStack.push(clone(state.template));
        state.template = annotate(stripIds(next));
        state.selectedSlotId = null;
        renderAll();
    }
    function stripIds(template) {
        var t = clone(template);
        if (t.hero) delete t.hero.__id;
        if (t.task) delete t.task.__id;
        (t.grid || []).forEach(function (s) { delete s.__id; });
        return t;
    }

    // Dirty when current template differs from baseline.
    function isDirty() {
        if (!state.baseline || !state.template) return false;
        return JSON.stringify(stripIds(state.template)) !== JSON.stringify(stripIds(state.baseline));
    }

    // ─── REST helpers ────────────────────────────────────────────

    function apiUrl(path) { return BOOT.rest_url + path; }
    function api(method, path, body) {
        var opts = {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': BOOT.rest_nonce
            }
        };
        if (body !== undefined) opts.body = JSON.stringify(body);
        return fetch(apiUrl(path), opts).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        });
    }

    function setStatus(text, kind) {
        var node = document.querySelector('[data-tt-pde="status"]');
        if (!node) return;
        node.textContent = text || '';
        if (kind) node.setAttribute('data-tt-pde-status', kind);
        else node.removeAttribute('data-tt-pde-status');
    }

    function saveDraft() {
        if (!state.template) return Promise.resolve();
        var payload = stripIds(state.template);
        payload.status = 'draft';
        return api('PUT', 'personas/' + state.persona + '/template', payload).then(function (saved) {
            BOOT.templates[state.persona].draft = saved;
            state.baseline = clone(state.template);
            setStatus(I18N.saved_draft || 'Draft saved.', 'ok');
            renderToolbarStates();
        }).catch(function () {
            setStatus(I18N.save_failed || 'Save failed.', 'warn');
        });
    }
    function publish() {
        if (!state.template) return Promise.resolve();
        var payload = stripIds(state.template);
        payload.status = 'published';
        return api('POST', 'personas/' + state.persona + '/template/publish', payload).then(function (saved) {
            BOOT.templates[state.persona].published = saved;
            BOOT.templates[state.persona].draft = null;
            state.baseline = clone(state.template);
            setStatus(I18N.published || 'Layout published.', 'ok');
            renderToolbarStates();
        }).catch(function () {
            setStatus(I18N.save_failed || 'Save failed.', 'warn');
        });
    }
    function reset() {
        return api('DELETE', 'personas/' + state.persona + '/template').then(function (def) {
            BOOT.templates[state.persona].draft = null;
            BOOT.templates[state.persona].published = null;
            state.template = annotate(def);
            // One-shot tidy in case the default template ships with
            // overlap (shouldn't, but cheap insurance).
            if (state.template.grid) compactGrid(state.template.grid);
            state.baseline = clone(state.template);
            state.undoStack = []; state.redoStack = [];
            state.selectedSlotId = null;
            setStatus(I18N.reset_done || 'Reset to default.', 'ok');
            renderAll();
        }).catch(function () {
            setStatus(I18N.save_failed || 'Save failed.', 'warn');
        });
    }

    // ─── Persona switching ───────────────────────────────────────

    function loadPersona(persona) {
        if (!BOOT.templates[persona]) return;
        state.persona = persona;
        state.template = annotate(activeTemplateFor(persona));
        // #0088 — one-shot compact pass cleans up any stored layouts
        // from before the layout-invariant work landed (an existing
        // overlap would render correctly but unattractively; the
        // compact pass tidies on first load and the user sees a
        // clean grid without having to drag anything).
        if (state.template.grid) compactGrid(state.template.grid);
        state.baseline = clone(state.template);
        state.undoStack = []; state.redoStack = [];
        state.selectedSlotId = null;
        renderAll();
    }

    // ─── Rendering ───────────────────────────────────────────────

    var $ = function (sel) { return document.querySelector(sel); };

    function renderAll() {
        renderPalette();
        renderCanvas();
        renderProperties();
        renderToolbarStates();
        document.querySelector('.tt-pde-wrap').setAttribute('data-mobile-preview', state.mobilePreview ? 'true' : 'false');
    }

    function renderToolbarStates() {
        $('[data-tt-pde="undo"]').disabled = state.undoStack.length === 0;
        $('[data-tt-pde="redo"]').disabled = state.redoStack.length === 0;
        $('[data-tt-pde="mobile-preview"]').setAttribute('aria-pressed', state.mobilePreview ? 'true' : 'false');
        if (isDirty()) setStatus(I18N.unsaved_changes || 'Unsaved changes.', 'dirty');
        var sel = $('[data-tt-pde="persona-select"]');
        if (sel && sel.value !== state.persona) sel.value = state.persona;
    }

    // Palette ─────────────────────────────────────────────────
    function renderPalette() {
        renderWidgetPalette();
        renderKpiPalette();
    }
    function renderWidgetPalette() {
        var pane = document.querySelector('[data-tt-pde-tabpanel="widgets"]');
        if (!pane) return;
        pane.innerHTML = '';
        BOOT.widgets.forEach(function (w) {
            // v3.71.5 — was <button draggable="true">, but Firefox (and
            // some Chromium-based browsers in particular configurations)
            // don't reliably fire `dragstart` on form-element buttons.
            // The result: __ttPdeDrag never gets set, dragover bails
            // early without preventDefault, the browser shows the
            // "not allowed" cursor, and the drop can't complete.
            // Switched to <div role="button" tabindex="0"> which behaves
            // identically for keyboard / screen-reader users but is
            // reliably draggable across browsers.
            var item = document.createElement('div');
            item.setAttribute('role', 'button');
            item.tabIndex = 0;
            item.className = 'tt-pde-palette-item';
            item.setAttribute('draggable', 'true');
            item.dataset.ttPdePaletteWidget = w.id;
            item.innerHTML =
                '<span class="tt-pde-palette-item-label">' + escape(w.label) + '</span>' +
                '<span class="tt-pde-palette-item-meta">' + w.default_size + '</span>';
            item.addEventListener('dragstart', onPaletteDragStart);
            item.addEventListener('keydown', onPaletteKey);
            item.addEventListener('click', function () { addWidget(w.id, ''); });
            pane.appendChild(item);
        });
    }
    function renderKpiPalette() {
        var pane = document.querySelector('[data-tt-pde-tabpanel="kpis"]');
        if (!pane) return;
        pane.innerHTML = '';
        var groups = [
            ['academy',       I18N.kpi_context_academy || 'Academy-wide'],
            ['coach',         I18N.kpi_context_coach || 'Coach'],
            ['player_parent', I18N.kpi_context_player || 'Player / parent']
        ];
        groups.forEach(function (g) {
            var ctx = g[0]; var label = g[1];
            var rows = BOOT.kpis_by_context[ctx] || [];
            if (rows.length === 0) return;
            var group = document.createElement('section');
            group.className = 'tt-pde-palette-group';
            var head = document.createElement('button');
            head.type = 'button';
            head.className = 'tt-pde-palette-group-head';
            head.setAttribute('aria-expanded', 'false');
            head.innerHTML =
                '<span>' + escape(label) + ' <span style="opacity:.6">· ' + rows.length + '</span></span>' +
                '<svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M2 3.5l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>';
            head.addEventListener('click', function () {
                var open = head.getAttribute('aria-expanded') === 'true';
                head.setAttribute('aria-expanded', open ? 'false' : 'true');
            });
            var body = document.createElement('div');
            body.className = 'tt-pde-palette-group-body';
            rows.forEach(function (k) {
                // v3.71.5 — div role=button instead of <button> so drag
                // events fire reliably across browsers (see widget
                // palette comment above).
                var item = document.createElement('div');
                item.setAttribute('role', 'button');
                item.tabIndex = 0;
                item.className = 'tt-pde-palette-item';
                item.setAttribute('draggable', 'true');
                item.dataset.ttPdePaletteKpi = k.id;
                item.innerHTML =
                    '<span class="tt-pde-palette-item-label">' + escape(k.label) + '</span>' +
                    '<span class="tt-pde-palette-item-meta">KPI</span>';
                item.addEventListener('dragstart', onPaletteDragStart);
                item.addEventListener('keydown', onPaletteKey);
                item.addEventListener('click', function () { addWidget('kpi_card', k.id); });
                body.appendChild(item);
            });
            group.appendChild(head);
            group.appendChild(body);
            pane.appendChild(group);
        });
    }

    // Canvas ─────────────────────────────────────────────────
    function renderCanvas() {
        var heroBand = document.querySelector('[data-tt-pde-band="hero"]');
        var taskBand = document.querySelector('[data-tt-pde-band="task"]');
        var canvas   = document.querySelector('[data-tt-pde="canvas"]');
        if (!canvas || !state.template) return;

        heroBand.setAttribute('data-empty-label', '+ ' + (I18N.add_widget || 'Add widget'));
        taskBand.setAttribute('data-empty-label', '+ ' + (I18N.add_widget || 'Add widget'));
        canvas.setAttribute('data-empty-label', I18N.no_widgets_placed || '');

        heroBand.innerHTML = '';
        taskBand.innerHTML = '';
        canvas.innerHTML = '';

        if (state.template.hero) heroBand.appendChild(buildCard(state.template.hero, 'hero'));
        if (state.template.task) taskBand.appendChild(buildCard(state.template.task, 'task'));
        (state.template.grid || []).forEach(function (slot) {
            canvas.appendChild(buildCard(slot, 'grid'));
        });

        // Wire band drop-targets.
        [
            { node: heroBand, kind: 'hero' },
            { node: taskBand, kind: 'task' },
            { node: canvas,   kind: 'grid' }
        ].forEach(function (t) {
            t.node.addEventListener('dragover', function (e) {
                if (currentDragKind() == null) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                t.node.classList.add('is-drop-target');

                // #0088 — alignment guides on the grid band only.
                // Compute the dragged slot's projected position from
                // the cursor, then surface guides for any aligned
                // edges. snapToGuides also adjusts the projected
                // coords; the drop handler reads `e.clientX/Y` so the
                // visible guide position stays in sync with the drop.
                if (t.kind === 'grid') {
                    var sz = dragSize();
                    var coords = gridCellFromEvent(e, sz);
                    if (coords) snapToGuides(coords, e, sz);
                    // v3.110.92 — live preview reflow. Existing cards
                    // animate to their post-drop positions BEFORE the
                    // operator releases, so the layout is no surprise.
                    previewDragLayout(e);
                }
            });
            t.node.addEventListener('dragleave', function (e) {
                if (e.target === t.node) t.node.classList.remove('is-drop-target');
                if (t.kind === 'grid') {
                    clearAlignmentGuides();
                    clearPreviewTransforms();
                }
            });
            t.node.addEventListener('drop', function (e) {
                e.preventDefault();
                t.node.classList.remove('is-drop-target');
                clearAlignmentGuides();
                clearPreviewTransforms();
                handleDropOnBand(t.kind, e);
            });
        });

        // Belt-and-braces: any dragend anywhere clears guides + preview
        // transforms (covers Escape-to-cancel + drag-out-of-window cases).
        document.addEventListener('dragend', clearAlignmentGuides);
        document.addEventListener('dragend', clearPreviewTransforms);
    }

    function buildCard(slot, bandKind) {
        var ref = splitRef(slot.widget);
        var widgetId = ref[0]; var ds = ref[1];
        var w = widgetById(widgetId);
        var card = document.createElement('div');
        card.className = 'tt-pde-card';
        if (bandKind !== 'grid') card.classList.add('is-band');
        card.tabIndex = 0;
        card.setAttribute('role', 'group');
        card.setAttribute('aria-grabbed', 'false');
        card.setAttribute('draggable', 'true');
        card.dataset.ttPdeSlot = slot.__id;
        card.dataset.ttPdeBand = bandKind;
        if (state.selectedSlotId === slot.__id) card.classList.add('is-selected');
        if (state.grabbedSlotId === slot.__id) {
            card.classList.add('is-grabbed');
            card.setAttribute('aria-grabbed', 'true');
        }

        if (bandKind === 'grid') {
            card.style.gridColumn = (slot.x + 1) + ' / span ' + colsForSize(slot.size);
            card.style.gridRow    = (slot.y + 1) + ' / span ' + Math.max(1, slot.row_span);
        }

        var labelText = slot.persona_label || (w ? w.label : widgetId);
        var sourceLine = '';
        if (ds) {
            var src = (widgetId === 'kpi_card' ? kpiById(ds) : null);
            sourceLine = src ? src.label : ds;
        }

        card.innerHTML =
            (sourceLine ? '<span class="tt-pde-card-source">' + escape(sourceLine) + '</span>' : '') +
            '<span class="tt-pde-card-label">' + escape(labelText) + '</span>' +
            '<span class="tt-pde-card-size-badge" aria-label="' + (I18N.size || 'Size') + '">' + slot.size + '</span>' +
            '<span class="tt-pde-card-actions">' +
                '<button type="button" class="tt-pde-card-action is-remove" data-tt-pde-action="remove" aria-label="' + (I18N.remove || 'Remove') + '">×</button>' +
            '</span>';

        // Selection on click + focus.
        card.addEventListener('click', function (e) {
            if (e.target.closest('[data-tt-pde-action]')) return;
            selectSlot(slot.__id);
        });
        card.addEventListener('focus', function () { selectSlot(slot.__id); });

        // Action: remove.
        card.querySelector('[data-tt-pde-action="remove"]').addEventListener('click', function () {
            removeSlot(slot.__id);
        });

        // Drag start / end on the card (move existing slot).
        card.addEventListener('dragstart', function (e) {
            window.__ttPdeDrag = { kind: 'move', slotId: slot.__id };
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', slot.__id);
            card.classList.add('is-dragging');
            document.body.classList.add('tt-pde-dragging');
        });
        card.addEventListener('dragend', function () {
            window.__ttPdeDrag = null;
            card.classList.remove('is-dragging');
            document.body.classList.remove('tt-pde-dragging');
        });

        // Keyboard a11y: space to grab/drop, arrows to move, escape to cancel, delete to remove.
        card.addEventListener('keydown', function (e) {
            if (e.key === ' ' || e.key === 'Spacebar') {
                e.preventDefault();
                if (state.grabbedSlotId === slot.__id) {
                    state.grabbedSlotId = null;
                    setStatus(formatString(I18N.dropped, [labelText, slot.x + 1, slot.y + 1]) || '', 'ok');
                } else {
                    state.grabbedSlotId = slot.__id;
                    setStatus(formatString(I18N.grabbed, [labelText]) || I18N.grab || '', '');
                }
                renderCanvas();
            } else if (state.grabbedSlotId === slot.__id) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    state.grabbedSlotId = null;
                    setStatus(I18N.cancelled || '', '');
                    renderCanvas();
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    moveSlotByKey(slot.__id, e.key);
                }
            } else if (e.key === 'Delete' || e.key === 'Backspace') {
                e.preventDefault();
                removeSlot(slot.__id);
            }
        });

        return card;
    }

    function currentDragKind() {
        return (window.__ttPdeDrag && window.__ttPdeDrag.kind) || null;
    }

    function onPaletteDragStart(e) {
        var t = e.currentTarget;
        var kind = t.dataset.ttPdePaletteWidget ? 'add-widget' : 'add-kpi';
        var id = t.dataset.ttPdePaletteWidget || t.dataset.ttPdePaletteKpi || '';
        window.__ttPdeDrag = { kind: kind, paletteId: id };
        e.dataTransfer.effectAllowed = 'copy';
        e.dataTransfer.setData('text/plain', id);
        t.classList.add('is-dragging');
        document.body.classList.add('tt-pde-dragging');
        var endHandler = function () {
            t.classList.remove('is-dragging');
            document.body.classList.remove('tt-pde-dragging');
            t.removeEventListener('dragend', endHandler);
        };
        t.addEventListener('dragend', endHandler);
    }
    function onPaletteKey(e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        e.preventDefault();
        var t = e.currentTarget;
        var widgetId = t.dataset.ttPdePaletteWidget;
        if (widgetId) addWidget(widgetId, '');
        var kpiId = t.dataset.ttPdePaletteKpi;
        if (kpiId) addWidget('kpi_card', kpiId);
    }

    function handleDropOnBand(kind, ev) {
        var drag = window.__ttPdeDrag; window.__ttPdeDrag = null;
        if (!drag) return;
        if (drag.kind === 'add-widget') {
            placeNewSlot(drag.paletteId, '', kind, ev);
        } else if (drag.kind === 'add-kpi') {
            placeNewSlot('kpi_card', drag.paletteId, kind, ev);
        } else if (drag.kind === 'move') {
            moveExistingSlot(drag.slotId, kind, ev);
        }
    }

    function addWidget(widgetId, dataSource) {
        placeNewSlot(widgetId, dataSource, 'grid', null);
    }

    function placeNewSlot(widgetId, dataSource, bandKind, ev) {
        if (!state.template) return;
        var slot = newSlot(widgetId, dataSource);
        if (bandKind === 'hero') {
            slot.size = 'XL'; slot.row_span = 2;
            state.template.hero = slot;
        } else if (bandKind === 'task') {
            slot.size = 'XL'; slot.row_span = 1;
            state.template.task = slot;
        } else {
            // v3.80.1 — when the placement was triggered by a real
            // drop event, derive the grid cell from the cursor
            // coordinates instead of always appending to the bottom-
            // left. Fixes the "drag-drop just dumps at the end"
            // complaint — the previous behaviour was visually
            // identical to clicking the palette item.
            var coords = ev ? gridCellFromEventWithSnap(ev, slot.size) : null;
            if (coords) {
                slot.x = coords.x;
                slot.y = coords.y;
            } else {
                // No event (palette click) → naive auto-place at
                // the bottom-left like before.
                var maxY = -1;
                (state.template.grid || []).forEach(function (s) {
                    var bottom = s.y + Math.max(1, s.row_span);
                    if (bottom > maxY) maxY = bottom;
                });
                slot.y = Math.max(0, maxY);
                slot.x = 0;
            }
            state.template.grid = state.template.grid || [];

            // Shift modifier — snap to nearest free cell instead of
            // pushing existing slots out of the way.
            if (ev && ev.shiftKey && coords) {
                var snapped = findNearestFreeSlot(state.template.grid, { x: coords.x, y: coords.y }, slot.size);
                slot.x = snapped.x; slot.y = snapped.y;
                state.template.grid.push(slot);
            } else {
                state.template.grid.push(slot);
                resolveCollisions(state.template.grid, slot.__id);
                compactGrid(state.template.grid);
            }
        }
        commit();
        selectSlot(slot.__id);

        var w = widgetById(widgetId);
        var label = w ? w.label : widgetId;
        setStatus(formatString(I18N.widget_added, [label]) || (label + ' added.'), 'ok');
    }

    /**
     * Translate a drop event's pointer coordinates into a grid cell
     * (col, row), clamped so the resulting slot stays inside the
     * 12-column canvas. Returns null when the canvas isn't open (e.g.
     * a band drop that re-routed here mistakenly).
     *
     * #0077 follow-up — the previous DnD code parsed the event but
     * never used clientX/Y, so every drop landed at (0, max_y+1).
     */
    function gridCellFromEvent(ev, size) {
        var canvas = getCanvas();
        if (!canvas || typeof ev.clientX !== 'number') return null;
        var rect = canvas.getBoundingClientRect();
        if (rect.width <= 0) return null;
        var dx = ev.clientX - rect.left;
        var dy = ev.clientY - rect.top;
        var colPx = rect.width / 12;
        // Approximate row height — uses the existing grid auto-rows
        // setting (CSS sets 60px). Reading getComputedStyle here is
        // overkill; 60 matches the editor's grid-auto-rows.
        var rowPx = 60;
        var col = Math.max(0, Math.min(11, Math.floor(dx / colPx)));
        var row = Math.max(0, Math.floor(dy / rowPx));
        // Clamp x so the slot doesn't overflow the right edge.
        var span = colsForSize(size);
        col = Math.max(0, Math.min(12 - span, col));
        return { x: col, y: row };
    }

    /**
     * Wraps gridCellFromEvent + alignment-guide snap. Used by the
     * drop handlers so a drop near an aligned column actually lands
     * on the aligned column instead of the raw cursor cell.
     */
    function gridCellFromEventWithSnap(ev, size) {
        var coords = gridCellFromEvent(ev, size);
        if (!coords) return null;
        snapToGuides(coords, ev, size);
        return coords;
    }

    function moveExistingSlot(slotId, targetBand, ev) {
        if (!state.template) return;
        var src = findSlotAndBand(slotId);
        if (!src) return;
        if (src.band === targetBand && targetBand !== 'grid') {
            // Same band, no-op.
            return;
        }
        // Detach from current band.
        if (src.band === 'hero') state.template.hero = null;
        if (src.band === 'task') state.template.task = null;
        if (src.band === 'grid') {
            state.template.grid = (state.template.grid || []).filter(function (s) { return s.__id !== slotId; });
        }
        // Re-attach to target.
        var slot = src.slot;
        if (targetBand === 'hero') {
            slot.size = 'XL'; slot.row_span = 2;
            state.template.hero = slot;
        } else if (targetBand === 'task') {
            slot.size = 'XL'; slot.row_span = 1;
            state.template.task = slot;
        } else {
            // v3.80.1 — same DnD-coords fix as placeNewSlot. Moving an
            // existing slot via drag now lands on the dropped cell
            // instead of bottom-left.
            var coords = ev ? gridCellFromEventWithSnap(ev, slot.size) : null;
            if (coords) {
                slot.x = coords.x;
                slot.y = coords.y;
            } else {
                var maxY = -1;
                (state.template.grid || []).forEach(function (s) {
                    var bottom = s.y + Math.max(1, s.row_span);
                    if (bottom > maxY) maxY = bottom;
                });
                slot.y = Math.max(0, maxY);
                slot.x = 0;
            }
            state.template.grid = state.template.grid || [];

            // Shift modifier — snap to nearest free cell.
            if (ev && ev.shiftKey && coords) {
                var snapped = findNearestFreeSlot(state.template.grid, { x: coords.x, y: coords.y }, slot.size);
                slot.x = snapped.x; slot.y = snapped.y;
                state.template.grid.push(slot);
            } else {
                state.template.grid.push(slot);
                resolveCollisions(state.template.grid, slot.__id);
                compactGrid(state.template.grid);
            }
        }
        commit();
        selectSlot(slot.__id);
    }

    function findSlotAndBand(slotId) {
        if (!state.template) return null;
        if (state.template.hero && state.template.hero.__id === slotId) return { slot: state.template.hero, band: 'hero' };
        if (state.template.task && state.template.task.__id === slotId) return { slot: state.template.task, band: 'task' };
        var hit = (state.template.grid || []).find(function (s) { return s.__id === slotId; });
        return hit ? { slot: hit, band: 'grid' } : null;
    }

    function moveSlotByKey(slotId, key) {
        var hit = findSlotAndBand(slotId);
        if (!hit || hit.band !== 'grid') return;
        var step = (key === 'ArrowLeft' || key === 'ArrowRight') ? 3 : 1;
        if (key === 'ArrowLeft')  hit.slot.x = Math.max(0, hit.slot.x - step);
        if (key === 'ArrowRight') hit.slot.x = Math.min(12 - colsForSize(hit.slot.size), hit.slot.x + step);
        if (key === 'ArrowUp')    hit.slot.y = Math.max(0, hit.slot.y - step);
        if (key === 'ArrowDown')  hit.slot.y = hit.slot.y + step;
        // Re-run the layout pass — nudging into an occupied cell pushes
        // the occupant down. Compact-after-push tightens the result.
        if (state.template.grid) {
            resolveCollisions(state.template.grid, hit.slot.__id);
            compactGrid(state.template.grid);
        }
        commit();
    }

    function removeSlot(slotId) {
        var hit = findSlotAndBand(slotId);
        if (!hit) return;
        if (hit.band === 'hero') state.template.hero = null;
        if (hit.band === 'task') state.template.task = null;
        if (hit.band === 'grid') {
            state.template.grid = (state.template.grid || []).filter(function (s) { return s.__id !== slotId; });
            // v3.110.91 — backfill the empty cell. Every other mutation
            // path (drop, keyboard nudge, persona switch, reset) calls
            // compactGrid; removal silently skipped it, so deleting a
            // widget left a visible hole and the layout below stayed
            // pinned to its original y. Same compact pass tidies on
            // remove now.
            compactGrid(state.template.grid);
        }
        if (state.selectedSlotId === slotId) state.selectedSlotId = null;
        commit();
    }

    function selectSlot(slotId) {
        state.selectedSlotId = slotId;
        renderCanvas();
        renderProperties();
    }

    // Properties panel ─────────────────────────────────────────
    function renderProperties() {
        var pane = document.querySelector('[data-tt-pde="properties"]');
        if (!pane) return;
        var hit = state.selectedSlotId ? findSlotAndBand(state.selectedSlotId) : null;
        if (!hit) {
            pane.innerHTML = '<div class="tt-pde-properties-empty">' + escape(I18N.select_widget || '') + '</div>';
            return;
        }
        var slot = hit.slot;
        var ref = splitRef(slot.widget);
        var w = widgetById(ref[0]);
        var allowedSizes = (w && w.allowed_sizes) || ['S', 'M', 'L', 'XL'];

        var form = document.createElement('form');
        form.className = 'tt-pde-properties-form';
        form.addEventListener('submit', function (e) { e.preventDefault(); });

        // Head
        var head = document.createElement('header');
        head.className = 'tt-pde-properties-head';
        head.innerHTML =
            '<div class="tt-pde-properties-meta">' + escape(slot.widget) + '</div>' +
            '<h3 class="tt-pde-properties-title">' + escape((w && w.label) || ref[0]) + '</h3>';
        form.appendChild(head);

        // Size — segmented control (band slots are XL only)
        if (hit.band === 'grid') {
            form.appendChild(field(I18N.size || 'Size', sizeSegmented(slot, allowedSizes)));
        } else {
            form.appendChild(field(I18N.size || 'Size', staticText('XL · ' + (hit.band === 'hero' ? 'hero' : 'task') + ' band')));
        }

        // Data source — KPI dropdown for kpi_card; per-widget catalogue
        // dropdown when the widget publishes one (#0077 M1); free-text
        // fallback for widgets without a catalogue.
        if (ref[0] === 'kpi_card') {
            form.appendChild(field(I18N.data_source || 'Data source', kpiSelect(slot)));
        } else {
            var catalogue = (BOOT.data_sources_by_widget || {})[ref[0]];
            if (catalogue && Object.keys(catalogue).length > 0) {
                form.appendChild(field(I18N.data_source || 'Data source', dataSourceSelect(slot, catalogue)));
            } else if (ref[0] === 'navigation_tile' || ref[0] === 'action_card' || ref[0] === 'info_card' || ref[0] === 'data_table' || ref[0] === 'mini_player_list') {
                form.appendChild(field(I18N.data_source || 'Data source', dataSourceText(slot)));
            }
        }

        // Persona label override
        form.appendChild(field(I18N.persona_label || 'Persona label override', personaLabelInput(slot)));

        // Mobile priority + visibility
        form.appendChild(field(I18N.mobile_priority || 'Mobile priority', mobilePriorityInput(slot)));
        form.appendChild(mobileVisibleCheckbox(slot));

        pane.innerHTML = '';
        pane.appendChild(form);
    }

    function field(labelText, body, helpText) {
        var wrap = document.createElement('label');
        wrap.className = 'tt-pde-field';
        var label = document.createElement('span');
        label.className = 'tt-pde-field-label';
        label.textContent = labelText;
        wrap.appendChild(label);
        wrap.appendChild(body);
        if (helpText) {
            var help = document.createElement('span');
            help.className = 'tt-pde-field-help';
            help.textContent = helpText;
            wrap.appendChild(help);
        }
        return wrap;
    }
    function staticText(t) {
        var s = document.createElement('span');
        s.textContent = t;
        s.className = 'tt-pde-field-help';
        return s;
    }
    function sizeSegmented(slot, allowed) {
        var box = document.createElement('div');
        box.className = 'tt-pde-size-segmented';
        ['S', 'M', 'L', 'XL'].forEach(function (sz) {
            var btn = document.createElement('button');
            btn.type = 'button'; btn.textContent = sz;
            btn.setAttribute('aria-pressed', slot.size === sz ? 'true' : 'false');
            if (allowed.indexOf(sz) === -1) btn.disabled = true;
            btn.addEventListener('click', function () {
                if (slot.size === sz) return;
                slot.size = sz;
                // Clamp x so the resized card stays within the 12-col grid.
                // Without this, an L slot at x=6 resized to L stays at x=6,
                // overflowing past column 12 — the card visually disappears
                // off the canvas with no feedback that the resize happened.
                var hit = findSlotAndBand(slot.__id);
                if (hit && hit.band === 'grid') {
                    var max_x = Math.max(0, 12 - colsForSize(sz));
                    if (slot.x > max_x) slot.x = max_x;
                    // The new size may overlap the slot to the right.
                    // Push and compact so the resize doesn't visually
                    // clobber another slot.
                    if (state.template.grid) {
                        resolveCollisions(state.template.grid, slot.__id);
                        compactGrid(state.template.grid);
                    }
                }
                commit();
            });
            box.appendChild(btn);
        });
        return box;
    }
    function kpiSelect(slot) {
        var sel = document.createElement('select');
        sel.appendChild(option('', '—'));
        ['academy', 'coach', 'player_parent'].forEach(function (ctx) {
            var rows = BOOT.kpis_by_context[ctx] || [];
            if (rows.length === 0) return;
            var group = document.createElement('optgroup');
            var labelMap = { academy: I18N.kpi_context_academy || 'Academy', coach: I18N.kpi_context_coach || 'Coach', player_parent: I18N.kpi_context_player || 'Player' };
            group.label = labelMap[ctx];
            rows.forEach(function (k) { group.appendChild(option(k.id, k.label)); });
            sel.appendChild(group);
        });
        sel.value = splitRef(slot.widget)[1] || '';
        sel.addEventListener('change', function () {
            var ref = splitRef(slot.widget);
            slot.widget = sel.value ? ref[0] + ':' + sel.value : ref[0];
            commit();
        });
        return sel;
    }
    function dataSourceText(slot) {
        var input = document.createElement('input');
        input.type = 'text';
        input.value = splitRef(slot.widget)[1] || '';
        input.placeholder = '—';
        input.addEventListener('change', function () {
            var ref = splitRef(slot.widget);
            slot.widget = input.value ? ref[0] + ':' + input.value : ref[0];
            commit();
        });
        return input;
    }
    // #0077 M1 — closed-set picker fed by the widget's catalogue.
    function dataSourceSelect(slot, catalogue) {
        var sel = document.createElement('select');
        sel.appendChild(option('', '—'));
        var current = splitRef(slot.widget)[1] || '';
        var keys = Object.keys(catalogue);
        keys.forEach(function (k) { sel.appendChild(option(k, catalogue[k])); });
        // If the slot already references an unknown id (e.g. an old
        // template referencing a since-removed preset), keep it visible
        // so the operator can deliberately switch — silent loss is worse.
        if (current && keys.indexOf(current) === -1) {
            sel.appendChild(option(current, current + ' (legacy)'));
        }
        sel.value = current;
        sel.addEventListener('change', function () {
            var ref = splitRef(slot.widget);
            slot.widget = sel.value ? ref[0] + ':' + sel.value : ref[0];
            commit();
        });
        return sel;
    }
    function personaLabelInput(slot) {
        var input = document.createElement('input');
        input.type = 'text';
        input.value = slot.persona_label || '';
        input.placeholder = I18N.persona_label_placeholder || '';
        input.addEventListener('change', function () {
            slot.persona_label = input.value;
            commit();
        });
        return input;
    }
    function mobilePriorityInput(slot) {
        var input = document.createElement('input');
        input.type = 'number';
        input.inputMode = 'numeric';
        input.min = '1'; input.max = '99';
        input.value = String(slot.mobile_priority || 50);
        input.addEventListener('change', function () {
            var n = parseInt(input.value, 10);
            if (isNaN(n) || n < 1) n = 1;
            slot.mobile_priority = n;
            commit();
        });
        return input;
    }
    function mobileVisibleCheckbox(slot) {
        var label = document.createElement('label');
        label.className = 'tt-pde-checkbox';
        var input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = slot.mobile_visible !== false;
        input.addEventListener('change', function () {
            slot.mobile_visible = !!input.checked;
            commit();
        });
        var text = document.createElement('span');
        text.textContent = I18N.mobile_visible || 'Show on mobile';
        label.appendChild(input);
        label.appendChild(text);
        return label;
    }
    function option(value, text) {
        var o = document.createElement('option');
        o.value = value; o.textContent = text;
        return o;
    }

    // ─── Modals ──────────────────────────────────────────────────

    function modal(opts) {
        var root = document.querySelector('[data-tt-pde="modal-root"]');
        root.innerHTML = '';
        root.setAttribute('aria-hidden', 'false');

        var dialog = document.createElement('div');
        dialog.className = 'tt-pde-modal';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.innerHTML =
            '<header class="tt-pde-modal-head">' +
                '<h2 class="tt-pde-modal-title"></h2>' +
            '</header>' +
            '<div class="tt-pde-modal-body"></div>' +
            '<div class="tt-pde-modal-actions"></div>';
        dialog.querySelector('.tt-pde-modal-title').textContent = opts.title || '';
        dialog.querySelector('.tt-pde-modal-body').textContent = opts.body || '';
        var actions = dialog.querySelector('.tt-pde-modal-actions');

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'button';
        cancelBtn.textContent = opts.cancelLabel || I18N.cancel || 'Cancel';
        cancelBtn.addEventListener('click', close);
        actions.appendChild(cancelBtn);

        var confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.className = opts.destructive ? 'button button-link-delete' : 'button button-primary';
        confirmBtn.textContent = opts.confirmLabel || 'OK';
        confirmBtn.addEventListener('click', function () {
            close();
            if (opts.onConfirm) opts.onConfirm();
        });
        actions.appendChild(confirmBtn);

        function close() {
            root.setAttribute('aria-hidden', 'true');
            root.innerHTML = '';
            document.removeEventListener('keydown', onKey);
        }
        function onKey(e) {
            if (e.key === 'Escape') close();
            if (e.key === 'Enter' && document.activeElement === confirmBtn) confirmBtn.click();
        }
        document.addEventListener('keydown', onKey);
        root.appendChild(dialog);
        confirmBtn.focus();
    }

    function confirmReset() {
        modal({
            title: I18N.reset_confirm_title || 'Reset?',
            body: I18N.reset_confirm_body || '',
            confirmLabel: I18N.reset_confirm_button || 'Reset',
            destructive: true,
            onConfirm: reset
        });
    }
    function confirmPublish() {
        var count = BOOT.user_counts[state.persona];
        var body;
        if (typeof count === 'number') {
            body = formatString(I18N.publish_confirm_body, [personaLabel(state.persona), count]);
        } else {
            body = formatString(I18N.publish_no_count_body, [personaLabel(state.persona)]);
        }
        modal({
            title: I18N.publish_confirm_title || 'Publish?',
            body: body,
            confirmLabel: I18N.publish_confirm_button || 'Publish',
            onConfirm: publish
        });
    }

    // ─── Helpers ─────────────────────────────────────────────────

    function escape(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function formatString(tmpl, args) {
        if (!tmpl) return '';
        return tmpl.replace(/%(\d+)\$[ds]|%[ds]/g, function (m) {
            var idxMatch = m.match(/%(\d+)\$/);
            if (idxMatch) {
                var i = parseInt(idxMatch[1], 10) - 1;
                return args[i] != null ? args[i] : '';
            }
            return args.shift();
        });
    }

    // ─── Init ────────────────────────────────────────────────────

    function init() {
        // Persona switch
        document.querySelector('[data-tt-pde="persona-select"]').addEventListener('change', function (e) {
            if (isDirty()) {
                if (!window.confirm(I18N.unsaved_changes + ' ' + (I18N.cancel || 'Cancel'))) {
                    e.target.value = state.persona;
                    return;
                }
            }
            loadPersona(e.target.value);
        });

        // Tabs
        document.querySelectorAll('[data-tt-pde-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                document.querySelectorAll('[data-tt-pde-tab]').forEach(function (t) {
                    t.classList.toggle('is-active', t === tab);
                    t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });
                document.querySelectorAll('[data-tt-pde-tabpanel]').forEach(function (p) {
                    p.hidden = p.dataset.ttPdeTabpanel !== tab.dataset.ttPdeTab;
                });
            });
        });

        // Toolbar
        document.querySelector('[data-tt-pde="undo"]').addEventListener('click', undo);
        document.querySelector('[data-tt-pde="redo"]').addEventListener('click', redo);
        var libToggle = document.querySelector('[data-tt-pde="library-toggle"]');
        if (libToggle) {
            libToggle.addEventListener('click', function () {
                var wrap = document.querySelector('.tt-pde-wrap');
                var open = wrap.getAttribute('data-library-open') === 'true';
                wrap.setAttribute('data-library-open', open ? 'false' : 'true');
                libToggle.setAttribute('aria-pressed', open ? 'false' : 'true');
            });
        }
        document.querySelector('[data-tt-pde="mobile-preview"]').addEventListener('click', function () {
            state.mobilePreview = !state.mobilePreview;
            renderAll();
        });
        document.querySelector('[data-tt-pde="reset"]').addEventListener('click', confirmReset);
        document.querySelector('[data-tt-pde="save-draft"]').addEventListener('click', saveDraft);
        document.querySelector('[data-tt-pde="publish"]').addEventListener('click', confirmPublish);

        // Keyboard: ctrl-z / ctrl-shift-z within the editor.
        document.addEventListener('keydown', function (e) {
            if (!document.querySelector('.tt-pde-wrap')) return;
            var z = (e.key === 'z' || e.key === 'Z');
            if (z && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                if (e.shiftKey) redo(); else undo();
            }
        });

        loadPersona(state.persona);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
