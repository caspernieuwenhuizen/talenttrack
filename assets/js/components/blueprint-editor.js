/**
 * TalentTrack — Blueprint editor.
 *
 * Clean port of `.local-mockups/blueprint-editor/index.html`. Each
 * pitch position renders a numbered circle plus a three-row tier stack
 * (1 / 2 / 3) directly below it. The roster sidebar shows a draggable
 * list of players, each row carrying an `xN` placement badge that
 * counts how many slots reference that player in the CURRENT formation.
 *
 * Interactions:
 *   - Click a tier slot   -> picker opens beside it; search filters in
 *                            real time; pick a player to assign, or
 *                            click "Clear this slot" if filled.
 *   - Drag a roster row   -> drop onto a tier slot to assign.
 *   - Drop on a filled    -> replaces the previous occupant (does NOT
 *     slot                  pull them from other slots).
 *   - Add guest / custom  -> inline 3-tab form (Other team / Guest /
 *     / cross-team          Custom); persists on placement only.
 *   - Switch formation    -> assignments survive by slot label; slots
 *                            dropped from the previous formation are
 *                            hidden but their data is preserved so a
 *                            round-trip switch restores them.
 *   - Clear all slots     -> confirms then removes every assignment.
 *
 * The chemistry headline + status / save / save-as / hide-chem
 * toolbars are PHP-rendered; this file lifts the handlers for them
 * (previously in `frontend-team-blueprint.js`, retired in v4.6.0).
 *
 * Every successful assignment / clear / formation change posts to REST
 * and then reloads the page, so the chemistry score, pitch occupants
 * and any server-side state always come back authoritative.
 */
(function () {
    'use strict';

    if (typeof window.TT_BLUEPRINT_EDITOR === 'undefined') return;
    var cfg = window.TT_BLUEPRINT_EDITOR;

    // -------- state ---------------------------------------------------
    // Roster is the team's players (kind = 'player') plus any
    // session-only guest / custom / cross-team entries the user has
    // added during this session. Items in `assignment_refs` for kinds
    // other than 'player' are re-seeded into the roster on load so a
    // returning user sees their picks.
    var roster = (cfg.roster || []).slice();

    // assignment_refs is { slot_label: { tier: ref } } where ref is one of:
    //   { kind: 'player', player_id, display_name, team_id, team_name }
    //   { kind: 'guest',  name, position, display_name }
    //   { kind: 'custom', label, display_name }
    var refs = deepClone(cfg.assignment_refs || {});

    // The active formation template id (server-side authoritative on
    // first render, mutated by the dropdown switch).
    var currentFormationId = (cfg.formation && cfg.formation.template_id) || 0;

    // Roster ids of session-only entries (kind != 'player') so we can
    // assign synthetic roster_ids that don't collide with player IDs.
    var nextSessionId = 1;

    // Surface the session-only refs from `refs` (guests/customs the
    // user placed previously) back into the roster list on first
    // render so they show with an x1+ badge.
    seedSessionOnlyRosterFromRefs();

    // -------- DOM hooks -----------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('.tt-bpe-editor');
        if (!root) return;

        renderRoster();
        renderPitch();
        wireToolbar(root);
        wireAddForm(root);
        wireDocClicks();

        // Pre-existing toolbar (Save / Save As / Hide chemistry) and
        // status row (Share / Lock / Reopen / Move back to draft) sit
        // OUTSIDE `.tt-bpe-editor` — they were rendered by the view's
        // `renderEditorToolbar()` + `renderStatusRow()`. Wire them
        // unconditionally so they work even when the editor is
        // locked / read-only.
        wireHideChemistryToggle();
        wireSaveToolbar();
        wireStatusButtons();
    });

    // ==================== rendering ====================================

    function renderRoster() {
        var ul = document.querySelector('.tt-bpe-roster-list');
        if (!ul) return;
        ul.innerHTML = '';
        if (!roster.length) {
            var li = document.createElement('li');
            li.className = 'tt-bpe-roster-empty';
            li.textContent = (cfg.i18n && cfg.i18n.roster_empty) || 'No players on this team yet.';
            ul.appendChild(li);
            return;
        }
        roster.forEach(function (p) {
            ul.appendChild(buildRosterRow(p));
        });
        var titleEl = document.querySelector('.tt-bpe-roster-count');
        if (titleEl) {
            titleEl.textContent = '(' + roster.length + ')';
        }
    }

    function buildRosterRow(p) {
        var li = document.createElement('li');
        li.className = 'tt-bpe-roster-row';
        li.dataset.rosterId = p.roster_id;
        var canDrag = cfg.can_manage && !cfg.locked;
        li.draggable = !!canDrag;
        if (canDrag) {
            li.addEventListener('dragstart', function (e) {
                li.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', p.roster_id);
            });
            li.addEventListener('dragend', function () {
                li.classList.remove('is-dragging');
            });
        }

        var av = document.createElement('span');
        av.className = 'tt-bpe-av tt-bpe-av-' + (p.kind || 'player');
        av.textContent = initials(p.name || '');

        var who = document.createElement('span');
        who.className = 'tt-bpe-who';

        var nameLine = document.createElement('span');
        nameLine.className = 'tt-bpe-who-name';
        nameLine.appendChild(document.createTextNode(p.name || ''));
        var count = placementCount(p);
        if (count > 0) {
            var badge = document.createElement('span');
            badge.className = 'tt-bpe-pick-count';
            badge.textContent = 'x' + count;
            badge.setAttribute('aria-label', 'placed ' + count + ' times');
            nameLine.appendChild(badge);
        }

        var metaLine = document.createElement('span');
        metaLine.className = 'tt-bpe-who-meta';
        metaLine.textContent = metaSuffix(p);

        who.appendChild(nameLine);
        who.appendChild(metaLine);

        li.appendChild(av);
        li.appendChild(who);

        // Guest / custom / cross-team rows are session-only — the user
        // who added them gets a small `x` button to drop them back out
        // of the roster (and clear any cell currently holding them).
        // Real club-roster `player` rows have no remove button; they
        // belong to the team membership, not this blueprint session.
        if (canDrag && p.kind && p.kind !== 'player') {
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'tt-bpe-roster-remove';
            var removeLabel = (cfg.i18n && cfg.i18n.remove_from_roster) || 'Remove from roster';
            removeBtn.setAttribute('aria-label', removeLabel);
            removeBtn.title = removeLabel;
            removeBtn.textContent = '×';
            // Prevent the row's dragstart from firing if the user
            // mousedown-clicks the remove button.
            removeBtn.addEventListener('mousedown', function (e) { e.stopPropagation(); });
            removeBtn.addEventListener('dragstart', function (e) { e.preventDefault(); });
            removeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                removeRosterEntry(p);
            });
            li.appendChild(removeBtn);
        }

        // Click anywhere on the row opens the picker for the next
        // empty slot? No — that's too magical. The mockup leaves
        // click as a no-op on the roster (drag is the only action).
        return li;
    }

    function removeRosterEntry(p) {
        if (!p || !p.roster_id) return;
        if (!cfg.can_manage || cfg.locked) return;
        // Confirm — destructive, since any cell currently holding
        // this entry is unbound. Pattern mirrors activity guest-remove.
        var fmt = (cfg.i18n && cfg.i18n.confirm_remove_roster)
            || 'Remove %s from the roster? Any slots holding this entry will be cleared.';
        if (!window.confirm(fmt.replace('%s', p.name || ''))) return;

        // Build the next-state ref map: copy everything EXCEPT cells
        // currently pointing at this roster entry.
        var nextRefs = {};
        Object.keys(refs).forEach(function (slotLabel) {
            var tiers = refs[slotLabel] || {};
            var keep = {};
            ['primary', 'secondary', 'tertiary'].forEach(function (tier) {
                var ref = tiers[tier];
                if (!ref) return;
                if (rosterIdForRef(ref) === p.roster_id) return; // drop
                keep[tier] = ref;
            });
            if (Object.keys(keep).length > 0) {
                nextRefs[slotLabel] = keep;
            }
        });

        saveHint((cfg.i18n && cfg.i18n.saving) || 'Saving...');
        fetch(cfg.rest_root + '/blueprints/' + cfg.blueprint_id + '/assignments', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce
            },
            body: JSON.stringify({ assignments: nextRefs })
        })
        .then(function (r) {
            if (!r.ok) throw new Error('save_failed');
            // Reload so the server-side roster seed + chemistry come
            // back authoritative. The session-only entry will not be
            // re-seeded because no ref references it any more.
            window.location.reload();
        })
        .catch(function () {
            alert((cfg.i18n && cfg.i18n.save_failed) || 'Could not save the change. Try again.');
            saveHint('');
        });
    }

    function metaSuffix(p) {
        var bits = [];
        bits.push(p.pos || '-');
        if (p.age && p.age > 0) {
            var ageFmt = (cfg.i18n && cfg.i18n.age_fmt) || 'age %d';
            bits.push(ageFmt.replace('%d', String(p.age)));
        }
        if (p.kind && p.kind !== 'player') {
            var kindLabel = (cfg.i18n && cfg.i18n['kind_' + p.kind]) || p.kind;
            bits.push(kindLabel);
        }
        return bits.join(' . ');
    }

    function placementCount(p) {
        var n = 0;
        var slots = activeSlotLabels();
        for (var i = 0; i < slots.length; i++) {
            var slotRefs = refs[slots[i]] || {};
            ['primary', 'secondary', 'tertiary'].forEach(function (tier) {
                if (rosterIdForRef(slotRefs[tier]) === p.roster_id) n++;
            });
        }
        return n;
    }

    function renderPitch() {
        var wrap = document.querySelector('.tt-bpe-pitch-wrap');
        if (!wrap) return;
        // Wipe previous position cards.
        Array.prototype.slice.call(wrap.querySelectorAll('.tt-bpe-pos'))
            .forEach(function (el) { el.parentNode.removeChild(el); });

        var slots = activeSlots();
        slots.forEach(function (slot) {
            wrap.appendChild(buildPositionCard(slot));
        });
    }

    function buildPositionCard(slot) {
        var card = document.createElement('div');
        card.className = 'tt-bpe-pos';
        card.style.left = (slot.x * 100) + '%';
        card.style.top  = (slot.y * 100) + '%';
        card.dataset.slotLabel = slot.label;

        var circle = document.createElement('div');
        circle.className = 'tt-bpe-circle';
        var numEl = document.createElement('span');
        numEl.className = 'tt-bpe-circle-num';
        numEl.textContent = slot.num != null && slot.num > 0 ? String(slot.num) : '';
        var abbrEl = document.createElement('span');
        abbrEl.className = 'tt-bpe-circle-abbr';
        abbrEl.textContent = slot.abbr || slot.label || '';
        circle.appendChild(numEl);
        circle.appendChild(abbrEl);

        var stack = document.createElement('div');
        stack.className = 'tt-bpe-stack';
        [1, 2, 3].forEach(function (tierNum) {
            stack.appendChild(buildSlotRow(slot.label, tierNum));
        });

        card.appendChild(circle);
        card.appendChild(stack);
        return card;
    }

    function buildSlotRow(slotLabel, tierNum) {
        var tierKey = tierKeyFromNum(tierNum);
        var ref = (refs[slotLabel] || {})[tierKey] || null;
        var row = document.createElement('div');
        row.className = 'tt-bpe-slot' + (ref ? ' is-filled' : '');
        row.dataset.slotLabel = slotLabel;
        row.dataset.tier = tierKey;
        row.dataset.tierNum = String(tierNum);
        row.setAttribute('role', 'button');
        row.setAttribute('tabindex', cfg.can_manage && !cfg.locked ? '0' : '-1');
        var aria = 'Slot ' + slotLabel + ' tier ' + tierNum;
        if (ref) aria += ' filled with ' + (ref.display_name || '');
        row.setAttribute('aria-label', aria);
        if (ref && ref.display_name) {
            row.title = ref.display_name;
        }

        var mark = document.createElement('span');
        mark.className = 'tt-bpe-tier-mark';
        mark.textContent = String(tierNum);
        row.appendChild(mark);

        var name = document.createElement('span');
        name.className = 'tt-bpe-slot-name';
        if (ref) {
            var shortName = shortNameForRef(ref);
            name.textContent = shortName;
            // Full display name as tooltip for disambiguation.
            if (ref.display_name && ref.display_name !== shortName) {
                name.title = ref.display_name;
            }
        } else {
            name.textContent = '-';
        }
        row.appendChild(name);

        var clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'tt-bpe-slot-x';
        clearBtn.setAttribute('aria-label', 'Clear');
        clearBtn.textContent = 'x';
        clearBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (!cfg.can_manage || cfg.locked) return;
            saveAssignment(slotLabel, tierKey, null);
        });
        row.appendChild(clearBtn);

        if (cfg.can_manage && !cfg.locked) {
            row.addEventListener('click', function (e) {
                if (e.target === clearBtn) return;
                openPicker(row);
            });
            row.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openPicker(row);
                }
            });
            row.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                row.classList.add('is-drag-over');
            });
            row.addEventListener('dragleave', function () {
                row.classList.remove('is-drag-over');
            });
            row.addEventListener('drop', function (e) {
                e.preventDefault();
                row.classList.remove('is-drag-over');
                var rosterId = e.dataTransfer.getData('text/plain');
                if (!rosterId) return;
                var p = findRosterEntry(rosterId);
                if (!p) return;
                saveAssignment(slotLabel, tierKey, refForRosterEntry(p));
            });
        }
        return row;
    }

    // ==================== picker (anchored dropdown) ===================

    var openPickerEl = null;
    var pickerAnchor = null;

    function openPicker(anchorSlot) {
        closePicker();
        pickerAnchor = anchorSlot;
        var slotLabel = anchorSlot.dataset.slotLabel;
        var tierKey = anchorSlot.dataset.tier;
        var tierNum = parseInt(anchorSlot.dataset.tierNum, 10);
        var currentRef = (refs[slotLabel] || {})[tierKey] || null;

        var dd = document.createElement('div');
        dd.className = 'tt-bpe-picker';
        dd.setAttribute('role', 'dialog');
        dd.setAttribute('aria-modal', 'false');

        var head = document.createElement('div');
        head.className = 'tt-bpe-picker-head';
        var headFmt = (cfg.i18n && cfg.i18n.picker_head) || 'Pick a player for tier %d';
        head.textContent = headFmt.replace('%d', String(tierNum));
        dd.appendChild(head);

        var search = document.createElement('input');
        search.type = 'search';
        search.className = 'tt-bpe-picker-search';
        search.placeholder = (cfg.i18n && cfg.i18n.search_placeholder) || 'Search...';
        search.setAttribute('inputmode', 'search');
        search.setAttribute('autocomplete', 'off');
        dd.appendChild(search);

        var results = document.createElement('div');
        results.className = 'tt-bpe-picker-results';
        dd.appendChild(results);

        if (currentRef) {
            var clearRow = document.createElement('button');
            clearRow.type = 'button';
            clearRow.className = 'tt-bpe-picker-clear';
            clearRow.textContent = (cfg.i18n && cfg.i18n.clear_slot) || 'Clear this slot';
            clearRow.addEventListener('click', function () {
                saveAssignment(slotLabel, tierKey, null);
            });
            dd.appendChild(clearRow);
        }

        document.body.appendChild(dd);
        openPickerEl = dd;

        renderPickerResults(results, '', slotLabel, tierKey);
        search.addEventListener('input', function () {
            renderPickerResults(results, search.value, slotLabel, tierKey);
        });

        positionPicker(dd, anchorSlot);

        // Focus the search box on next tick so iOS doesn't blur it.
        setTimeout(function () { search.focus(); }, 0);
    }

    function renderPickerResults(container, query, slotLabel, tierKey) {
        var q = (query || '').trim().toLowerCase();
        var slotRefs = refs[slotLabel] || {};
        var occupantRosterId = rosterIdForRef(slotRefs[tierKey]);
        var matches = roster.filter(function (p) {
            // Skip the row already in this exact slot+tier (it IS this slot).
            if (p.roster_id === occupantRosterId) return false;
            if (!q) return true;
            var hay = ((p.name || '') + ' ' + (p.pos || '')).toLowerCase();
            return hay.indexOf(q) !== -1;
        });

        container.innerHTML = '';
        if (!matches.length) {
            var empty = document.createElement('div');
            empty.className = 'tt-bpe-picker-empty';
            empty.textContent = (cfg.i18n && cfg.i18n.no_matches) || 'No players match.';
            container.appendChild(empty);
            return;
        }

        matches.forEach(function (p) {
            var row = document.createElement('button');
            row.type = 'button';
            row.className = 'tt-bpe-picker-row';
            row.dataset.rosterId = p.roster_id;

            var av = document.createElement('span');
            av.className = 'tt-bpe-av tt-bpe-av-' + (p.kind || 'player');
            av.textContent = initials(p.name || '');
            row.appendChild(av);

            var info = document.createElement('span');
            info.className = 'tt-bpe-picker-info';
            var name = document.createElement('span');
            name.className = 'tt-bpe-picker-row-name';
            name.textContent = p.name || '';
            var sub = document.createElement('span');
            sub.className = 'tt-bpe-picker-row-sub';
            var subBits = [p.pos || '-'];
            if (p.age && p.age > 0) {
                var ageFmt = (cfg.i18n && cfg.i18n.age_fmt) || 'age %d';
                subBits.push(ageFmt.replace('%d', String(p.age)));
            }
            if (p.kind && p.kind !== 'player') {
                subBits.push((cfg.i18n && cfg.i18n['kind_' + p.kind]) || p.kind);
            }
            var placed = placementCount(p);
            if (placed > 0) {
                var placedFmt = (cfg.i18n && cfg.i18n.placed_n) || 'x%d on pitch';
                subBits.push(placedFmt.replace('%d', String(placed)));
            }
            sub.textContent = subBits.join(' . ');
            info.appendChild(name);
            info.appendChild(sub);
            row.appendChild(info);

            row.addEventListener('click', function () {
                saveAssignment(slotLabel, tierKey, refForRosterEntry(p));
            });
            container.appendChild(row);
        });
    }

    function positionPicker(dd, anchor) {
        // Default: anchored to the right of the slot. Flip / clamp if
        // it would overflow the viewport.
        var ar = anchor.getBoundingClientRect();
        // Force layout to measure dd.
        dd.style.left = '0px';
        dd.style.top  = '0px';
        var dr = dd.getBoundingClientRect();
        var left = ar.right + window.scrollX + 6;
        var top  = ar.top + window.scrollY;
        if (left + dr.width > window.scrollX + document.documentElement.clientWidth - 12) {
            left = ar.left + window.scrollX - dr.width - 6;
        }
        if (left < window.scrollX + 8) {
            left = window.scrollX + 8;
        }
        if (top + dr.height > window.scrollY + document.documentElement.clientHeight - 12) {
            top = window.scrollY + document.documentElement.clientHeight - dr.height - 12;
        }
        dd.style.left = Math.max(8, left) + 'px';
        dd.style.top  = Math.max(8, top) + 'px';
    }

    function closePicker() {
        if (openPickerEl && openPickerEl.parentNode) {
            openPickerEl.parentNode.removeChild(openPickerEl);
        }
        openPickerEl = null;
        pickerAnchor = null;
    }

    function wireDocClicks() {
        document.addEventListener('click', function (e) {
            if (!openPickerEl) return;
            if (openPickerEl.contains(e.target)) return;
            if (pickerAnchor && pickerAnchor.contains(e.target)) return;
            closePicker();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closePicker();
        });
    }

    // ==================== add-form (3-tab inline) ======================

    function wireAddForm(root) {
        var toggle = root.querySelector('.tt-bpe-add-toggle');
        var form = root.querySelector('.tt-bpe-add-form');
        if (!toggle || !form) return;

        toggle.addEventListener('click', function () {
            var hidden = form.hasAttribute('hidden');
            if (hidden) {
                form.removeAttribute('hidden');
            } else {
                form.setAttribute('hidden', '');
            }
        });

        // Tabs.
        Array.prototype.forEach.call(form.querySelectorAll('.tt-bpe-add-tab'), function (tab) {
            tab.addEventListener('click', function () {
                Array.prototype.forEach.call(form.querySelectorAll('.tt-bpe-add-tab'), function (t) {
                    t.classList.remove('is-active');
                });
                Array.prototype.forEach.call(form.querySelectorAll('.tt-bpe-add-pane'), function (p) {
                    p.classList.remove('is-active');
                });
                tab.classList.add('is-active');
                var pane = form.querySelector('.tt-bpe-add-pane[data-pane="' + tab.dataset.tab + '"]');
                if (pane) pane.classList.add('is-active');
            });
        });

        // Cancel buttons close the form.
        Array.prototype.forEach.call(form.querySelectorAll('.tt-bpe-add-cancel'), function (b) {
            b.addEventListener('click', function () {
                form.setAttribute('hidden', '');
            });
        });

        // Other-team flow.
        var ctTeam = form.querySelector('.tt-bpe-ct-team');
        var ctPlayer = form.querySelector('.tt-bpe-ct-player');
        if (ctTeam && ctPlayer) {
            // Populate team dropdown from cfg.other_teams.
            (cfg.other_teams || []).forEach(function (t) {
                var opt = document.createElement('option');
                opt.value = String(t.id);
                opt.textContent = t.name;
                ctTeam.appendChild(opt);
            });
            ctTeam.addEventListener('change', function () {
                while (ctPlayer.options.length > 1) ctPlayer.remove(1);
                var sel = ctTeam.value;
                if (!sel) return;
                var team = (cfg.other_teams || []).filter(function (t) {
                    return String(t.id) === sel;
                })[0];
                if (!team) return;
                (team.players || []).forEach(function (p) {
                    var o = document.createElement('option');
                    o.value = String(p.id);
                    o.textContent = p.name + (p.pos ? ' (' + p.pos + ')' : '');
                    o.dataset.pos = p.pos || '';
                    o.dataset.age = String(p.age || 0);
                    ctPlayer.appendChild(o);
                });
            });
        }
        var ctAdd = form.querySelector('.tt-bpe-ct-add');
        if (ctAdd) {
            ctAdd.addEventListener('click', function () {
                var teamId = ctTeam && ctTeam.value;
                var playerId = ctPlayer && ctPlayer.value;
                if (!teamId || !playerId) {
                    alert((cfg.i18n && cfg.i18n.pick_team_and_player) || 'Pick a team and a player.');
                    return;
                }
                var team = (cfg.other_teams || []).filter(function (t) {
                    return String(t.id) === teamId;
                })[0];
                if (!team) return;
                var src = (team.players || []).filter(function (p) {
                    return String(p.id) === playerId;
                })[0];
                if (!src) return;
                var rosterId = 'p:' + src.id;
                if (findRosterEntry(rosterId)) {
                    alert((cfg.i18n && cfg.i18n.already_in_roster) || 'That player is already on the roster.');
                    return;
                }
                roster.push({
                    roster_id: rosterId,
                    kind: 'crossteam',
                    player_id: parseInt(src.id, 10),
                    name: src.name,
                    pos: src.pos || '',
                    age: src.age || 0,
                    team_id: team.id,
                    team_name: team.name
                });
                renderRoster();
                form.setAttribute('hidden', '');
            });
        }

        // Guest tab.
        var guestAdd = form.querySelector('.tt-bpe-guest-add');
        if (guestAdd) {
            guestAdd.addEventListener('click', function () {
                var nameEl = form.querySelector('.tt-bpe-guest-name');
                var posEl  = form.querySelector('.tt-bpe-guest-pos');
                var name = (nameEl && nameEl.value || '').trim();
                if (!name) {
                    alert((cfg.i18n && cfg.i18n.name_required) || 'Name is required.');
                    return;
                }
                roster.push({
                    roster_id: 'g:' + (nextSessionId++),
                    kind: 'guest',
                    name: name,
                    pos: (posEl && posEl.value || '').trim() || '',
                    age: 0
                });
                if (nameEl) nameEl.value = '';
                if (posEl) posEl.value = '';
                renderRoster();
                form.setAttribute('hidden', '');
            });
        }

        // Custom tab.
        var customAdd = form.querySelector('.tt-bpe-custom-add');
        if (customAdd) {
            customAdd.addEventListener('click', function () {
                var nameEl = form.querySelector('.tt-bpe-custom-name');
                var name = (nameEl && nameEl.value || '').trim();
                if (!name) {
                    alert((cfg.i18n && cfg.i18n.label_required) || 'Custom label is required.');
                    return;
                }
                roster.push({
                    roster_id: 'c:' + (nextSessionId++),
                    kind: 'custom',
                    name: name,
                    pos: '',
                    age: 0
                });
                if (nameEl) nameEl.value = '';
                renderRoster();
                form.setAttribute('hidden', '');
            });
        }
    }

    // ==================== formation toolbar ============================

    function wireToolbar(root) {
        var sel = root.querySelector('.tt-bpe-formation-select');
        if (sel) {
            sel.addEventListener('change', function () {
                var newId = parseInt(sel.value, 10);
                if (!newId || newId === currentFormationId) return;
                if (!cfg.can_manage || cfg.locked) {
                    sel.value = String(currentFormationId);
                    return;
                }
                saveHint((cfg.i18n && cfg.i18n.saving) || 'Saving...');
                fetch(cfg.rest_root + '/blueprints/' + cfg.blueprint_id, {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': cfg.nonce
                    },
                    body: JSON.stringify({ formation_template_id: newId })
                })
                .then(function (r) {
                    if (!r.ok) throw new Error('save_failed');
                    return r.json();
                })
                .then(function () {
                    // Reload — the server-side rendering of the slot
                    // list (and the chemistry headline that depends
                    // on the new slot set) is the source of truth.
                    window.location.reload();
                })
                .catch(function () {
                    alert((cfg.i18n && cfg.i18n.save_failed) || 'Could not save the change. Try again.');
                    sel.value = String(currentFormationId);
                    saveHint('');
                });
            });
        }

        var clearBtn = root.querySelector('.tt-bpe-clear-all');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (!cfg.can_manage || cfg.locked) return;
                var msg = (cfg.i18n && cfg.i18n.confirm_clear_all)
                    || 'Clear every slot on this blueprint? This cannot be undone.';
                if (!window.confirm(msg)) return;
                saveHint((cfg.i18n && cfg.i18n.saving) || 'Saving...');
                fetch(cfg.rest_root + '/blueprints/' + cfg.blueprint_id + '/assignments', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': cfg.nonce
                    },
                    body: JSON.stringify({ assignments: {} })
                })
                .then(function (r) {
                    if (!r.ok) throw new Error('save_failed');
                    window.location.reload();
                })
                .catch(function () {
                    alert((cfg.i18n && cfg.i18n.save_failed) || 'Could not save the change. Try again.');
                    saveHint('');
                });
            });
        }
    }

    // ==================== save / save-as / hide-chem toolbar ===========

    function wireSaveToolbar() {
        var toolbar = document.querySelector('.tt-bp-editor-toolbar');
        if (!toolbar) return;
        var listUrl = toolbar.getAttribute('data-list-url') || (cfg.list_url || '');

        var saveBtn = toolbar.querySelector('.tt-bp-save-done');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                // Auto-save fires on every placement; Save is the
                // "done editing, take me back to the list" cue.
                var url = listUrl + (listUrl.indexOf('?') === -1 ? '?' : '&') + 'tt_saved=1';
                window.location.href = url;
            });
        }

        var saveAsBtn = toolbar.querySelector('.tt-bp-save-as');
        if (saveAsBtn) {
            saveAsBtn.addEventListener('click', function () {
                var promptMsg = (cfg.i18n && cfg.i18n.save_as_prompt) || 'Name the new blueprint:';
                var defName = (cfg.i18n && cfg.i18n.save_as_default) || 'Copy of blueprint';
                var name = window.prompt(promptMsg, defName);
                if (name === null) return;
                name = (name || '').trim();
                if (name === '') return;
                saveAsBtn.disabled = true;
                fetch(cfg.rest_root + '/blueprints/' + cfg.blueprint_id + '/clone', {
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
                    var url = window.location.pathname + window.location.search;
                    url = url.replace(/([?&])id=\d+/, '$1id=' + newId);
                    if (url.indexOf('id=') === -1) {
                        url += (url.indexOf('?') === -1 ? '?' : '&') + 'id=' + newId;
                    }
                    window.location.href = url;
                })
                .catch(function () {
                    saveAsBtn.disabled = false;
                    alert((cfg.i18n && (cfg.i18n.save_as_failed || cfg.i18n.save_failed)) || 'Could not duplicate.');
                });
            });
        }
    }

    function wireHideChemistryToggle() {
        var btn = document.querySelector('.tt-bp-hide-chem-toggle');
        if (!btn) return;
        var key = 'tt_bp_hide_chem_' + cfg.blueprint_id;
        var initial = false;
        try { initial = sessionStorage.getItem(key) === '1'; } catch (e) { /* ignore */ }
        applyHide(initial);

        btn.addEventListener('click', function () {
            var next = !document.body.classList.contains('tt-bp-chem-hidden');
            applyHide(next);
            try { sessionStorage.setItem(key, next ? '1' : '0'); } catch (e) { /* ignore */ }
        });

        function applyHide(hidden) {
            document.body.classList.toggle('tt-bp-chem-hidden', hidden);
            btn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
            btn.textContent = hidden
                ? ((cfg.i18n && cfg.i18n.show_chem_label) || 'Show chemistry')
                : ((cfg.i18n && cfg.i18n.hide_chem_label) || 'Hide chemistry');
        }
    }

    function wireStatusButtons() {
        var buttons = document.querySelectorAll('.tt-bp-status-btn');
        Array.prototype.forEach.call(buttons, function (b) {
            b.addEventListener('click', function () {
                var holder = b.closest('.tt-bp-status-actions');
                if (!holder) return;
                var bpId = parseInt(holder.getAttribute('data-blueprint-id'), 10);
                var target = b.getAttribute('data-target-status');
                if (!bpId || !target) return;
                b.disabled = true;
                fetch(cfg.rest_root + '/blueprints/' + bpId + '/status', {
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
                    alert((cfg.i18n && cfg.i18n.save_failed) || 'Could not save the change. Try again.');
                    b.disabled = false;
                });
            });
        });
    }

    // ==================== persistence ==================================

    function saveAssignment(slotLabel, tierKey, ref) {
        if (!cfg.can_manage || cfg.locked) return;
        closePicker();
        saveHint((cfg.i18n && cfg.i18n.saving) || 'Saving...');
        var body = { slot_label: slotLabel, tier: tierKey };
        body.ref = ref;
        fetch(cfg.rest_root + '/blueprints/' + cfg.blueprint_id + '/assignment', {
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
        .then(function () {
            // Reload to refresh chemistry + occupant names from the
            // server. Cheaper than re-implementing chemistry locally.
            window.location.reload();
        })
        .catch(function () {
            alert((cfg.i18n && cfg.i18n.save_failed) || 'Could not save the change. Try again.');
            saveHint('');
        });
    }

    function saveHint(text) {
        var el = document.querySelector('[data-tt-bpe-savehint]');
        if (!el) return;
        el.textContent = text || '';
    }

    // ==================== ref / roster utilities =======================

    function refForRosterEntry(p) {
        if (!p) return null;
        if (p.kind === 'player' || p.kind === 'crossteam') {
            return { kind: 'player', player_id: parseInt(p.player_id, 10) };
        }
        if (p.kind === 'guest') {
            return { kind: 'guest', name: p.name, position: p.pos || null };
        }
        if (p.kind === 'custom') {
            return { kind: 'custom', label: p.name };
        }
        return null;
    }

    function rosterIdForRef(ref) {
        if (!ref) return null;
        if (ref.kind === 'player') return 'p:' + (parseInt(ref.player_id, 10) || 0);
        if (ref.kind === 'guest')  return 'g-name:' + (ref.name || ref.display_name || '');
        if (ref.kind === 'custom') return 'c-name:' + (ref.label || ref.display_name || '');
        return null;
    }

    // Returns the short display name for a slot occupant. First name
    // only by default ("Lucas", "Daan"). When two or more active-roster
    // entries share the same first name (case-insensitive), the
    // colliding entries get a "First L." suffix to disambiguate
    // ("Bram H.", "Bram J."). Falls back to display_name when no
    // first name can be extracted (custom labels, single-token names).
    function shortNameForRef(ref) {
        if (!ref) return '';
        var full = ref.display_name || '';
        var parts = String(full).trim().split(/\s+/).filter(Boolean);
        if (parts.length === 0) return '';
        if (parts.length === 1) return parts[0]; // single token — show as-is
        var first = parts[0];
        var firstLc = first.toLowerCase();
        // Count first-name collisions across the active roster.
        var collisions = 0;
        for (var i = 0; i < roster.length; i++) {
            var entryName = String(roster[i].name || '').trim();
            if (!entryName) continue;
            var entryFirst = entryName.split(/\s+/)[0] || '';
            if (entryFirst.toLowerCase() === firstLc) collisions++;
        }
        if (collisions <= 1) return first;
        // Use the LAST token's initial as the surname hint — handles
        // multi-word surnames ("van Dijk" -> "V"). Add a period.
        var last = parts[parts.length - 1];
        var initial = last.charAt(0).toUpperCase();
        return first + ' ' + initial + '.';
    }

    function findRosterEntry(rosterId) {
        if (!rosterId) return null;
        for (var i = 0; i < roster.length; i++) {
            if (roster[i].roster_id === rosterId) return roster[i];
        }
        return null;
    }

    function seedSessionOnlyRosterFromRefs() {
        // Walk `refs` and add any guest / custom / cross-team ref to
        // the roster (deduped by display key) so a returning user
        // sees their previously-placed session items in the sidebar.
        var seenP = {};
        roster.forEach(function (p) { seenP[p.roster_id] = true; });

        Object.keys(refs).forEach(function (slotLabel) {
            var tiers = refs[slotLabel] || {};
            Object.keys(tiers).forEach(function (tier) {
                var ref = tiers[tier];
                if (!ref) return;
                if (ref.kind === 'player') {
                    // Cross-team: a player ref whose team_id != cfg.team_id.
                    if (ref.team_id && parseInt(ref.team_id, 10) !== parseInt(cfg.team_id, 10)) {
                        var rid = 'p:' + parseInt(ref.player_id, 10);
                        if (!seenP[rid]) {
                            roster.push({
                                roster_id: rid,
                                kind: 'crossteam',
                                player_id: parseInt(ref.player_id, 10),
                                name: ref.display_name || '',
                                pos: '',
                                age: 0,
                                team_id: ref.team_id,
                                team_name: ref.team_name || ''
                            });
                            seenP[rid] = true;
                        }
                    }
                } else if (ref.kind === 'guest') {
                    var gid = 'g-name:' + (ref.name || ref.display_name || '');
                    if (!seenP[gid]) {
                        roster.push({
                            roster_id: gid,
                            kind: 'guest',
                            name: ref.name || ref.display_name || '',
                            pos: ref.position || '',
                            age: 0
                        });
                        seenP[gid] = true;
                    }
                } else if (ref.kind === 'custom') {
                    var cid = 'c-name:' + (ref.label || ref.display_name || '');
                    if (!seenP[cid]) {
                        roster.push({
                            roster_id: cid,
                            kind: 'custom',
                            name: ref.label || ref.display_name || '',
                            pos: '',
                            age: 0
                        });
                        seenP[cid] = true;
                    }
                }
            });
        });
    }

    // ==================== slot / formation utilities ===================

    function activeSlots() {
        var tpl = currentTemplate();
        return (tpl && tpl.slots) ? tpl.slots : (cfg.formation && cfg.formation.slots) || [];
    }

    function activeSlotLabels() {
        return activeSlots().map(function (s) { return s.label; });
    }

    function currentTemplate() {
        var list = cfg.formation_templates || [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].id === currentFormationId) return list[i];
        }
        return null;
    }

    // ==================== misc utils ===================================

    function tierKeyFromNum(n) {
        if (n === 1) return 'primary';
        if (n === 2) return 'secondary';
        if (n === 3) return 'tertiary';
        return 'primary';
    }

    function initials(name) {
        var s = String(name || '').trim();
        if (!s) return '?';
        var parts = s.split(/\s+/);
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    }

    function deepClone(o) {
        try { return JSON.parse(JSON.stringify(o)); } catch (e) { return {}; }
    }

})();
