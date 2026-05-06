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

    // 12-column grid. Row height matches the editor CSS `--pde-row-h`.
    // Updated lazily via getComputedStyle on first read so a theme
    // override flows through.
    var GRID_COLS = 12;
    var ROW_PX_FALLBACK = 60;

    // Alignment-guide tolerance — the dragged edge has to land within
    // this many px of another edge for a guide to render. 4px is
    // tight enough to feel intentional, loose enough to forgive a
    // shaky cursor.
    var ALIGN_TOLERANCE_PX = 4;

    // Cached canvas element. The previous (v3.82.1) `gridCellFromEvent`
    // referenced `canvas` at module scope but only declared it inside
    // `renderCanvas` — the reference always resolved to undefined and
    // the function silently returned null, so cursor-coord drops fell
    // through to the legacy bottom-left fallback. Promoting to module
    // scope makes the v3.82.1 fix actually take effect AND lets the
    // new alignment-guide layer reach the same element.
    var canvas = null;

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

    // ─── Undo / redo ─────────────────────────────────────────────

    function commit() {
        if (!state.template) return;
        state.undoStack.push(clone(state.template));
        if (state.undoStack.length > UNDO_LIMIT) state.undoStack.shift();
        state.redoStack = [];
        // #0088 — FLIP: capture each slot's pre-render rect, render,
        // then play a transform-based animation back to identity for
        // any slot whose position changed (push / compact / nudge).
        // Honors prefers-reduced-motion inside playFlipAnimations().
        captureSlotRectsForFlip();
        renderAll();
        playFlipAnimations();
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
        // #0088 — one-shot compact pass on load resolves any pre-
        // existing overlap left by templates that shipped before
        // collision detection. Operator opens the editor and sees a
        // clean layout instead of having to drag overlapping slots
        // apart by hand. Idempotent on already-clean grids.
        compactGrid(state.template.grid || []);
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
        canvas       = document.querySelector('[data-tt-pde="canvas"]');
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
                // #0088 — only the grid band offers alignment guides;
                // hero / task bands are single-slot lanes.
                if (t.kind === 'grid') updateAlignmentGuides(e);
            });
            t.node.addEventListener('dragleave', function (e) {
                if (e.target === t.node) {
                    t.node.classList.remove('is-drop-target');
                    if (t.kind === 'grid') clearAlignmentGuides();
                }
            });
            t.node.addEventListener('drop', function (e) {
                e.preventDefault();
                t.node.classList.remove('is-drop-target');
                if (t.kind === 'grid') clearAlignmentGuides();
                handleDropOnBand(t.kind, e);
            });
        });

        // Re-create the guide overlay on every render (canvas was
        // wiped above). Empty until a drag begins.
        ensureGuideLayer();
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
            state.template.grid = state.template.grid || [];
            var coords = ev ? gridCellFromEvent(ev, slot.size) : null;
            if (coords) {
                // #0088 — Shift modifier on drop switches from the
                // default push-and-reflow behaviour to "snap to the
                // nearest free cell," leaving every existing slot in
                // place. Default = Notion / Power BI feel; Shift =
                // Figma "snap to whitespace" feel.
                if (ev && ev.shiftKey) {
                    var free = findNearestFreeSlot(state.template.grid, coords, slot.size);
                    if (free) { slot.x = free.x; slot.y = free.y; }
                    else      { slot.x = coords.x; slot.y = coords.y; }
                } else {
                    slot.x = coords.x;
                    slot.y = coords.y;
                }
            } else {
                // No event (palette click) → auto-place at the first
                // free row from the top-left.
                var maxY = -1;
                state.template.grid.forEach(function (s) {
                    var bottom = s.y + Math.max(1, s.row_span);
                    if (bottom > maxY) maxY = bottom;
                });
                slot.y = Math.max(0, maxY);
                slot.x = 0;
            }
            state.template.grid.push(slot);
            // #0088 — push-and-reflow + compact pass closes the
            // overlap window the previous implementation left open.
            // Shift-mode drops already landed in a free cell, so
            // resolveCollisions becomes a no-op there; compact still
            // runs to close any gap above.
            reflow(state.template.grid, slot.__id);
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
        if (!canvas || typeof ev.clientX !== 'number') return null;
        var rect = canvas.getBoundingClientRect();
        if (rect.width <= 0) return null;
        var dx = ev.clientX - rect.left;
        var dy = ev.clientY - rect.top;
        var colPx = rect.width / GRID_COLS;
        var rowPx = currentRowPx();
        var col = Math.max(0, Math.min(GRID_COLS - 1, Math.floor(dx / colPx)));
        var row = Math.max(0, Math.floor(dy / rowPx));
        // Clamp x so the slot doesn't overflow the right edge.
        var span = colsForSize(size);
        col = Math.max(0, Math.min(GRID_COLS - span, col));
        return { x: col, y: row };
    }

    // Reads the active row height from the canvas' computed
    // `grid-auto-rows`. Falls back to the CSS default when the canvas
    // isn't in the DOM yet or the value can't be parsed.
    function currentRowPx() {
        if (!canvas || !canvas.ownerDocument || !canvas.ownerDocument.defaultView) return ROW_PX_FALLBACK;
        var cs = canvas.ownerDocument.defaultView.getComputedStyle(canvas);
        var raw = cs.getPropertyValue('grid-auto-rows');
        var n = parseFloat(raw);
        return (isFinite(n) && n > 0) ? n : ROW_PX_FALLBACK;
    }

    // ─── Collision / reflow ──────────────────────────────────────

    // Pure rect-overlap test on grid coords. Slots that share an edge
    // (a.x + a.col_span === b.x) DO NOT collide — the closed-half-
    // open interval convention every grid layout uses.
    function slotsCollide(a, b) {
        var aw = colsForSize(a.size);
        var bw = colsForSize(b.size);
        var ah = Math.max(1, a.row_span | 0);
        var bh = Math.max(1, b.row_span | 0);
        if (a.x + aw <= b.x || b.x + bw <= a.x) return false;
        if (a.y + ah <= b.y || b.y + bh <= a.y) return false;
        return true;
    }

    // Push-down cascade rooted at the just-placed / just-moved slot.
    // Anything colliding with `dropped` gets shoved to dropped.bottom.
    // Then chain — a pushed slot may now collide with the slot below
    // it; repeat until no collisions remain. Bounded so termination
    // is guaranteed even on pathological inputs.
    function resolveCollisions(grid, droppedId) {
        if (!grid || grid.length < 2) return;
        var anchor = grid.find(function (s) { return s.__id === droppedId; });
        if (!anchor) return;
        var moved = true;
        var guard = grid.length * grid.length + 8;
        while (moved && guard-- > 0) {
            moved = false;
            grid.forEach(function (other) {
                if (other === anchor) return;
                if (!slotsCollide(anchor, other)) return;
                var newY = anchor.y + Math.max(1, anchor.row_span | 0);
                if (other.y < newY) { other.y = newY; moved = true; }
            });
            // Cascade — slots below the just-pushed slots may now
            // overlap. Resolve pairwise until stable.
            for (var i = 0; i < grid.length; i++) {
                for (var j = 0; j < grid.length; j++) {
                    if (i === j) continue;
                    var a = grid[i]; var b = grid[j];
                    if (a === anchor || b === anchor) continue;
                    if (!slotsCollide(a, b)) continue;
                    var pushTarget = (a.y < b.y) ? b : (b.y < a.y ? a : (i < j ? b : a));
                    var pushSource = pushTarget === a ? b : a;
                    pushTarget.y = pushSource.y + Math.max(1, pushSource.row_span | 0);
                    moved = true;
                }
            }
        }
    }

    // Vertical compact pass. Lowers every slot's `y` to the smallest
    // value that doesn't introduce a new collision. Closes gaps left
    // by the push pass and matches the Notion / Power BI / Grafana
    // "always-compact" feel — no free row above any slot.
    function compactGrid(grid) {
        if (!grid || grid.length === 0) return;
        var sorted = grid.slice().sort(function (a, b) {
            if (a.y !== b.y) return a.y - b.y;
            return a.x - b.x;
        });
        sorted.forEach(function (slot) {
            while (slot.y > 0) {
                var probeY = slot.y - 1;
                var probe = { x: slot.x, y: probeY, size: slot.size, row_span: slot.row_span };
                var collides = false;
                for (var i = 0; i < sorted.length; i++) {
                    if (sorted[i] === slot) continue;
                    if (slotsCollide(probe, sorted[i])) { collides = true; break; }
                }
                if (collides) break;
                slot.y = probeY;
            }
        });
    }

    // Convenience: run both passes. Call after any mutation that may
    // have introduced overlap or gap.
    function reflow(grid, droppedId) {
        if (droppedId) resolveCollisions(grid, droppedId);
        compactGrid(grid);
    }

    // BFS outward from `(want.x, want.y)` for the first cell that
    // fits a slot of the given size without colliding. Used by the
    // Shift-modifier "find a gap" mode where the operator wants the
    // drop to slot in beside existing widgets instead of shoving
    // them down. Returns null when the grid is full to the right and
    // below — caller falls back to "append to bottom-left".
    function findNearestFreeSlot(grid, want, size) {
        var span = colsForSize(size);
        var maxX = GRID_COLS - span;
        for (var radius = 0; radius < 40; radius++) {
            for (var dy = 0; dy <= radius; dy++) {
                for (var dxAbs = -radius; dxAbs <= radius; dxAbs++) {
                    if (Math.abs(dxAbs) + dy !== radius) continue;
                    var x = want.x + dxAbs;
                    var y = want.y + dy;
                    if (x < 0 || x > maxX || y < 0) continue;
                    var probe = { x: x, y: y, size: size, row_span: 1, __id: '__probe__' };
                    var collides = false;
                    for (var i = 0; i < grid.length; i++) {
                        if (slotsCollide(probe, grid[i])) { collides = true; break; }
                    }
                    if (!collides) return { x: x, y: y };
                }
            }
        }
        return null;
    }

    // ─── Alignment guides ────────────────────────────────────────

    // Pixel-space rect for a (potentially virtual) slot at grid
    // coords. Avoids dependency on actual DOM rects so the dragged
    // slot's rect is consistent with the grid coords we're going to
    // write on drop.
    function slotRectPx(slot, canvasRect) {
        var colPx = canvasRect.width / GRID_COLS;
        var rowPx = currentRowPx();
        var w = colsForSize(slot.size) * colPx;
        var h = Math.max(1, slot.row_span | 0) * rowPx;
        return {
            left:   slot.x * colPx,
            right:  slot.x * colPx + w,
            top:    slot.y * rowPx,
            bottom: slot.y * rowPx + h,
            cx:     slot.x * colPx + w / 2,
            cy:     slot.y * rowPx + h / 2
        };
    }

    // Returns the list of guide lines to render. The canvas's own
    // left / right / centre count as legitimate alignment targets;
    // an alignment to the canvas centre is the cleanest cue when a
    // slot is being centred.
    function computeAlignmentGuides(draggedRect, otherSlots, canvasRect, tolerance) {
        var guides = [];
        var seen = {};
        function add(axis, pos) {
            var key = axis + ':' + Math.round(pos);
            if (seen[key]) return;
            seen[key] = true;
            guides.push({ axis: axis, pos: pos });
        }
        var canvasMids = {
            v: [0, canvasRect.width / 2, canvasRect.width],
            h: [0, canvasRect.height / 2, canvasRect.height]
        };
        var draggedV = [draggedRect.left, draggedRect.cx, draggedRect.right];
        var draggedH = [draggedRect.top,  draggedRect.cy, draggedRect.bottom];
        draggedV.forEach(function (d) {
            canvasMids.v.forEach(function (c) {
                if (Math.abs(d - c) <= tolerance) add('v', c);
            });
        });
        draggedH.forEach(function (d) {
            canvasMids.h.forEach(function (c) {
                if (Math.abs(d - c) <= tolerance) add('h', c);
            });
        });
        otherSlots.forEach(function (other) {
            var or = slotRectPx(other, canvasRect);
            var otherV = [or.left, or.cx, or.right];
            var otherH = [or.top,  or.cy, or.bottom];
            draggedV.forEach(function (d) {
                otherV.forEach(function (o) {
                    if (Math.abs(d - o) <= tolerance) add('v', o);
                });
            });
            draggedH.forEach(function (d) {
                otherH.forEach(function (o) {
                    if (Math.abs(d - o) <= tolerance) add('h', o);
                });
            });
        });
        return guides;
    }

    // The container `.tt-pde-guides` sits inside the canvas,
    // absolutely positioned, pointer-events disabled, sized to match
    // the canvas. Cleared via `clearAlignmentGuides()` on dragend /
    // drop / dragleave-from-canvas.
    function ensureGuideLayer() {
        if (!canvas) return null;
        var layer = canvas.querySelector(':scope > .tt-pde-guides');
        if (!layer) {
            layer = document.createElement('div');
            layer.className = 'tt-pde-guides';
            layer.setAttribute('aria-hidden', 'true');
            canvas.appendChild(layer);
        }
        return layer;
    }
    function renderAlignmentGuides(guides) {
        var layer = ensureGuideLayer();
        if (!layer) return;
        layer.innerHTML = '';
        guides.forEach(function (g) {
            var el = document.createElement('span');
            el.className = 'tt-pde-guide tt-pde-guide--' + (g.axis === 'v' ? 'vertical' : 'horizontal');
            if (g.axis === 'v') el.style.left = g.pos + 'px';
            else                el.style.top  = g.pos + 'px';
            layer.appendChild(el);
        });
    }
    function clearAlignmentGuides() {
        if (!canvas) return;
        var layer = canvas.querySelector(':scope > .tt-pde-guides');
        if (layer) layer.innerHTML = '';
    }

    // Pull the dragged slot's projected size + row_span based on
    // current drag state. Palette items default to the widget's
    // default_size; existing slots use their stored size.
    function draggedSlotShape() {
        var d = window.__ttPdeDrag;
        if (!d) return null;
        if (d.kind === 'add-widget') {
            var w = widgetById(d.paletteId);
            return { size: (w && w.default_size) || 'M', row_span: 1, slotId: null };
        }
        if (d.kind === 'add-kpi') {
            return { size: 'S', row_span: 1, slotId: null };
        }
        if (d.kind === 'move' && d.slotId) {
            var hit = findSlotAndBand(d.slotId);
            if (hit) return { size: hit.slot.size, row_span: hit.slot.row_span, slotId: d.slotId };
        }
        return null;
    }

    // Called from the canvas dragover handler. Cheap enough to run
    // every event at <= 30 slots; spec budget is < 16ms / event and
    // a 30×30 pair scan is sub-millisecond.
    function updateAlignmentGuides(ev) {
        if (!canvas || !state.template) return;
        var shape = draggedSlotShape();
        if (!shape) return;
        var coords = gridCellFromEvent(ev, shape.size);
        if (!coords) return;
        var canvasRect = canvas.getBoundingClientRect();
        var draggedSlot = { x: coords.x, y: coords.y, size: shape.size, row_span: shape.row_span };
        var draggedRect = slotRectPx(draggedSlot, canvasRect);
        var others = (state.template.grid || []).filter(function (s) { return s.__id !== shape.slotId; });
        var guides = computeAlignmentGuides(draggedRect, others, canvasRect, ALIGN_TOLERANCE_PX);
        renderAlignmentGuides(guides);
    }

    // ─── FLIP reflow animation ───────────────────────────────────

    // Captured before each renderAll(); compared after to drive a
    // FLIP transition (set transform to old position, transition
    // back to identity in the next frame). CSS transition on
    // `transform` does the actual animation — we just hand it the
    // before / after deltas.
    var slotRectsBeforeRender = {};
    function captureSlotRectsForFlip() {
        slotRectsBeforeRender = {};
        if (!canvas) return;
        canvas.querySelectorAll('[data-tt-pde-slot]').forEach(function (el) {
            var id = el.dataset.ttPdeSlot;
            var r = el.getBoundingClientRect();
            slotRectsBeforeRender[id] = { top: r.top, left: r.left };
        });
    }
    function playFlipAnimations() {
        if (!canvas) return;
        var rm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (rm) return;
        canvas.querySelectorAll('[data-tt-pde-slot]').forEach(function (el) {
            var id = el.dataset.ttPdeSlot;
            var prev = slotRectsBeforeRender[id];
            if (!prev) return;
            var now = el.getBoundingClientRect();
            var dx = prev.left - now.left;
            var dy = prev.top  - now.top;
            if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return;
            el.style.transition = 'none';
            el.style.transform  = 'translate(' + dx + 'px, ' + dy + 'px)';
            // Force a reflow so the transform commits before we
            // reset transition on the next frame. Reading
            // offsetHeight is the standard FLIP idiom.
            void el.offsetHeight;
            requestAnimationFrame(function () {
                el.style.transition = 'transform 150ms ease, box-shadow 0.12s ease';
                el.style.transform  = '';
            });
        });
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
            state.template.grid = state.template.grid || [];
            var coords = ev ? gridCellFromEvent(ev, slot.size) : null;
            if (coords) {
                if (ev && ev.shiftKey) {
                    var free = findNearestFreeSlot(state.template.grid, coords, slot.size);
                    if (free) { slot.x = free.x; slot.y = free.y; }
                    else      { slot.x = coords.x; slot.y = coords.y; }
                } else {
                    slot.x = coords.x;
                    slot.y = coords.y;
                }
            } else {
                var maxY = -1;
                state.template.grid.forEach(function (s) {
                    var bottom = s.y + Math.max(1, s.row_span);
                    if (bottom > maxY) maxY = bottom;
                });
                slot.y = Math.max(0, maxY);
                slot.x = 0;
            }
            state.template.grid.push(slot);
            reflow(state.template.grid, slot.__id);
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
        if (key === 'ArrowRight') hit.slot.x = Math.min(GRID_COLS - colsForSize(hit.slot.size), hit.slot.x + step);
        if (key === 'ArrowUp')    hit.slot.y = Math.max(0, hit.slot.y - step);
        if (key === 'ArrowDown')  hit.slot.y = hit.slot.y + step;
        // #0088 — nudging into an occupied cell pushes the occupant;
        // compact closes any gap left behind. Same engine as drag.
        reflow(state.template.grid, slotId);
        commit();
    }

    function removeSlot(slotId) {
        var hit = findSlotAndBand(slotId);
        if (!hit) return;
        if (hit.band === 'hero') state.template.hero = null;
        if (hit.band === 'task') state.template.task = null;
        if (hit.band === 'grid') {
            state.template.grid = (state.template.grid || []).filter(function (s) { return s.__id !== slotId; });
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
                    var max_x = Math.max(0, GRID_COLS - colsForSize(sz));
                    if (slot.x > max_x) slot.x = max_x;
                    // #0088 — a larger size may now overlap the slot's
                    // neighbours; reflow to push them down + compact.
                    reflow(state.template.grid, slot.__id);
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
            // #0088 — Escape during a drag clears alignment guides
            // immediately (the dragend follows but on slow systems
            // the user expects sub-frame feedback).
            if (e.key === 'Escape') clearAlignmentGuides();
        });

        // #0088 — Catch-all dragend so guides clear when the drag is
        // released outside any registered drop target (cursor leaves
        // the canvas, ESC, drop-on-non-target).
        document.addEventListener('dragend', function () { clearAlignmentGuides(); });

        loadPersona(state.persona);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
