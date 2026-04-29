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
            var item = document.createElement('button');
            item.type = 'button';
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
            head.setAttribute('aria-expanded', 'true');
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
                var item = document.createElement('button');
                item.type = 'button';
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
            });
            t.node.addEventListener('drop', function (e) {
                e.preventDefault();
                handleDropOnBand(t.kind, e);
            });
        });
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
            card.classList.add('is-grabbed');
        });
        card.addEventListener('dragend', function () {
            window.__ttPdeDrag = null;
            card.classList.remove('is-grabbed');
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
            // Drop into grid — naive auto-place: lowest open row, left edge.
            var maxY = -1;
            (state.template.grid || []).forEach(function (s) {
                var bottom = s.y + Math.max(1, s.row_span);
                if (bottom > maxY) maxY = bottom;
            });
            slot.y = Math.max(0, maxY);
            slot.x = 0;
            state.template.grid = state.template.grid || [];
            state.template.grid.push(slot);
        }
        commit();
        selectSlot(slot.__id);
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
            var maxY = -1;
            (state.template.grid || []).forEach(function (s) {
                var bottom = s.y + Math.max(1, s.row_span);
                if (bottom > maxY) maxY = bottom;
            });
            slot.y = Math.max(0, maxY);
            slot.x = 0;
            state.template.grid = state.template.grid || [];
            state.template.grid.push(slot);
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

        // Data source — KPI dropdown for kpi_card; data table preset for data_table; nav slug for navigation_tile; etc.
        if (ref[0] === 'kpi_card') {
            form.appendChild(field(I18N.data_source || 'Data source', kpiSelect(slot)));
        } else if (ref[0] === 'navigation_tile' || ref[0] === 'action_card' || ref[0] === 'info_card' || ref[0] === 'data_table' || ref[0] === 'mini_player_list') {
            form.appendChild(field(I18N.data_source || 'Data source', dataSourceText(slot)));
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
                commit();
                renderProperties();
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
