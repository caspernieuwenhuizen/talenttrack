/**
 * TalentTrack — Team Blueprint editor (#0068 Phase 1).
 *
 * Drag-drop lineup builder. Each drop sends a single PUT to
 * /blueprints/{id}/assignment so the back-end stays the source of
 * truth and the chemistry recompute happens server-side via
 * BlueprintChemistryEngine. Optimistic UI is intentionally avoided —
 * the round-trip is small and rendering the authoritative score +
 * line colours from the response keeps the front- and back-end in
 * sync without diff'ing locally.
 *
 * No framework. Pure addEventListener. Pitch SVG comes from PHP;
 * this file mutates it minimally on each save.
 */
(function () {
    'use strict';
    if (typeof window.TT_BLUEPRINT === 'undefined') return;

    var cfg = window.TT_BLUEPRINT;

    document.addEventListener('DOMContentLoaded', function () {
        var editor = document.querySelector('.tt-bp-editor');
        if (!editor) return;

        var blueprintId = parseInt(editor.getAttribute('data-blueprint-id'), 10);
        var locked      = editor.getAttribute('data-locked') === '1';
        var canManage   = editor.getAttribute('data-can-manage') === '1';
        if (!blueprintId || !canManage || locked) {
            wireStatusButtons(); // still allow status changes (reopen) when locked
            // v3.110.184 — chemistry-hide toggle works in locked mode
            // too (read-only viewing); register only that handler.
            wireHideChemistryToggle(blueprintId);
            return;
        }

        wireDragDrop(blueprintId, editor);
        wireTouchDragDrop(blueprintId, editor); // #0068 Phase 4 — mobile fallback
        wireStatusButtons();

        // v3.110.184 — tap-to-swap picker + chemistry-hide toggle + Save/Save-As.
        wireTapToSwap(blueprintId);
        wireHideChemistryToggle(blueprintId);
        wireToolbarButtons(blueprintId);
        wirePickerDismiss();
    });

    function wireDragDrop(blueprintId, editor) {
        var roster = editor.querySelector('.tt-bp-roster-list');

        // Pitch + depth-chart cells share the same drop logic via the
        // .tt-bp-droptarget class on pitch slots and the
        // .tt-bp-droptarget-cell class on depth-chart cells.
        var pitchTargets = editor.querySelectorAll('.tt-bp-droptarget');
        // Depth-chart cells live OUTSIDE .tt-bp-editor (rendered after
        // the editor div) so we look at the document level for them.
        var depthCells = document.querySelectorAll('.tt-bp-droptarget-cell');
        var allTargets = [].slice.call(pitchTargets).concat([].slice.call(depthCells));

        // dragstart: any draggable chip — roster chip or depth-chart chip.
        document.addEventListener('dragstart', function (e) {
            var chip = e.target.closest('[draggable="true"][data-player-id]');
            if (!chip) return;
            chip.classList.add('tt-bp-chip-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', JSON.stringify({
                player_id: parseInt(chip.getAttribute('data-player-id'), 10),
                player_name: chip.getAttribute('data-player-name') || '',
                is_trial: chip.getAttribute('data-is-trial') === '1'
            }));
        });
        document.addEventListener('dragend', function (e) {
            var chip = e.target.closest('[data-player-id]');
            if (chip) chip.classList.remove('tt-bp-chip-dragging');
        });

        allTargets.forEach(function (t) {
            t.addEventListener('dragover', function (e) {
                if (t.getAttribute('data-can-drag') !== '1') return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                t.classList.add('tt-bp-dragover');
            });
            t.addEventListener('dragleave', function () { t.classList.remove('tt-bp-dragover'); });
            t.addEventListener('drop', function (e) {
                if (t.getAttribute('data-can-drag') !== '1') return;
                e.preventDefault();
                t.classList.remove('tt-bp-dragover');
                var data = readDragData(e);
                if (!data) return;
                var slot = t.getAttribute('data-slot-label');
                var tier = t.getAttribute('data-tier') || 'primary';
                saveAssignment(blueprintId, slot, tier, data.player_id);
            });
        });

        if (roster) {
            roster.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                roster.classList.add('tt-bp-dragover');
            });
            roster.addEventListener('dragleave', function () { roster.classList.remove('tt-bp-dragover'); });
            roster.addEventListener('drop', function (e) {
                e.preventDefault();
                roster.classList.remove('tt-bp-dragover');
                var data = readDragData(e);
                if (!data) return;
                var origin = findSlotForPlayer(allTargets, data.player_id);
                if (!origin) return;
                saveAssignment(blueprintId, origin.slot, origin.tier, 0);
            });
        }
    }

    function readDragData(e) {
        try {
            var raw = e.dataTransfer.getData('text/plain');
            if (!raw) return null;
            var d = JSON.parse(raw);
            if (!d || !d.player_id) return null;
            return d;
        } catch (err) { return null; }
    }

    function findSlotForPlayer(targets, playerId) {
        for (var i = 0; i < targets.length; i++) {
            if (parseInt(targets[i].getAttribute('data-player-id'), 10) === playerId) {
                return {
                    slot: targets[i].getAttribute('data-slot-label'),
                    tier: targets[i].getAttribute('data-tier') || 'primary'
                };
            }
        }
        return null;
    }

    function saveAssignment(blueprintId, slotLabel, tier, playerId) {
        // #953 — in-repo callers send the canonical `ref` shape. The
        // REST controller's coerceAssignmentRef() shim still accepts
        // the legacy flat `player_id` for documented external API
        // consumers (sunset v5.0.0 per docs/rest-api.md).
        var body = { slot_label: slotLabel, tier: tier || 'primary' };
        body.ref = playerId > 0
            ? { kind: 'player', player_id: playerId }
            : null;

        fetch(cfg.rest_root + '/blueprints/' + blueprintId + '/assignment', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce
            },
            body: JSON.stringify(body)
        })
        .then(function (r) {
            if (!r.ok) throw new Error('save_failed');
            return r.json();
        })
        .then(function (resp) {
            // The response is { success: true, data: { ... } } via RestResponse.
            var payload = resp && resp.data ? resp.data : resp;
            applyServerState(payload);
        })
        .catch(function () {
            alert(cfg.i18n.save_failed);
            // Rebuild from page reload — keeps local + server consistent.
            window.location.reload();
        });
    }

    /**
     * Apply the authoritative state coming back from the assignment
     * save. The simplest correct path: reload the page so all PHP-
     * rendered chips + slots + chemistry lines re-render server-side.
     * Avoids re-implementing the slot rendering in JS.
     */
    function applyServerState(payload) {
        if (!payload) return;
        // Targeted refresh: just reload. The page is small.
        window.location.reload();
    }

    /**
     * #0068 Phase 4 — mobile drag-drop fallback via PointerEvents.
     *
     * iOS Safari mangles HTML5 `draggable=true` on iPhone (works fine
     * on iPad). The fallback: long-press 300ms on a roster chip →
     * pickup → drag preview follows pointer → drop on a slot or roster.
     * Mouse pointers fall through to the existing HTML5 dragstart
     * handler, which is well-supported on every desktop browser.
     *
     * Spec decision Q4 (300ms) + Q5 (vibrate on pickup + drop, no
     * auto-scroll). Locked inside `frontend-team-blueprint.js` rather
     * than a separate JS file so the existing handler context (cfg,
     * saveAssignment, applyServerState) stays in scope.
     */
    function wireTouchDragDrop(blueprintId, editor) {
        if (typeof window.PointerEvent === 'undefined') return;

        var LONG_PRESS_MS = 300;
        var pressTimer    = null;
        var pickup        = null;   // { chip, data, originSlot, originTier }
        var preview       = null;   // floating clone element
        var lastTarget    = null;   // current highlighted drop target

        function rosterEl() { return editor.querySelector('.tt-bp-roster-list'); }

        function findDropTargetAt(x, y) {
            // elementFromPoint runs against the viewport so the floating
            // preview must be styled with pointer-events: none for this
            // to land on the underlying slot.
            var el = document.elementFromPoint(x, y);
            if (!el) return null;
            var slot = el.closest('.tt-bp-droptarget, .tt-bp-droptarget-cell');
            if (slot) return { kind: 'slot', el: slot };
            var roster = el.closest('.tt-bp-roster-list');
            if (roster) return { kind: 'roster', el: roster };
            return null;
        }

        function setHighlight(target) {
            if (lastTarget && lastTarget.el !== (target && target.el)) {
                lastTarget.el.classList.remove('tt-bp-dragover');
            }
            if (target) target.el.classList.add('tt-bp-dragover');
            lastTarget = target;
        }

        function buildPreview(chip, x, y) {
            var clone = chip.cloneNode(true);
            clone.removeAttribute('draggable');
            clone.style.position = 'fixed';
            clone.style.left = (x - 40) + 'px';
            clone.style.top  = (y - 24) + 'px';
            clone.style.zIndex = '9999';
            clone.style.pointerEvents = 'none';
            clone.style.opacity = '0.85';
            clone.style.boxShadow = '0 4px 16px rgba(0,0,0,0.2)';
            clone.classList.add('tt-bp-touch-preview');
            document.body.appendChild(clone);
            return clone;
        }

        function vibrate(ms) {
            if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
                try { navigator.vibrate(ms); } catch (e) { /* ignored */ }
            }
        }

        function clearPickupState() {
            if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
            if (preview && preview.parentNode) preview.parentNode.removeChild(preview);
            preview = null;
            if (lastTarget) lastTarget.el.classList.remove('tt-bp-dragover');
            lastTarget = null;
            if (pickup && pickup.chip) pickup.chip.classList.remove('tt-bp-chip-dragging');
            pickup = null;
        }

        document.addEventListener('pointerdown', function (e) {
            if (e.pointerType !== 'touch') return; // mouse falls through to HTML5 path
            var chip = e.target.closest('[draggable="true"][data-player-id]');
            if (!chip) return;
            var startX = e.clientX, startY = e.clientY;
            var pid    = parseInt(chip.getAttribute('data-player-id'), 10);
            if (!pid) return;
            var origin = findSlotForPlayerOnPage(pid);

            pressTimer = setTimeout(function () {
                pressTimer = null;
                pickup = {
                    chip: chip,
                    data: { player_id: pid },
                    originSlot: origin ? origin.slot : null,
                    originTier: origin ? origin.tier : 'primary'
                };
                chip.classList.add('tt-bp-chip-dragging');
                preview = buildPreview(chip, startX, startY);
                vibrate(50);
                // Block the default touch scroll once we're actually
                // dragging. Until pickup fires, scroll wins — that's
                // why short taps don't accidentally pick up a chip.
                document.body.style.userSelect = 'none';
            }, LONG_PRESS_MS);
        }, { passive: true });

        document.addEventListener('pointermove', function (e) {
            if (e.pointerType !== 'touch') return;
            // Cancel pending pickup if the finger moved >8px before the
            // long-press fired (user is scrolling, not dragging).
            if (pressTimer && Math.hypot(e.movementX || 0, e.movementY || 0) > 8) {
                clearTimeout(pressTimer);
                pressTimer = null;
            }
            if (!pickup) return;
            e.preventDefault();
            if (preview) {
                preview.style.left = (e.clientX - 40) + 'px';
                preview.style.top  = (e.clientY - 24) + 'px';
            }
            setHighlight(findDropTargetAt(e.clientX, e.clientY));
        }, { passive: false });

        document.addEventListener('pointerup', function (e) {
            if (e.pointerType !== 'touch') return;
            if (!pickup) { clearPickupState(); return; }
            var target = findDropTargetAt(e.clientX, e.clientY);
            var data   = pickup.data;
            var origin = { slot: pickup.originSlot, tier: pickup.originTier };
            clearPickupState();
            document.body.style.userSelect = '';
            if (!target) return;
            vibrate(50);
            if (target.kind === 'slot') {
                if (target.el.getAttribute('data-can-drag') !== '1') return;
                var slot = target.el.getAttribute('data-slot-label');
                var tier = target.el.getAttribute('data-tier') || 'primary';
                saveAssignment(blueprintId, slot, tier, data.player_id);
            } else if (target.kind === 'roster' && origin.slot) {
                saveAssignment(blueprintId, origin.slot, origin.tier, 0);
            }
        }, { passive: true });

        document.addEventListener('pointercancel', function () {
            clearPickupState();
            document.body.style.userSelect = '';
        });

        function findSlotForPlayerOnPage(playerId) {
            var pitchTargets = editor.querySelectorAll('.tt-bp-droptarget');
            var depthCells   = document.querySelectorAll('.tt-bp-droptarget-cell');
            var all = [].slice.call(pitchTargets).concat([].slice.call(depthCells));
            for (var i = 0; i < all.length; i++) {
                if (parseInt(all[i].getAttribute('data-player-id'), 10) === playerId) {
                    return {
                        slot: all[i].getAttribute('data-slot-label'),
                        tier: all[i].getAttribute('data-tier') || 'primary'
                    };
                }
            }
            return null;
        }
    }

    function wireStatusButtons() {
        var buttons = document.querySelectorAll('.tt-bp-status-btn');
        Array.prototype.forEach.call(buttons, function (b) {
            b.addEventListener('click', function () {
                var holder = b.closest('.tt-bp-status-actions');
                if (!holder) return;
                var blueprintId = parseInt(holder.getAttribute('data-blueprint-id'), 10);
                var target = b.getAttribute('data-target-status');
                if (!blueprintId || !target) return;
                b.disabled = true;
                fetch(cfg.rest_root + '/blueprints/' + blueprintId + '/status', {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': cfg.nonce
                    },
                    body: JSON.stringify({ status: target })
                })
                .then(function (r) {
                    if (!r.ok) throw new Error('status_failed');
                    window.location.reload();
                })
                .catch(function () {
                    alert(cfg.i18n.save_failed);
                    b.disabled = false;
                });
            });
        });
    }

    // ============================================================
    // v3.110.184 — tap-to-swap picker (three-tier "show all" layout)
    // ============================================================

    var openSheet = null;

    function wireTapToSwap(blueprintId) {
        // Tap any droptarget on the pitch → open the picker. The
        // droptarget already carries data-slot-label + data-can-drag.
        // Drag-and-drop stays as a power-user fallback (see wireDragDrop
        // above) — both handlers can coexist because the picker only
        // fires on plain `click` (not drag events).
        document.addEventListener('click', function (e) {
            var target = e.target.closest && e.target.closest('.tt-bp-droptarget');
            if (!target) return;
            if (target.getAttribute('data-can-drag') !== '1') return;
            // Ignore clicks that originated from a chip inside the
            // droptarget — those have their own behaviour (drag).
            if (e.target.closest('.tt-bp-chip')) return;
            e.preventDefault();
            openPicker(blueprintId, target.getAttribute('data-slot-label'));
        });

        // Keyboard parity. Slots are focusable when the picker is
        // available (toolbar adds tabindex via the editor mount).
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var target = e.target.closest && e.target.closest('.tt-bp-droptarget');
            if (!target) return;
            if (target.getAttribute('data-can-drag') !== '1') return;
            e.preventDefault();
            openPicker(blueprintId, target.getAttribute('data-slot-label'));
        });
    }

    function wirePickerDismiss() {
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closePicker();
        });
    }

    function openPicker(blueprintId, slotLabel) {
        closePicker();
        if (!slotLabel) return;
        var sheet = buildSheet(slotLabel, blueprintId);
        document.body.appendChild(sheet);
        document.body.classList.add('tt-bp-picker-open');
        openSheet = sheet;

        // Focus the close button so Escape / Tab works out of the gate.
        var close = sheet.querySelector('.tt-bp-picker-close');
        if (close) close.focus();
    }

    function closePicker() {
        if (openSheet) {
            openSheet.parentNode.removeChild(openSheet);
            openSheet = null;
            document.body.classList.remove('tt-bp-picker-open');
        }
    }

    function buildSheet(slotLabel, blueprintId) {
        var sheet = document.createElement('div');
        sheet.className = 'tt-bp-picker';
        sheet.setAttribute('role', 'dialog');
        sheet.setAttribute('aria-modal', 'true');

        var titleTpl = cfg.i18n.picker_title || 'Assign players to %s';
        var title = titleTpl.replace('%s', slotLabel);

        var html = '<div class="tt-bp-picker-backdrop" data-close></div>' +
                   '<div class="tt-bp-picker-sheet">' +
                       '<header class="tt-bp-picker-header">' +
                           '<h3 class="tt-bp-picker-title">' + escapeHtml(title) + '</h3>' +
                           '<button type="button" class="tt-btn tt-btn-secondary tt-btn-sm tt-bp-picker-close" data-close>' +
                               escapeHtml(cfg.i18n.picker_close || 'Done') +
                           '</button>' +
                       '</header>' +
                       '<div class="tt-bp-picker-body">' +
                           tierSection('primary',   cfg.i18n.tier_primary,   slotLabel) +
                           tierSection('secondary', cfg.i18n.tier_secondary, slotLabel) +
                           tierSection('tertiary',  cfg.i18n.tier_tertiary,  slotLabel) +
                       '</div>' +
                   '</div>';
        sheet.innerHTML = html;

        sheet.querySelectorAll('[data-close]').forEach(function (el) {
            el.addEventListener('click', function () { closePicker(); });
        });
        sheet.querySelectorAll('.tt-bp-picker-tier-clear').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tier = btn.getAttribute('data-tier');
                applyTierAssignment(blueprintId, slotLabel, tier, null);
            });
        });
        sheet.querySelectorAll('.tt-bp-picker-player').forEach(function (row) {
            row.addEventListener('click', function () {
                var tier = row.getAttribute('data-tier');
                var pid  = parseInt(row.getAttribute('data-player-id'), 10);
                applyTierAssignment(blueprintId, slotLabel, tier, pid);
            });
        });

        return sheet;
    }

    /**
     * Render one tier section: heading + current-assignment line +
     * roster list. All three sections are shown stacked (user asked
     * for "show all" layout — no segmented control / tabs).
     */
    function tierSection(tier, label, slotLabel) {
        var current = currentPlayerFor(slotLabel, tier);
        var currentLabel = current ? current.name : ''; // empty when not assigned
        var clearVisible = current ? '' : ' hidden';

        var rows = '';
        (cfg.roster || []).forEach(function (p) {
            var selectedCls = (current && current.id === p.id) ? ' is-selected' : '';
            rows +=
                '<li class="tt-bp-picker-player' + selectedCls + '"' +
                    ' role="option"' +
                    ' tabindex="0"' +
                    ' data-tier="' + escapeAttr(tier) + '"' +
                    ' data-player-id="' + p.id + '">' +
                    '<span class="tt-bp-picker-player-name">' + escapeHtml(p.name) + '</span>' +
                    ((current && current.id === p.id)
                        ? '<span class="tt-bp-picker-player-badge">' + escapeHtml(cfg.i18n.tier_current || 'Currently assigned') + '</span>'
                        : '') +
                '</li>';
        });

        return '<section class="tt-bp-picker-tier" data-tier="' + escapeAttr(tier) + '">' +
                   '<header class="tt-bp-picker-tier-head">' +
                       '<h4 class="tt-bp-picker-tier-label">' + escapeHtml(label) + '</h4>' +
                       '<span class="tt-bp-picker-tier-current">' + escapeHtml(currentLabel) + '</span>' +
                       '<button type="button" class="tt-btn tt-btn-secondary tt-btn-sm tt-bp-picker-tier-clear"' +
                            ' data-tier="' + escapeAttr(tier) + '"' + clearVisible + '>' +
                           escapeHtml(cfg.i18n.tier_clear || 'Clear this tier') +
                       '</button>' +
                   '</header>' +
                   '<ul class="tt-bp-picker-roster" role="listbox">' + rows + '</ul>' +
               '</section>';
    }

    function currentPlayerFor(slotLabel, tier) {
        var slotMap = (cfg.assignments || {})[slotLabel] || {};
        var pid = slotMap[tier];
        if (!pid) return null;
        var found = null;
        (cfg.roster || []).forEach(function (p) {
            if (p.id === pid) found = { id: p.id, name: p.name };
        });
        return found || { id: pid, name: '#' + pid };
    }

    /**
     * Send a single (slot, tier, player_id) change to the existing
     * PUT /blueprints/{id}/assignment endpoint. Patch local
     * `cfg.assignments` on success, re-render the picker tier section
     * so the badge / clear button reflect the new state. The
     * chemistry headline + pitch link colours don't repaint without a
     * server recompute — for now, the user reloads to refresh those
     * (same fallback the drag-drop path takes on its less common
     * paths). Future iteration can wire chemistry/preview here.
     */
    function applyTierAssignment(blueprintId, slotLabel, tier, playerId) {
        fetch(cfg.rest_root + '/blueprints/' + blueprintId + '/assignment', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce
            },
            body: JSON.stringify({
                slot_label: slotLabel,
                tier:       tier,
                // #953 — `ref` shape; null/0 player_id clears the cell.
                ref: ( playerId === null || playerId === 0 )
                    ? null
                    : { kind: 'player', player_id: playerId }
            })
        })
        .then(function (r) {
            if (!r.ok) throw new Error('save_failed');
            return r.json();
        })
        .then(function () {
            // Patch local config so the picker re-render reflects the
            // new state.
            if (!cfg.assignments[slotLabel]) cfg.assignments[slotLabel] = {};
            if (playerId === null) {
                delete cfg.assignments[slotLabel][tier];
            } else {
                cfg.assignments[slotLabel][tier] = playerId;
            }
            // Reload to refresh the pitch (occupant names + chemistry
            // lines). Same fallback the drag-drop path uses for full
            // re-sync. The picker stays open via the reload's
            // sessionStorage; we just close it before reload so it
            // isn't a confusing dangling overlay.
            closePicker();
            window.location.reload();
        })
        .catch(function () {
            alert(cfg.i18n.save_failed);
        });
    }

    // ============================================================
    // v3.110.184 — Hide-chemistry toggle (sessionStorage-persisted)
    // ============================================================

    function wireHideChemistryToggle(blueprintId) {
        var btn = document.querySelector('.tt-bp-hide-chem-toggle');
        if (!btn) return;
        var storageKey = 'tt_bp_hide_chem_' + blueprintId;
        var initial = false;
        try { initial = sessionStorage.getItem(storageKey) === '1'; } catch (e) { /* ignore */ }
        applyHideChem(initial, btn);

        btn.addEventListener('click', function () {
            var next = !document.body.classList.contains('tt-bp-chem-hidden');
            applyHideChem(next, btn);
            try { sessionStorage.setItem(storageKey, next ? '1' : '0'); } catch (e) { /* ignore */ }
        });
    }

    function applyHideChem(hidden, btn) {
        document.body.classList.toggle('tt-bp-chem-hidden', hidden);
        btn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
        btn.textContent = hidden
            ? (cfg.i18n.show_chem_label || 'Show chemistry')
            : (cfg.i18n.hide_chem_label || 'Hide chemistry');
    }

    // ============================================================
    // v3.110.184 — Save / Save As toolbar buttons
    // ============================================================

    function wireToolbarButtons(blueprintId) {
        var toolbar = document.querySelector('.tt-bp-editor-toolbar');
        if (!toolbar) return;
        var listUrl = toolbar.getAttribute('data-list-url') || '';

        var saveBtn = toolbar.querySelector('.tt-bp-save-done');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                // Auto-save fired on every drop and every picker change.
                // Save is "done editing" — return to the list with a
                // toast hint that the controller can render.
                var url = listUrl + (listUrl.indexOf('?') === -1 ? '?' : '&') + 'tt_saved=1';
                window.location.href = url;
            });
        }

        var saveAsBtn = toolbar.querySelector('.tt-bp-save-as');
        if (saveAsBtn) {
            saveAsBtn.addEventListener('click', function () {
                var name = window.prompt(
                    cfg.i18n.save_as_prompt || 'Name the new blueprint:',
                    cfg.i18n.save_as_default || 'Copy of blueprint'
                );
                if (name === null) return;
                name = (name || '').trim();
                if (name === '') return;
                saveAsBtn.disabled = true;
                fetch(cfg.rest_root + '/blueprints/' + blueprintId + '/clone', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': cfg.nonce
                    },
                    body: JSON.stringify({ name: name })
                })
                .then(function (r) {
                    if (!r.ok) throw new Error('clone_failed');
                    return r.json();
                })
                .then(function (resp) {
                    var newId = (resp && resp.data && resp.data.id) || (resp && resp.id);
                    if (!newId) throw new Error('clone_failed');
                    // Same path the user is on; just swap the id.
                    var url = window.location.pathname + window.location.search;
                    url = url.replace(/([?&])id=\d+/, '$1id=' + newId);
                    if (url.indexOf('id=') === -1) {
                        url += (url.indexOf('?') === -1 ? '?' : '&') + 'id=' + newId;
                    }
                    window.location.href = url;
                })
                .catch(function () {
                    saveAsBtn.disabled = false;
                    alert(cfg.i18n.save_as_failed || cfg.i18n.save_failed);
                });
            });
        }
    }

    // ============================================================
    // helpers
    // ============================================================

    function escapeHtml(s) {
        s = String(s == null ? '' : s);
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function escapeAttr(s) { return escapeHtml(s); }
})();
