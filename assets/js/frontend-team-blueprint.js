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
        wireStatusButtons();
    });

    function wireDragDrop(blueprintId, editor) {
        var roster = editor.querySelector('.tt-bp-roster-list');
        var targets = editor.querySelectorAll('.tt-bp-droptarget');

        editor.addEventListener('dragstart', function (e) {
            var chip = e.target.closest('.tt-bp-chip[draggable="true"]');
            if (!chip) return;
            chip.classList.add('tt-bp-chip-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', JSON.stringify({
                player_id: parseInt(chip.getAttribute('data-player-id'), 10),
                player_name: chip.getAttribute('data-player-name') || ''
            }));
        });
        editor.addEventListener('dragend', function (e) {
            var chip = e.target.closest('.tt-bp-chip');
            if (chip) chip.classList.remove('tt-bp-chip-dragging');
        });

        // Drop zones — slot targets on the pitch + roster panel for "remove".
        Array.prototype.forEach.call(targets, function (t) {
            t.addEventListener('dragover', function (e) {
                if (t.getAttribute('data-can-drag') !== '1') return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                t.classList.add('tt-bp-dragover');
            });
            t.addEventListener('dragleave', function () { t.classList.remove('tt-bp-dragover'); });
            t.addEventListener('drop', function (e) {
                e.preventDefault();
                t.classList.remove('tt-bp-dragover');
                var data = readDragData(e);
                if (!data) return;
                var slot = t.getAttribute('data-slot-label');
                saveAssignment(blueprintId, slot, data.player_id);
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
                // Find which slot the player was dropped FROM. We resolve it
                // by walking the targets and picking the one whose
                // data-player-id matches.
                var origin = findSlotForPlayer(targets, data.player_id);
                if (!origin) return;
                saveAssignment(blueprintId, origin, 0); // 0 = unassign
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
                return targets[i].getAttribute('data-slot-label');
            }
        }
        return null;
    }

    function saveAssignment(blueprintId, slotLabel, playerId) {
        var body = { slot_label: slotLabel };
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
