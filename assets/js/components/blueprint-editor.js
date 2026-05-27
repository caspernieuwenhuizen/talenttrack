/**
 * TalentTrack — Blueprint editor (#953).
 *
 * Rebuilt depth-chart editor matching the in-tree prototype at
 * `.local-mockups/blueprint-editor/index.html`. Each pitch position
 * carries a numbered circle plus a three-row stack underneath
 * (primary / secondary / tertiary). Players can sit in any number of
 * slots and tiers; the roster sidebar shows an `xN` placement badge.
 *
 * The roster sidebar augments with cross-team / guest / custom entries
 * via the "+ Add" inline form. Augmentations are session-only — they
 * only persist on placement (the assignment row carries the ref).
 *
 * Drag-drop, formation switch and the dropdown picker are wired here.
 * The chemistry recompute happens server-side; this file reloads the
 * page after a save so the authoritative pitch + chemistry headline
 * come back from PHP.
 */
(function () {
    'use strict';

    if (typeof window.TT_BLUEPRINT_EDITOR === 'undefined') return;
    var cfg = window.TT_BLUEPRINT_EDITOR;

    // -------------------- state ---------------------------------------
    // Session-augmented roster. The PHP-rendered team roster is the
    // seed; cross-team / guest / custom entries the user adds live here
    // until they are placed in a slot (at which point the placement
    // persists via the assignments table).
    var roster = (cfg.roster || []).slice();

    // assignments: slot_label -> { 1|2|3: ref|null }
    // tiers are stored as numeric keys 1/2/3 in memory and translated
    // to 'primary'/'secondary'/'tertiary' on the wire.
    var assignments = {};

    var currentFormation = cfg.formation || {};
    var blueprintId = cfg.blueprint_id | 0;
    var locked = !!cfg.locked;
    var canManage = !!cfg.can_manage;

    var TIER_NAME = { 1: 'primary', 2: 'secondary', 3: 'tertiary' };
    var TIER_NUM  = { 'primary': 1, 'secondary': 2, 'tertiary': 3 };

    // -------------------- bootstrap -----------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        var editor = document.querySelector('.tt-bpe-editor');
        if (!editor) return;
        ingestAssignments(cfg.assignment_refs || {});
        renderRoster();
        renderPitch();
        wireToolbar();
        wireAddForm();
        wireDocumentClose();
    });

    // -------------------- model helpers -------------------------------

    function ingestAssignments(refs) {
        Object.keys(refs || {}).forEach(function (slot) {
            assignments[slot] = { 1: null, 2: null, 3: null };
            Object.keys(refs[slot] || {}).forEach(function (tier) {
                var n = TIER_NUM[tier] || 0;
                if (!n) return;
                assignments[slot][n] = refs[slot][tier];
                // Augment roster with player refs we don't already have
                // (cross-team picks already in storage land here too).
                addRefToRosterIfMissing(refs[slot][tier]);
            });
        });
        // Ensure every formation slot has an entry.
        (currentFormation.slots || []).forEach(function (s) {
            if (!assignments[s.label]) assignments[s.label] = { 1: null, 2: null, 3: null };
        });
    }

    function addRefToRosterIfMissing(ref) {
        if (!ref) return;
        var id = refRosterId(ref);
        if (!id) return;
        for (var i = 0; i < roster.length; i++) {
            if (roster[i].roster_id === id) return;
        }
        roster.push(refToRosterRow(ref));
    }

    // Build a stable, unique roster row key for any ref.
    function refRosterId(ref) {
        if (!ref) return '';
        if (ref.kind === 'player') return 'p:' + ref.player_id;
        if (ref.kind === 'guest')  return 'g:' + (ref.name || '');
        if (ref.kind === 'custom') return 'c:' + (ref.label || '');
        return '';
    }

    function refToRosterRow(ref) {
        if (ref.kind === 'player') {
            return {
                roster_id: 'p:' + ref.player_id,
                kind: 'player',
                player_id: ref.player_id,
                name: ref.display_name || ('#' + ref.player_id),
                team_id: ref.team_id || null,
                team_name: ref.team_name || '',
                is_crossteam: cfg.team_id && ref.team_id && cfg.team_id !== ref.team_id
            };
        }
        if (ref.kind === 'guest') {
            return {
                roster_id: 'g:' + ref.name,
                kind: 'guest',
                name: ref.display_name || ref.name || '',
                position: ref.position || ''
            };
        }
        return {
            roster_id: 'c:' + ref.label,
            kind: 'custom',
            name: ref.display_name || ref.label || ''
        };
    }

    function refForRosterRow(row) {
        if (!row) return null;
        if (row.kind === 'player') {
            return { kind: 'player', player_id: row.player_id };
        }
        if (row.kind === 'guest') {
            return { kind: 'guest', name: row.name, position: row.position || null };
        }
        return { kind: 'custom', label: row.name };
    }

    function placementCount(rosterId) {
        var n = 0;
        (currentFormation.slots || []).forEach(function (s) {
            var cells = assignments[s.label];
            if (!cells) return;
            [1, 2, 3].forEach(function (t) {
                if (cells[t] && refRosterId(cells[t]) === rosterId) n++;
            });
        });
        return n;
    }

    // -------------------- rendering -----------------------------------

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function initials(name) {
        return (name || '').split(/\s+/).map(function (w) { return w[0] || ''; }).slice(0, 2).join('').toUpperCase();
    }

    function rowClass(row) {
        if (row.kind === 'player')   return row.is_crossteam ? 'tt-bpe-av-crossteam' : 'tt-bpe-av-team';
        if (row.kind === 'guest')    return 'tt-bpe-av-guest';
        return 'tt-bpe-av-custom';
    }

    function renderRoster() {
        var ul = document.querySelector('.tt-bpe-roster-list');
        if (!ul) return;
        ul.innerHTML = '';
        if (!roster.length) {
            ul.innerHTML = '<li class="tt-bpe-roster-empty">' + escapeHtml(cfg.i18n.roster_empty) + '</li>';
            return;
        }
        roster.forEach(function (row) {
            var count = placementCount(row.roster_id);
            var li = document.createElement('li');
            li.className = 'tt-bpe-roster-row';
            li.setAttribute('data-roster-id', row.roster_id);
            li.draggable = canManage && !locked;
            var meta = '';
            if (row.kind === 'player') {
                meta = (row.team_name || '') + (row.is_crossteam ? ' · ' + cfg.i18n.kind_crossteam : '');
            } else if (row.kind === 'guest') {
                meta = (row.position ? row.position + ' · ' : '') + cfg.i18n.kind_guest;
            } else {
                meta = cfg.i18n.kind_custom;
            }
            li.innerHTML =
                '<span class="tt-bpe-av ' + rowClass(row) + '">' + escapeHtml(initials(row.name)) + '</span>' +
                '<span class="tt-bpe-who">' +
                    '<span class="tt-bpe-who-name">' + escapeHtml(row.name) +
                        '<span class="tt-bpe-pick-count"' + (count === 0 ? ' hidden' : '') + '>×' + count + '</span>' +
                    '</span>' +
                    '<span class="tt-bpe-who-meta">' + escapeHtml(meta) + '</span>' +
                '</span>';
            ul.appendChild(li);
        });
        wireRosterDrag();
    }

    function renderPitch() {
        var pitch = document.querySelector('.tt-bpe-pitch');
        if (!pitch) return;
        // Drop any pre-existing position cards.
        Array.prototype.forEach.call(pitch.querySelectorAll('.tt-bpe-pos'), function (el) { el.remove(); });

        var slots = currentFormation.slots || [];
        slots.forEach(function (slot) {
            var card = document.createElement('div');
            card.className = 'tt-bpe-pos';
            card.style.left = (slot.x * 100) + '%';
            card.style.top  = (slot.y * 100) + '%';
            card.setAttribute('data-slot-label', slot.label);
            card.innerHTML =
                '<div class="tt-bpe-circle" aria-hidden="true">' +
                    (slot.num ? '<span class="tt-bpe-circle-num">' + escapeHtml(String(slot.num)) + '</span>' : '') +
                    '<span class="tt-bpe-circle-abbr">' + escapeHtml(slot.label) + '</span>' +
                '</div>' +
                '<div class="tt-bpe-stack" role="group" aria-label="' + escapeHtml(slot.label) + '">' +
                    slotMarkup(slot.label, 1) +
                    slotMarkup(slot.label, 2) +
                    slotMarkup(slot.label, 3) +
                '</div>';
            pitch.appendChild(card);
        });
        wireSlots();
    }

    function slotMarkup(slotLabel, tier) {
        var ref = (assignments[slotLabel] && assignments[slotLabel][tier]) || null;
        var rowName = '';
        if (ref) {
            var row = roster.filter(function (r) { return r.roster_id === refRosterId(ref); })[0];
            rowName = row ? row.name : (ref.display_name || '');
        }
        var filled = ref ? ' is-filled' : '';
        return (
            '<button type="button" class="tt-bpe-slot' + filled + '"' +
                ' data-slot-label="' + escapeHtml(slotLabel) + '"' +
                ' data-tier="' + tier + '"' +
                ' aria-label="' + escapeHtml(slotLabel + ' tier ' + tier) + '">' +
                '<span class="tt-bpe-tier-mark" data-tier="' + tier + '" aria-hidden="true">' + tier + '</span>' +
                '<span class="tt-bpe-slot-name">' + (ref ? escapeHtml(rowName) : '—') + '</span>' +
                (ref ? '<span class="tt-bpe-slot-clear" data-clear="1" aria-label="' + escapeHtml(cfg.i18n.clear_slot) + '">×</span>' : '') +
            '</button>'
        );
    }

    // -------------------- interactions --------------------------------

    function wireSlots() {
        var slots = document.querySelectorAll('.tt-bpe-slot');
        Array.prototype.forEach.call(slots, function (btn) {
            btn.addEventListener('click', function (e) {
                if (!canManage || locked) return;
                if (e.target && e.target.getAttribute('data-clear') === '1') {
                    e.stopPropagation();
                    saveAssignment(btn.getAttribute('data-slot-label'), parseInt(btn.getAttribute('data-tier'), 10), null);
                    return;
                }
                openDropdown(btn);
            });
            btn.addEventListener('dragover', function (e) {
                if (!canManage || locked) return;
                e.preventDefault();
                btn.classList.add('is-drag-over');
            });
            btn.addEventListener('dragleave', function () { btn.classList.remove('is-drag-over'); });
            btn.addEventListener('drop', function (e) {
                if (!canManage || locked) return;
                e.preventDefault();
                btn.classList.remove('is-drag-over');
                var rosterId = e.dataTransfer.getData('text/plain');
                if (!rosterId) return;
                var row = roster.filter(function (r) { return r.roster_id === rosterId; })[0];
                if (!row) return;
                saveAssignment(btn.getAttribute('data-slot-label'), parseInt(btn.getAttribute('data-tier'), 10), refForRosterRow(row));
            });
        });
    }

    function wireRosterDrag() {
        if (!canManage || locked) return;
        var rows = document.querySelectorAll('.tt-bpe-roster-row');
        Array.prototype.forEach.call(rows, function (li) {
            li.addEventListener('dragstart', function (e) {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', li.getAttribute('data-roster-id'));
                li.classList.add('is-dragging');
            });
            li.addEventListener('dragend', function () { li.classList.remove('is-dragging'); });
        });
    }

    // -------------------- dropdown picker -----------------------------

    var currentDropdown = null;
    var currentAnchor = null;

    function openDropdown(anchor) {
        closeDropdown();
        currentAnchor = anchor;
        var slotLabel = anchor.getAttribute('data-slot-label');
        var tier = parseInt(anchor.getAttribute('data-tier'), 10);

        var dd = document.createElement('div');
        dd.className = 'tt-bpe-dropdown';
        dd.setAttribute('role', 'listbox');
        dd.innerHTML =
            '<div class="tt-bpe-dd-head">' +
                escapeHtml(cfg.i18n.picker_head.replace('%s', String(tier))) +
            '</div>' +
            '<input type="text" class="tt-bpe-dd-search" inputmode="search" placeholder="' + escapeHtml(cfg.i18n.search_placeholder) + '" autocomplete="off">' +
            '<div class="tt-bpe-dd-results"></div>' +
            (anchor.classList.contains('is-filled')
                ? '<div class="tt-bpe-dd-clear" role="button" tabindex="0">' + escapeHtml(cfg.i18n.clear_slot) + '</div>'
                : '');
        document.body.appendChild(dd);
        currentDropdown = dd;

        renderDropdownResults(dd, '', slotLabel, tier);
        var search = dd.querySelector('.tt-bpe-dd-search');
        search.addEventListener('input', function () {
            renderDropdownResults(dd, search.value, slotLabel, tier);
        });
        var clear = dd.querySelector('.tt-bpe-dd-clear');
        if (clear) {
            clear.addEventListener('click', function () {
                saveAssignment(slotLabel, tier, null);
            });
        }

        positionDropdown(dd, anchor);
        setTimeout(function () { search.focus(); }, 0);
    }

    function positionDropdown(dd, anchor) {
        // The body picker uses fixed positioning relative to the
        // viewport — viewport-edge clamps keep it on screen on small
        // phones where the slot sits near the right edge of the pitch.
        var rect = anchor.getBoundingClientRect();
        dd.style.position = 'fixed';
        dd.style.visibility = 'hidden';
        dd.style.left = '0px';
        dd.style.top = '0px';
        var ddRect = dd.getBoundingClientRect();
        var left = rect.right + 6;
        var top  = rect.top;
        if (left + ddRect.width > window.innerWidth - 12) {
            left = Math.max(8, rect.left - ddRect.width - 6);
        }
        if (top + ddRect.height > window.innerHeight - 12) {
            top = Math.max(8, window.innerHeight - ddRect.height - 12);
        }
        dd.style.left = left + 'px';
        dd.style.top = top + 'px';
        dd.style.visibility = 'visible';
    }

    function renderDropdownResults(dd, query, slotLabel, tier) {
        var results = dd.querySelector('.tt-bpe-dd-results');
        var q = (query || '').trim().toLowerCase();
        var currentRef = assignments[slotLabel] && assignments[slotLabel][tier];
        var currentId = currentRef ? refRosterId(currentRef) : null;

        var matches = roster.filter(function (row) {
            if (currentId && row.roster_id === currentId) return false;
            if (!q) return true;
            var hay = (row.name + ' ' + (row.team_name || '') + ' ' + (row.position || '')).toLowerCase();
            return hay.indexOf(q) >= 0;
        });
        if (!matches.length) {
            results.innerHTML = '<div class="tt-bpe-dd-empty">' + escapeHtml(cfg.i18n.no_matches) + '</div>';
            return;
        }
        results.innerHTML = matches.map(function (row) {
            var placed = placementCount(row.roster_id);
            var meta = (row.team_name || row.position || cfg.i18n['kind_' + row.kind] || '');
            if (placed > 0) {
                meta += (meta ? ' · ' : '') + cfg.i18n.placed_n.replace('%d', String(placed));
            }
            return '<div class="tt-bpe-dd-row" role="option" tabindex="0" data-roster-id="' + escapeHtml(row.roster_id) + '">' +
                       '<span class="tt-bpe-av tt-bpe-av-sm ' + rowClass(row) + '">' + escapeHtml(initials(row.name)) + '</span>' +
                       '<span class="tt-bpe-dd-who">' +
                           '<span class="tt-bpe-dd-name">' + escapeHtml(row.name) + '</span>' +
                           '<span class="tt-bpe-dd-meta">' + escapeHtml(meta) + '</span>' +
                       '</span>' +
                   '</div>';
        }).join('');
        Array.prototype.forEach.call(results.querySelectorAll('.tt-bpe-dd-row'), function (el) {
            el.addEventListener('click', function () {
                var rosterId = el.getAttribute('data-roster-id');
                var row = roster.filter(function (r) { return r.roster_id === rosterId; })[0];
                if (!row) return;
                saveAssignment(slotLabel, tier, refForRosterRow(row));
            });
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    el.click();
                }
            });
        });
    }

    function closeDropdown() {
        if (currentDropdown && currentDropdown.parentNode) {
            currentDropdown.parentNode.removeChild(currentDropdown);
        }
        currentDropdown = null;
        currentAnchor = null;
    }

    function wireDocumentClose() {
        document.addEventListener('click', function (e) {
            if (!currentDropdown) return;
            if (currentDropdown.contains(e.target)) return;
            if (currentAnchor && currentAnchor.contains(e.target)) return;
            closeDropdown();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeDropdown();
        });
    }

    // -------------------- toolbar + formation switch -------------------

    function wireToolbar() {
        var formationSel = document.querySelector('.tt-bpe-formation-select');
        if (formationSel) {
            formationSel.addEventListener('change', function () {
                var tid = parseInt(formationSel.value, 10);
                if (!tid || tid === currentFormation.template_id) return;
                switchFormation(tid);
            });
        }

        var clearBtn = document.querySelector('.tt-bpe-clear-all');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (!canManage || locked) return;
                if (!window.confirm(cfg.i18n.confirm_clear_all)) return;
                bulkReplace({});
            });
        }
    }

    function switchFormation(templateId) {
        // Persist the formation switch on the blueprint, then refetch
        // the blueprint to read back the new slot list. Assignment rows
        // survive because they key on slot_label; slots that don't
        // appear in the new template still sit in storage and pop back
        // if the user switches back.
        fetch(cfg.rest_root + '/blueprints/' + blueprintId, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify({ formation_template_id: templateId })
        })
        .then(function (r) { if (!r.ok) throw new Error('switch_failed'); return r.json(); })
        .then(function () { window.location.reload(); })
        .catch(function () { window.alert(cfg.i18n.save_failed); });
    }

    function bulkReplace(map) {
        fetch(cfg.rest_root + '/blueprints/' + blueprintId + '/assignments', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify({ assignments: map })
        })
        .then(function (r) { if (!r.ok) throw new Error('bulk_failed'); return r.json(); })
        .then(function () { window.location.reload(); })
        .catch(function () { window.alert(cfg.i18n.save_failed); });
    }

    // -------------------- save (single slot/tier) ----------------------

    function saveAssignment(slotLabel, tier, ref) {
        var tierName = TIER_NAME[tier];
        if (!tierName) return;
        var body = { slot_label: slotLabel, tier: tierName, ref: ref };
        fetch(cfg.rest_root + '/blueprints/' + blueprintId + '/assignment', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify(body)
        })
        .then(function (r) { if (!r.ok) throw new Error('save_failed'); return r.json(); })
        .then(function () {
            // Patch local state for optimistic re-render, then close
            // the dropdown. Skip the full reload — the chemistry
            // headline + lines still come from PHP-rendered markup that
            // doesn't change between drops, so a local re-render keeps
            // the editor responsive.
            if (!assignments[slotLabel]) assignments[slotLabel] = { 1: null, 2: null, 3: null };
            assignments[slotLabel][tier] = ref;
            if (ref) addRefToRosterIfMissing(ref);
            closeDropdown();
            renderRoster();
            renderPitch();
            refreshChemistry();
        })
        .catch(function () { window.alert(cfg.i18n.save_failed); });
    }

    function refreshChemistry() {
        // Pull recomputed chemistry headline + lines from the get
        // endpoint. The full re-fetch keeps PHP authoritative without
        // double-fetching the assignments we already locally know.
        fetch(cfg.rest_root + '/blueprints/' + blueprintId, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce }
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (resp) {
            if (!resp || !resp.data) return;
            var chem = resp.data.blueprint_chemistry || {};
            var valEl = document.querySelector('#tt-bp-chem-value');
            if (valEl) {
                if (chem.team_score === null || chem.team_score === undefined) {
                    valEl.innerHTML = '<span style="color:#8a9099;">— / 100</span>';
                } else {
                    valEl.textContent = cfg.i18n.score_fmt.replace('%d', String(chem.team_score));
                }
            }
            var pairsEl = document.querySelector('#tt-bp-chem-pairs');
            if (pairsEl) {
                var scored = chem.scored_pair_count | 0;
                pairsEl.textContent = (scored === 1 ? cfg.i18n.pairs_one : cfg.i18n.pairs_many).replace('%d', String(scored));
            }
        })
        .catch(function () { /* non-fatal */ });
    }

    // -------------------- add-form (cross-team / guest / custom) ------

    function wireAddForm() {
        var showBtn = document.querySelector('.tt-bpe-add-toggle');
        var form = document.querySelector('.tt-bpe-add-form');
        if (!showBtn || !form) return;

        showBtn.addEventListener('click', function () {
            var open = form.hasAttribute('hidden') ? false : !form.classList.contains('is-collapsed');
            if (open) {
                form.setAttribute('hidden', '');
            } else {
                form.removeAttribute('hidden');
            }
        });

        var tabs = form.querySelectorAll('.tt-bpe-add-tab');
        Array.prototype.forEach.call(tabs, function (tab) {
            tab.addEventListener('click', function () {
                Array.prototype.forEach.call(tabs, function (t) { t.classList.remove('is-active'); });
                tab.classList.add('is-active');
                var key = tab.getAttribute('data-tab');
                Array.prototype.forEach.call(form.querySelectorAll('.tt-bpe-add-pane'), function (p) {
                    p.classList.toggle('is-active', p.getAttribute('data-pane') === key);
                });
            });
        });

        // Cross-team picker — chained team-select / player-select.
        var ctTeam = form.querySelector('.tt-bpe-ct-team');
        var ctPlayer = form.querySelector('.tt-bpe-ct-player');
        // Populate the team select from the localised sibling-team list.
        if (ctTeam) {
            (cfg.other_teams || []).forEach(function (t) {
                var opt = document.createElement('option');
                opt.value = String(t.id);
                opt.textContent = t.name;
                ctTeam.appendChild(opt);
            });
        }
        if (ctTeam) {
            ctTeam.addEventListener('change', function () {
                var teamId = parseInt(ctTeam.value, 10);
                ctPlayer.innerHTML = '<option value="">' + escapeHtml(cfg.i18n.pick_a_player) + '</option>';
                if (!teamId) return;
                var teamObj = (cfg.other_teams || []).filter(function (t) { return t.id === teamId; })[0];
                if (!teamObj) return;
                (teamObj.players || []).forEach(function (p) {
                    var opt = document.createElement('option');
                    opt.value = String(p.id);
                    opt.textContent = p.name;
                    ctPlayer.appendChild(opt);
                });
            });
        }
        var ctAdd = form.querySelector('.tt-bpe-ct-add');
        if (ctAdd) {
            ctAdd.addEventListener('click', function () {
                var teamId = parseInt(ctTeam.value, 10);
                var pid = parseInt(ctPlayer.value, 10);
                if (!teamId || !pid) { window.alert(cfg.i18n.pick_team_and_player); return; }
                var teamObj = (cfg.other_teams || []).filter(function (t) { return t.id === teamId; })[0];
                var pObj = teamObj && (teamObj.players || []).filter(function (p) { return p.id === pid; })[0];
                if (!pObj) return;
                if (roster.filter(function (r) { return r.roster_id === 'p:' + pid; })[0]) {
                    window.alert(cfg.i18n.already_in_roster);
                    return;
                }
                roster.push({
                    roster_id: 'p:' + pid,
                    kind: 'player',
                    player_id: pid,
                    name: pObj.name,
                    team_id: teamId,
                    team_name: teamObj.name,
                    is_crossteam: true
                });
                form.setAttribute('hidden', '');
                renderRoster();
            });
        }

        // Guest add.
        var guestAdd = form.querySelector('.tt-bpe-guest-add');
        if (guestAdd) {
            guestAdd.addEventListener('click', function () {
                var nameEl = form.querySelector('.tt-bpe-guest-name');
                var posEl  = form.querySelector('.tt-bpe-guest-pos');
                var name = (nameEl.value || '').trim();
                if (!name) { window.alert(cfg.i18n.name_required); return; }
                var pos = (posEl.value || '').trim();
                roster.push({
                    roster_id: 'g:' + name,
                    kind: 'guest',
                    name: name,
                    position: pos
                });
                nameEl.value = '';
                posEl.value = '';
                form.setAttribute('hidden', '');
                renderRoster();
            });
        }

        // Custom add.
        var customAdd = form.querySelector('.tt-bpe-custom-add');
        if (customAdd) {
            customAdd.addEventListener('click', function () {
                var nameEl = form.querySelector('.tt-bpe-custom-name');
                var name = (nameEl.value || '').trim();
                if (!name) { window.alert(cfg.i18n.label_required); return; }
                roster.push({
                    roster_id: 'c:' + name,
                    kind: 'custom',
                    name: name
                });
                nameEl.value = '';
                form.setAttribute('hidden', '');
                renderRoster();
            });
        }

        // Cancel buttons close the form.
        Array.prototype.forEach.call(form.querySelectorAll('.tt-bpe-add-cancel'), function (btn) {
            btn.addEventListener('click', function () { form.setAttribute('hidden', ''); });
        });
    }
})();
