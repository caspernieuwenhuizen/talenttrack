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
            return;
        }

        wireDragDrop(blueprintId, editor);
        wireTouchDragDrop(blueprintId, editor); // #0068 Phase 4 — mobile fallback
        wireStatusButtons();
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
        var body = { slot_label: slotLabel, tier: tier || 'primary' };
        if (playerId > 0) body.player_id = playerId;

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
})();
