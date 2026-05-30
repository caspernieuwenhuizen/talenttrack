/**
 * TalentTrack — Team chemistry interaction layer.
 *
 * v4.13.0 (#1002) — selectors retargeted to the new mockup-port layout
 * (`tt-tc-*` class family). Behaviour is unchanged from the v3.110.174
 * sandbox + v3.110.184 save-as-blueprint model:
 *
 *   - Suggested / Override segmented toggle in the toolbar.
 *   - Tap a pitch slot in override mode -> bottom-sheet picker.
 *   - Override persists in sessionStorage keyed by team id.
 *   - Save-as-blueprint hands the lineup to the blueprint REST endpoints.
 *
 * Additional v4.13.0 plumbing:
 *   - Roster filter input — live filter on the sidebar.
 *   - Formation-picker auto-submit on change (replaces inline onchange).
 *   - Reset button confirmation re-uses the existing `reset_confirm` string.
 *
 * No framework. Pure addEventListener + fetch.
 */
(function () {
    'use strict';

    // Always wire the unguarded pieces — roster filter + formation
    // auto-submit are present even for read-only viewers.
    document.addEventListener('DOMContentLoaded', function () {
        wireRosterFilter();
        wireFormationAutosubmit();
    });

    if (typeof window.TT_TEAM_CHEM === 'undefined') return;

    var cfg = window.TT_TEAM_CHEM;
    var STORAGE_KEY = 'tt_chem_sandbox_' + cfg.team_id;

    document.addEventListener('DOMContentLoaded', function () {
        var sandbox = document.querySelector('.tt-tc-sandbox');
        if (!sandbox) return;

        var state = loadState();
        wireSegmentedToggle(sandbox, state);
        wireReset(sandbox, state);
        wireSave(sandbox, state);
        wireSlotTaps(state);
        wireSheetDismiss();

        if (state.mode === 'on' || hasOverrides(state)) {
            applyMode(sandbox, 'on');
            if (hasOverrides(state)) {
                refreshFromServer(state, sandbox);
            } else {
                renderStatus(sandbox, state);
            }
        }
    });

    // -----------------------------------------------------------------
    // Roster filter — case-insensitive substring match on name + pos.
    // -----------------------------------------------------------------
    function wireRosterFilter() {
        var input = document.getElementById('tt-tc-roster-filter');
        if (!input) return;
        var rows = document.querySelectorAll('.tt-tc-roster-row');
        input.addEventListener('input', function () {
            var q = String(input.value || '').trim().toLowerCase();
            rows.forEach(function (row) {
                if (q === '') {
                    row.classList.remove('is-hidden');
                    return;
                }
                var hay = row.getAttribute('data-search') || '';
                if (hay.indexOf(q) === -1) row.classList.add('is-hidden');
                else row.classList.remove('is-hidden');
            });
        });
    }

    // -----------------------------------------------------------------
    // Formation picker — auto-submits on change. Replaces the v1 inline
    // `onchange="this.form.submit()"` so CSP-strict installs work.
    // -----------------------------------------------------------------
    function wireFormationAutosubmit() {
        document.querySelectorAll('[data-tt-tc-autosubmit]').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var form = sel.closest('form');
                if (form) form.submit();
            });
        });
    }

    // -----------------------------------------------------------------
    // Sandbox state
    // -----------------------------------------------------------------
    function loadState() {
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            if (!raw) return { mode: 'off', overrides: {} };
            var parsed = JSON.parse(raw);
            return {
                mode: parsed.mode === 'on' ? 'on' : 'off',
                overrides: (parsed.overrides && typeof parsed.overrides === 'object') ? parsed.overrides : {}
            };
        } catch (e) {
            return { mode: 'off', overrides: {} };
        }
    }

    function saveState(state) {
        try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) { /* quota — ignore */ }
    }

    function hasOverrides(state) {
        return state.overrides && Object.keys(state.overrides).length > 0;
    }

    function applyMode(sandbox, mode) {
        sandbox.setAttribute('data-mode', mode);
        document.body.classList.toggle('tt-chem-sandbox-on', mode === 'on');

        // Segmented toggle visual state.
        var segOn  = sandbox.querySelector('.tt-tc-sandbox-toggle');
        var segOff = sandbox.querySelector('.tt-tc-sandbox-mode-suggested');
        if (segOn)  { segOn.setAttribute('aria-pressed',  mode === 'on'  ? 'true' : 'false'); segOn.classList.toggle('is-active',  mode === 'on'); }
        if (segOff) { segOff.setAttribute('aria-pressed', mode === 'off' ? 'true' : 'false'); segOff.classList.toggle('is-active', mode === 'off'); }

        // Override banner — toggled by mode.
        var banner = document.querySelector('.tt-tc-override-banner');
        if (banner) {
            if (mode === 'on') banner.removeAttribute('hidden');
            else banner.setAttribute('hidden', '');
        }

        // Make slots focusable while in sandbox mode so the picker is
        // reachable from the keyboard.
        var slots = document.querySelectorAll('.tt-pitch-slot[data-slot-label]');
        slots.forEach(function (s) {
            if (mode === 'on') {
                s.setAttribute('tabindex', '0');
                s.setAttribute('role', 'button');
                s.setAttribute('aria-label', s.getAttribute('data-slot-label'));
            } else {
                s.removeAttribute('tabindex');
                s.removeAttribute('role');
                s.removeAttribute('aria-label');
            }
        });
    }

    function renderStatus(sandbox, state) {
        var reset = sandbox.querySelector('.tt-tc-sandbox-reset');
        var save  = sandbox.querySelector('.tt-tc-sandbox-save');
        var count = Object.keys(state.overrides).length;
        if (count === 0) {
            if (reset) reset.setAttribute('hidden', '');
            if (save)  save.setAttribute('hidden', '');
            return;
        }
        if (reset) reset.removeAttribute('hidden');
        if (save)  save.removeAttribute('hidden');
    }

    function wireSegmentedToggle(sandbox, state) {
        var on  = sandbox.querySelector('.tt-tc-sandbox-toggle');
        var off = sandbox.querySelector('.tt-tc-sandbox-mode-suggested');
        if (on) {
            on.addEventListener('click', function () {
                applyMode(sandbox, 'on');
                state.mode = 'on';
                saveState(state);
                renderStatus(sandbox, state);
            });
        }
        if (off) {
            off.addEventListener('click', function () {
                applyMode(sandbox, 'off');
                state.mode = 'off';
                saveState(state);
                renderStatus(sandbox, state);
            });
        }
    }

    function wireReset(sandbox, state) {
        var btn = sandbox.querySelector('.tt-tc-sandbox-reset');
        if (!btn) return;
        btn.addEventListener('click', function () {
            if (!window.confirm(cfg.i18n.reset_confirm)) return;
            state.overrides = {};
            saveState(state);
            renderStatus(sandbox, state);
            // Reload to restore the server-rendered suggested XI.
            window.location.reload();
        });
    }

    function wireSave(sandbox, state) {
        var btn = sandbox.querySelector('.tt-tc-sandbox-save');
        if (!btn) return;
        btn.addEventListener('click', function () {
            if (!hasOverrides(state)) return;
            openSaveAsDialog(function (result) {
                if (!result) return;
                btn.disabled = true;
                saveAsBlueprint(result.name, result.flavour, state).catch(function () {
                    window.alert(cfg.i18n.save_bp_failed);
                    btn.disabled = false;
                });
            });
        });
    }

    function openSaveAsDialog(onDone) {
        var modal = document.createElement('div');
        modal.className = 'tt-chem-saveas';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        var labelMatch  = cfg.i18n.save_bp_flavour_match || 'Match-day lineup';
        var labelSquad  = cfg.i18n.save_bp_flavour_squad || 'Squad plan (3 tiers per slot)';
        var labelLegend = cfg.i18n.save_bp_flavour_legend || 'Blueprint type';
        var labelName   = cfg.i18n.save_bp_name_label || 'Blueprint name';
        var labelSave   = cfg.i18n.save_bp_save || 'Save blueprint';
        var labelCancel = cfg.i18n.save_bp_cancel || 'Cancel';
        modal.innerHTML =
            '<div class="tt-chem-saveas-backdrop" data-close></div>' +
            '<div class="tt-chem-saveas-sheet" role="document">' +
                '<h3 class="tt-chem-saveas-title">' + escapeHtml(cfg.i18n.save_bp_prompt || 'Save as blueprint') + '</h3>' +
                '<fieldset class="tt-chem-saveas-flavour">' +
                    '<legend>' + escapeHtml(labelLegend) + '</legend>' +
                    '<label><input type="radio" name="tt-saveas-flavour" value="match_day" checked> ' + escapeHtml(labelMatch) + '</label>' +
                    '<label><input type="radio" name="tt-saveas-flavour" value="squad_plan"> ' + escapeHtml(labelSquad) + '</label>' +
                '</fieldset>' +
                '<label class="tt-chem-saveas-name">' +
                    '<span>' + escapeHtml(labelName) + '</span>' +
                    '<input type="text" name="tt-saveas-name" value="' + escapeHtml(cfg.i18n.save_bp_default || '') + '" autocomplete="off" />' +
                '</label>' +
                '<div class="tt-chem-saveas-actions">' +
                    '<button type="button" class="tt-btn tt-btn-secondary" data-cancel>' + escapeHtml(labelCancel) + '</button>' +
                    '<button type="button" class="tt-btn tt-btn-primary" data-save>' + escapeHtml(labelSave) + '</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);
        document.body.classList.add('tt-chem-saveas-open');

        function close(result) {
            modal.parentNode.removeChild(modal);
            document.body.classList.remove('tt-chem-saveas-open');
            onDone(result);
        }
        modal.querySelectorAll('[data-close], [data-cancel]').forEach(function (el) {
            el.addEventListener('click', function () { close(null); });
        });
        modal.querySelector('[data-save]').addEventListener('click', function () {
            var name = modal.querySelector('input[name="tt-saveas-name"]').value.trim()
                    || (cfg.i18n.save_bp_default || 'Blueprint');
            var checked = modal.querySelector('input[name="tt-saveas-flavour"]:checked');
            var flavour = checked ? checked.value : 'match_day';
            close({ name: name, flavour: flavour });
        });
        document.addEventListener('keydown', function once(e) {
            if (e.key === 'Escape') {
                document.removeEventListener('keydown', once);
                close(null);
            }
        });
        var input = modal.querySelector('input[name="tt-saveas-name"]');
        if (input) { input.focus(); input.select(); }
    }

    function saveAsBlueprint(name, flavour, state) {
        return fetch(cfg.rest_root + '/teams/' + cfg.team_id + '/blueprints', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify({
                name: name,
                formation_template_id: cfg.template_id,
                flavour: flavour === 'squad_plan' ? 'squad_plan' : 'match_day'
            })
        }).then(function (r) {
            if (!r.ok) throw new Error('bp_create_failed');
            return r.json();
        }).then(function (resp) {
            var bpId = (resp && resp.data && resp.data.id) || (resp && resp.id);
            if (!bpId) throw new Error('bp_create_failed');
            var lineup = {};
            Object.keys(cfg.suggested).forEach(function (label) {
                var entry = cfg.suggested[label];
                if (entry && entry.player_id) {
                    lineup[label] = { kind: 'player', player_id: entry.player_id };
                }
            });
            Object.keys(state.overrides).forEach(function (label) {
                var pid = state.overrides[label];
                if (pid === null) {
                    delete lineup[label];
                } else {
                    lineup[label] = { kind: 'player', player_id: pid };
                }
            });
            return fetch(cfg.rest_root + '/blueprints/' + bpId + '/assignments', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify({ assignments: lineup })
            }).then(function (r2) {
                if (!r2.ok) throw new Error('bp_assign_failed');
                sessionStorage.removeItem(STORAGE_KEY);
                window.location.href = window.location.pathname
                    + '?tt_view=team-blueprints&team_id=' + cfg.team_id
                    + '&blueprint_id=' + bpId;
            });
        });
    }

    function wireSlotTaps(state) {
        document.addEventListener('click', function (e) {
            if (document.body.classList.contains('tt-chem-sandbox-on') !== true) return;
            var slot = e.target.closest('.tt-pitch-slot[data-slot-label]');
            if (!slot) return;
            e.preventDefault();
            openPicker(slot.getAttribute('data-slot-label'), state);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            if (document.body.classList.contains('tt-chem-sandbox-on') !== true) return;
            var slot = e.target.closest && e.target.closest('.tt-pitch-slot[data-slot-label]');
            if (!slot) return;
            e.preventDefault();
            openPicker(slot.getAttribute('data-slot-label'), state);
        });
    }

    function buildSheet() {
        var sheet = document.createElement('div');
        sheet.className = 'tt-chem-picker';
        sheet.setAttribute('role', 'dialog');
        sheet.setAttribute('aria-modal', 'true');
        sheet.innerHTML =
            '<div class="tt-chem-picker-backdrop" data-close></div>' +
            '<div class="tt-chem-picker-sheet">' +
                '<header class="tt-chem-picker-header">' +
                    '<h3 class="tt-chem-picker-title"></h3>' +
                    '<button type="button" class="tt-btn tt-btn-secondary tt-btn-sm tt-chem-picker-close" data-close></button>' +
                '</header>' +
                '<ul class="tt-chem-picker-list" role="listbox"></ul>' +
            '</div>';
        document.body.appendChild(sheet);
        sheet.querySelectorAll('[data-close]').forEach(function (el) {
            el.addEventListener('click', function () { closePicker(); });
        });
        return sheet;
    }

    function wireSheetDismiss() {
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closePicker();
        });
    }

    var openSheet = null;

    function closePicker() {
        if (openSheet) {
            openSheet.parentNode.removeChild(openSheet);
            openSheet = null;
            document.body.classList.remove('tt-chem-picker-open');
        }
    }

    function openPicker(slotLabel, state) {
        closePicker();
        openSheet = buildSheet();
        document.body.classList.add('tt-chem-picker-open');

        openSheet.querySelector('.tt-chem-picker-title').textContent =
            cfg.i18n.picker_title.replace('%s', slotLabel);
        openSheet.querySelector('.tt-chem-picker-close').textContent = cfg.i18n.picker_close;

        var list = openSheet.querySelector('.tt-chem-picker-list');
        var candidates = buildCandidatesFor(slotLabel, state);
        candidates.forEach(function (c) {
            list.appendChild(renderCandidate(c, slotLabel, state));
        });
        var emptyLi = document.createElement('li');
        emptyLi.className = 'tt-chem-picker-row tt-chem-picker-row--empty';
        emptyLi.setAttribute('role', 'option');
        emptyLi.setAttribute('tabindex', '0');
        emptyLi.innerHTML = '<span class="tt-chem-picker-name">' + escapeHtml(cfg.i18n.picker_empty) + '</span>';
        emptyLi.addEventListener('click', function () { applyOverride(slotLabel, null, state); });
        emptyLi.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); applyOverride(slotLabel, null, state); }
        });
        list.appendChild(emptyLi);

        var first = list.querySelector('.tt-chem-picker-row');
        if (first) first.focus();
    }

    function buildCandidatesFor(slotLabel, state) {
        var byId = {};
        var depthRows = cfg.depth[slotLabel] || [];
        depthRows.forEach(function (r) {
            byId[r.player_id] = { player_id: r.player_id, player_name: r.player_name, score: r.score, has_data: r.has_data };
        });
        var eligibleList = (cfg.eligible && cfg.eligible[slotLabel]) || null;
        var eligibleSet = null;
        if (eligibleList && eligibleList.length) {
            eligibleSet = {};
            eligibleList.forEach(function (id) { eligibleSet[id] = true; });
        }
        cfg.roster.forEach(function (p) {
            if (byId[p.id]) return;
            if (eligibleSet && !eligibleSet[p.id]) return;
            byId[p.id] = { player_id: p.id, player_name: p.name, score: 0, has_data: false };
        });
        var rows = [];
        Object.keys(byId).forEach(function (k) { rows.push(byId[k]); });

        rows.sort(function (a, b) {
            if (a.has_data !== b.has_data) return b.has_data ? 1 : -1;
            return b.score - a.score;
        });

        var slotByPlayer = currentLineupReverse(state);
        rows.forEach(function (r) {
            var cur = slotByPlayer[r.player_id];
            if (cur && cur !== slotLabel) r.currently_in = cur;
        });

        return rows;
    }

    function currentLineupReverse(state) {
        var map = {};
        Object.keys(cfg.suggested).forEach(function (label) {
            var entry = cfg.suggested[label];
            if (entry && entry.player_id) map[entry.player_id] = label;
        });
        Object.keys(state.overrides).forEach(function (label) {
            Object.keys(map).forEach(function (pid) {
                if (map[pid] === label) delete map[pid];
            });
            var pid = state.overrides[label];
            if (pid !== null) map[pid] = label;
        });
        return map;
    }

    function renderCandidate(c, slotLabel, state) {
        var li = document.createElement('li');
        li.className = 'tt-chem-picker-row';
        li.setAttribute('role', 'option');
        li.setAttribute('tabindex', '0');
        var fit = c.has_data
            ? cfg.i18n.fit.replace('%s', c.score.toFixed(2))
            : cfg.i18n.no_fit;
        var inXi = c.currently_in
            ? '<span class="tt-chem-picker-badge">' + escapeHtml(cfg.i18n.in_xi.replace('%s', c.currently_in)) + '</span>'
            : '';
        li.innerHTML =
            '<span class="tt-chem-picker-name">' + escapeHtml(c.player_name) + '</span>' +
            '<span class="tt-chem-picker-meta">' + escapeHtml(fit) + '</span>' +
            inXi;
        li.addEventListener('click', function () { applyOverride(slotLabel, c.player_id, state); });
        li.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); applyOverride(slotLabel, c.player_id, state); }
        });
        return li;
    }

    function applyOverride(slotLabel, playerId, state) {
        var suggested = cfg.suggested[slotLabel];
        if (playerId !== null && suggested && suggested.player_id === playerId
            && !state.overrides.hasOwnProperty(slotLabel)) {
            closePicker();
            return;
        }

        if (playerId === null) {
            state.overrides[slotLabel] = null;
        } else if (suggested && suggested.player_id === playerId) {
            delete state.overrides[slotLabel];
        } else {
            state.overrides[slotLabel] = playerId;
        }
        saveState(state);
        closePicker();
        refreshFromServer(state, document.querySelector('.tt-tc-sandbox'));
    }

    function refreshFromServer(state, sandbox) {
        var body = {
            template_id: cfg.template_id,
            possession: cfg.style.possession,
            counter:    cfg.style.counter,
            press:      cfg.style.press,
            overrides:  state.overrides
        };
        fetch(cfg.rest_root + '/teams/' + cfg.team_id + '/chemistry/preview', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify(body)
        }).then(function (r) {
            if (!r.ok) throw new Error('preview_failed');
            return r.json();
        }).then(function (resp) {
            var payload = resp && resp.data ? resp.data : resp;
            if (!payload) throw new Error('preview_failed');
            patchBoard(payload);
            renderStatus(sandbox, state);
        }).catch(function () {
            window.alert(cfg.i18n.save_failed);
        });
    }

    function patchBoard(payload) {
        if (payload.suggested_xi) {
            cfg.suggested = {};
            Object.keys(payload.suggested_xi).forEach(function (label) {
                var e = payload.suggested_xi[label];
                cfg.suggested[label] = {
                    player_id:   e.player_id || 0,
                    player_name: e.player_name || '',
                    score:       Number(e.score || 0),
                    has_data:    !!e.has_data
                };
            });
        }
        patchSlots(payload.suggested_xi || {});
        patchBreakdown(payload);
        patchLinks((payload.blueprint_chemistry && payload.blueprint_chemistry.links) || []);
        patchLinkHeadline(payload.blueprint_chemistry || {});
    }

    function patchSlots(suggested) {
        var slots = document.querySelectorAll('.tt-pitch-slot[data-slot-label]');
        slots.forEach(function (slot) {
            var label = slot.getAttribute('data-slot-label');
            var entry = suggested[label];
            slot.classList.remove('tt-fit-mid', 'tt-fit-low', 'tt-fit-unknown', 'tt-slot-empty');
            if (!entry || !entry.player_id) {
                slot.classList.add('tt-slot-empty');
                slot.setAttribute('data-player-id', '0');
                slot.innerHTML = '<strong>' + escapeHtml(label) + '</strong><span class="tt-slot-name">—</span>';
                slot.setAttribute('title', '');
                return;
            }
            slot.setAttribute('data-player-id', String(entry.player_id));
            var name = entry.player_name || '';
            var first = name.split(' ')[0] || '';
            var score = Number(entry.score || 0);
            var inner = '<strong>' + escapeHtml(label) + '</strong>';
            if (!entry.has_data) {
                slot.classList.add('tt-fit-unknown');
                inner += first ? '<span class="tt-slot-name" style="font-size:9px;">' + escapeHtml(first) + '</span>' : '';
                inner += '<span class="tt-slot-score">?</span>';
            } else {
                if (score < 3.0) slot.classList.add('tt-fit-low');
                else if (score < 4.0) slot.classList.add('tt-fit-mid');
                inner += '<span class="tt-slot-name" style="font-size:9px; color:#5b6e75;">' + escapeHtml(first) + '</span>';
                inner += '<span class="tt-slot-score">' + score.toFixed(2) + '</span>';
            }
            slot.innerHTML = inner;
            slot.setAttribute('title', name + ' — ' + label);
        });
    }

    function patchBreakdown(payload) {
        var card = document.querySelector('[data-tt-chem-breakdown]');
        if (!card) return;
        var rmax = parseFloat(card.getAttribute('data-rating-max') || '10');
        var heading = card.querySelector('[data-tt-chem-composite-heading]');
        if (heading) {
            if (payload.composite === null || typeof payload.composite === 'undefined') {
                heading.innerHTML = '— <sup>/' + escapeHtml(rmax.toFixed(0)) + '</sup>';
            } else {
                heading.innerHTML = escapeHtml(Number(payload.composite).toFixed(2))
                    + ' <sup>/' + escapeHtml(rmax.toFixed(0)) + '</sup>';
            }
        }
        ['formation_fit', 'style_fit', 'depth_score', 'paired_chemistry'].forEach(function (key) {
            var el = card.querySelector('[data-tt-chem-part="' + key + '"]');
            if (!el) return;
            var v = payload[key];
            if (v === null || typeof v === 'undefined') {
                el.textContent = '—';
            } else {
                el.textContent = Number(v).toFixed(2);
            }
        });
    }

    function patchLinks(links) {
        var byKey = {};
        links.forEach(function (l) {
            var key = (l.a_slot < l.b_slot)
                ? (l.a_slot + '|' + l.b_slot)
                : (l.b_slot + '|' + l.a_slot);
            byKey[key] = l;
        });
        var lines = document.querySelectorAll('.tt-chem-link[data-link-key]');
        lines.forEach(function (line) {
            var key = line.getAttribute('data-link-key');
            var link = byKey[key];
            if (!link) return;
            line.classList.remove('tt-chem-green', 'tt-chem-amber', 'tt-chem-red', 'tt-chem-neutral');
            line.classList.add('tt-chem-' + (link.color || 'neutral'));
            var titleEl = line.querySelector('title');
            if (titleEl) {
                if (link.score === null || typeof link.score === 'undefined') {
                    titleEl.textContent = '';
                } else {
                    var reasons = (link.reasons || []).join(', ');
                    titleEl.textContent = cfg.i18n.link_tip
                        .replace('%1$s', Number(link.score).toFixed(1))
                        .replace('%2$s', reasons || cfg.i18n.no_signals);
                }
            }
        });
    }

    function patchLinkHeadline(bp) {
        var headline = document.querySelector('[data-tt-link-headline]');
        var subtitle = document.querySelector('[data-tt-link-subtitle]');
        if (headline) {
            if (bp.team_score === null || typeof bp.team_score === 'undefined') {
                headline.innerHTML = '— <sup>/100</sup>';
            } else {
                headline.innerHTML = escapeHtml(String(Math.round(bp.team_score))) + '<sup>/100</sup>';
            }
        }
        if (subtitle) {
            var n = bp.scored_pair_count || 0;
            var tpl = n === 1 ? cfg.i18n.pairs_one : cfg.i18n.pairs_many;
            subtitle.textContent = tpl.replace('%d', String(n));
        }
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return c === '&' ? '&amp;' : c === '<' ? '&lt;' : c === '>' ? '&gt;' : c === '"' ? '&quot;' : '&#39;';
        });
    }
})();
