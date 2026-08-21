/**
 * TalentTrack — training plan builder (#2498).
 *
 * The editable half of `?tt_view=training-plan&id=N&mode=build`. PHP
 * renders the chrome and hands over the plan's blocks; everything that
 * changes on an edit — the list, the timeline, the running total — is
 * rendered here, because a page load per duration tap is not a builder.
 *
 * Interaction rules this file exists to honour (CLAUDE.md §2):
 *   - The up/down buttons are the PRIMARY reorder control, not a
 *     degraded fallback. Drag is the enhancement, and only above
 *     1024px where a pointer exists to do it with.
 *   - Every control is a real <button>, so keyboard-only reordering
 *     works without a single extra line.
 *   - Nothing is committed until Save. Reordering is cheap and
 *     reversible until then; a coach experimenting should not be
 *     writing to the database on every tap.
 *
 * Everything user-facing comes from cfg.i18n — no English lives here.
 */
(function () {
    'use strict';

    if (typeof window.TT_TRAINING_BUILDER === 'undefined') return;

    var cfg = window.TT_TRAINING_BUILDER;
    var i18n = cfg.i18n || {};

    var DESKTOP = 1024;

    var state = {
        blocks: (cfg.blocks || []).slice(),
        dirty: false,
        swapIndex: null,
        options: [],
        rosterSize: 0
    };

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-tt-builder]');
        if (!root) return;

        var el = {
            list: root.querySelector('[data-tt-builder-list]'),
            timeline: root.querySelector('[data-tt-builder-timeline]'),
            total: root.querySelector('[data-tt-builder-total]'),
            msg: root.querySelector('[data-tt-builder-msg]'),
            add: root.querySelector('[data-tt-builder-add]'),
            save: root.querySelector('[data-tt-builder-save]'),
            coverage: root.querySelector('[data-tt-builder-coverage]'),
            picker: document.querySelector('[data-tt-builder-picker]'),
            scrim: document.querySelector('[data-tt-builder-scrim]'),
            pickerRows: document.querySelector('[data-tt-builder-picker-rows]'),
            pickerSearch: document.querySelector('[data-tt-builder-picker-search]'),
            pickerClose: document.querySelector('[data-tt-builder-picker-close]')
        };
        if (!el.list) return;

        bind(el);
        bindDrop(el.list);
        render(el);
    });

    // ---- rendering --------------------------------------------------------

    function render(el) {
        renderList(el);
        renderTimeline(el);
        renderTotal(el);
    }

    function renderList(el) {
        el.list.textContent = '';

        if (!state.blocks.length) {
            var empty = document.createElement('li');
            empty.className = 'tt-builder__empty tt-muted';
            empty.textContent = i18n.empty || '';
            el.list.appendChild(empty);
            return;
        }

        state.blocks.forEach(function (block, index) {
            el.list.appendChild(blockNode(block, index));
        });
    }

    function blockNode(block, index) {
        var li = document.createElement('li');
        li.className = 'tt-builder__block tt-builder__block--' + safeType(block.block_type);
        li.setAttribute('data-index', String(index));

        li.appendChild(blockHead(block, index));
        li.appendChild(blockBody(block, index));
        li.appendChild(blockBar(block, index));

        return li;
    }

    function blockHead(block, index) {
        var head = document.createElement('div');
        head.className = 'tt-builder__block-head';

        var type = document.createElement('select');
        type.className = 'tt-builder__block-type';
        type.setAttribute('aria-label', i18n.blockType || '');
        (cfg.blockTypes || []).forEach(function (option) {
            var opt = document.createElement('option');
            opt.value = option.value;
            opt.textContent = (index + 1) + ' · ' + option.label;
            if (option.value === block.block_type) opt.selected = true;
            type.appendChild(opt);
        });
        type.addEventListener('change', function () {
            state.blocks[index].block_type = type.value;
            markDirty();
            rerender();
        });
        head.appendChild(type);

        // Drag is desktop-only enhancement. Below 1024px the handle is not
        // rendered at all rather than rendered-and-inert, so nothing on a
        // phone advertises an interaction that will not happen.
        if (window.matchMedia('(min-width: ' + DESKTOP + 'px)').matches) {
            var handle = document.createElement('span');
            handle.className = 'tt-builder__handle';
            handle.setAttribute('draggable', 'true');
            handle.setAttribute('title', i18n.drag || '');
            handle.setAttribute('aria-hidden', 'true');
            handle.textContent = '⠿';
            attachDrag(handle, index);
            head.appendChild(handle);
        }

        return head;
    }

    function blockBody(block, index) {
        var body = document.createElement('div');
        body.className = 'tt-builder__block-body';

        var name = document.createElement('p');
        name.className = 'tt-builder__block-name';
        name.textContent = block.title_override || block.exercise_name || (i18n.untitled || '');
        if (!block.exercise_id) name.classList.add('tt-muted');
        body.appendChild(name);

        if (block.organisation) {
            var org = document.createElement('p');
            org.className = 'tt-builder__block-desc tt-small tt-muted';
            org.textContent = block.organisation;
            body.appendChild(org);
        }

        if ((block.principle_codes || []).length) {
            var tags = document.createElement('div');
            tags.className = 'tt-builder__tags';
            block.principle_codes.forEach(function (code) {
                var pill = document.createElement('span');
                pill.className = 'tt-pill tt-pill--principle';
                pill.textContent = code;
                tags.appendChild(pill);
            });
            body.appendChild(tags);
        }

        var label = document.createElement('label');
        label.className = 'tt-field tt-builder__points';
        var span = document.createElement('span');
        span.textContent = i18n.coachingPts || '';
        var area = document.createElement('textarea');
        area.rows = 2;
        area.value = block.coaching_points || '';
        area.addEventListener('input', function () {
            state.blocks[index].coaching_points = area.value;
            markDirty();
        });
        label.appendChild(span);
        label.appendChild(area);
        body.appendChild(label);

        return body;
    }

    function blockBar(block, index) {
        var bar = document.createElement('div');
        bar.className = 'tt-builder__block-bar';

        var dur = document.createElement('div');
        dur.className = 'tt-builder__duration';
        dur.appendChild(iconButton('−', i18n.shorter, function () { step(index, -1); }));
        var out = document.createElement('output');
        out.textContent = fmt(i18n.minutes, block.duration_minutes);
        dur.appendChild(out);
        dur.appendChild(iconButton('+', i18n.longer, function () { step(index, 1); }));
        bar.appendChild(dur);

        var move = document.createElement('div');
        move.className = 'tt-builder__move';
        var up = iconButton('↑', i18n.up, function () { moveBlock(index, index - 1); });
        up.disabled = index === 0;
        var down = iconButton('↓', i18n.down, function () { moveBlock(index, index + 1); });
        down.disabled = index === state.blocks.length - 1;
        move.appendChild(up);
        move.appendChild(down);
        bar.appendChild(move);

        var swap = document.createElement('button');
        swap.type = 'button';
        swap.className = 'tt-btn tt-btn-secondary tt-btn-sm';
        swap.textContent = i18n.swap || '';
        swap.addEventListener('click', function () { openPicker(index); });
        bar.appendChild(swap);

        bar.appendChild(iconButton('✕', i18n.remove, function () { removeBlock(index); }));

        return bar;
    }

    function renderTimeline(el) {
        el.timeline.textContent = '';

        state.blocks.forEach(function (block) {
            var minutes = Math.max(0, parseInt(block.duration_minutes, 10) || 0);
            if (!minutes) return;

            var seg = document.createElement('span');
            seg.className = 'tt-builder__seg tt-builder__seg--' + safeType(block.block_type);
            // The one genuinely computed style on this surface: a segment's
            // share of the session is data, not design.
            seg.style.flex = String(minutes);
            seg.textContent = String(minutes);
            el.timeline.appendChild(seg);
        });
    }

    function renderTotal(el) {
        el.total.textContent = fmt(i18n.totalMinutes, totalMinutes());
    }

    function rerender() {
        var root = document.querySelector('[data-tt-builder]');
        if (!root) return;
        render({
            list: root.querySelector('[data-tt-builder-list]'),
            timeline: root.querySelector('[data-tt-builder-timeline]'),
            total: root.querySelector('[data-tt-builder-total]')
        });
    }

    // ---- mutations --------------------------------------------------------

    function step(index, direction) {
        var block = state.blocks[index];
        var next = (parseInt(block.duration_minutes, 10) || 0) + (direction * (cfg.step || 5));
        block.duration_minutes = Math.min(cfg.max || 60, Math.max(cfg.min || 5, next));
        markDirty();
        rerender();
    }

    /**
     * Reorder, and tell the user it happened. A visual reorder is invisible
     * to a screen reader and easy to miss on a phone where the moved block
     * may scroll out of view, so the status line names the new position.
     */
    function moveBlock(from, to) {
        if (to < 0 || to >= state.blocks.length) return;

        var moved = state.blocks.splice(from, 1)[0];
        state.blocks.splice(to, 0, moved);
        markDirty();
        rerender();
        announce(fmt(i18n.movedTo, to + 1));
        focusBlock(to);
    }

    function removeBlock(index) {
        state.blocks.splice(index, 1);
        markDirty();
        rerender();
    }

    function addBlock() {
        state.blocks.push({
            id: 0,
            block_type: 'main',
            exercise_id: null,
            exercise_name: '',
            title_override: '',
            organisation: '',
            coaching_points: '',
            duration_minutes: cfg.step || 5,
            intensity_band: null,
            principle_codes: []
        });
        markDirty();
        rerender();
        focusBlock(state.blocks.length - 1);
    }

    function markDirty() {
        state.dirty = true;
        announce(i18n.unsaved);
    }

    // ---- drag (desktop enhancement) ---------------------------------------

    /**
     * The handle is created before its block is attached to the list, so
     * the drop target is bound lazily on first dragover of the list
     * rather than walking a parent chain that does not exist yet.
     */
    function attachDrag(handle, index) {
        handle.addEventListener('dragstart', function (e) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(index));
        });
    }

    /**
     * One delegated set of listeners on the list, rather than three per
     * block. Rebinding on every render is how drop handlers end up
     * pointing at stale indices.
     */
    function bindDrop(list) {
        list.addEventListener('dragover', function (e) {
            var block = e.target.closest && e.target.closest('.tt-builder__block');
            if (!block) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            block.classList.add('is-drop-target');
        });

        list.addEventListener('dragleave', function (e) {
            var block = e.target.closest && e.target.closest('.tt-builder__block');
            if (block) block.classList.remove('is-drop-target');
        });

        list.addEventListener('drop', function (e) {
            var block = e.target.closest && e.target.closest('.tt-builder__block');
            if (!block) return;
            e.preventDefault();
            block.classList.remove('is-drop-target');

            var from = parseInt(e.dataTransfer.getData('text/plain'), 10);
            var to = parseInt(block.getAttribute('data-index'), 10);
            if (isNaN(from) || isNaN(to) || from === to) return;
            moveBlock(from, to);
        });
    }

    // ---- picker -----------------------------------------------------------

    function openPicker(index) {
        state.swapIndex = index;

        var picker = document.querySelector('[data-tt-builder-picker]');
        var scrim = document.querySelector('[data-tt-builder-scrim]');
        if (!picker || !scrim) return;

        picker.hidden = false;
        scrim.hidden = false;
        document.body.classList.add('tt-builder-picking');

        var search = picker.querySelector('[data-tt-builder-picker-search]');
        if (search) search.focus();

        loadOptions(search ? search.value : '');
    }

    function closePicker() {
        var picker = document.querySelector('[data-tt-builder-picker]');
        var scrim = document.querySelector('[data-tt-builder-scrim]');
        if (picker) picker.hidden = true;
        if (scrim) scrim.hidden = true;
        document.body.classList.remove('tt-builder-picking');

        if (state.swapIndex !== null) focusBlock(state.swapIndex);
        state.swapIndex = null;
    }

    function loadOptions(search) {
        var rows = document.querySelector('[data-tt-builder-picker-rows]');
        if (!rows) return;

        var url = cfg.restBase + '/training/plans/' + cfg.planId + '/exercise-options';
        if (search) url += '?search=' + encodeURIComponent(search);

        request('GET', url).then(function (data) {
            state.options = (data && data.options) || [];
            state.rosterSize = (data && data.roster_size) || 0;
            renderOptions();
        }).catch(function () {
            rows.textContent = '';
            var li = document.createElement('li');
            li.className = 'tt-muted';
            li.textContent = i18n.loadFailed || '';
            rows.appendChild(li);
        });
    }

    function renderOptions() {
        var rows = document.querySelector('[data-tt-builder-picker-rows]');
        if (!rows) return;
        rows.textContent = '';

        if (!state.options.length) {
            var none = document.createElement('li');
            none.className = 'tt-muted';
            none.textContent = i18n.noOptions || '';
            rows.appendChild(none);
            return;
        }

        state.options.forEach(function (option) {
            rows.appendChild(optionNode(option));
        });
    }

    function optionNode(option) {
        var li = document.createElement('li');
        li.className = 'tt-builder__picker-row';

        var body = document.createElement('div');
        body.className = 'tt-builder__picker-body';

        var title = document.createElement('p');
        title.className = 'tt-builder__picker-title';
        title.textContent = option.name;
        body.appendChild(title);

        var meta = document.createElement('p');
        meta.className = 'tt-small tt-muted';
        // The sort key, visible on every row. A ranked list whose ranking
        // the user cannot see is just an arbitrary order they must trust.
        meta.textContent = option.players_served > 0
            ? fmt(i18n.servesPlayers, option.players_served)
            : (i18n.servesNobody || '');
        body.appendChild(meta);

        li.appendChild(body);

        var choose = document.createElement('button');
        choose.type = 'button';
        choose.className = 'tt-btn tt-btn-sm';
        choose.textContent = i18n.choose || '';
        choose.addEventListener('click', function () { chooseOption(option); });
        li.appendChild(choose);

        return li;
    }

    function chooseOption(option) {
        if (state.swapIndex === null) return;

        var block = state.blocks[state.swapIndex];
        block.exercise_id = option.id;
        block.exercise_name = option.name;
        block.title_override = '';
        block.intensity_band = option.intensity_band;
        block.principle_codes = [];
        if (option.duration_minutes) block.duration_minutes = option.duration_minutes;

        markDirty();
        rerender();
        closePicker();
    }

    // ---- saving -----------------------------------------------------------

    function save(el) {
        el.save.disabled = true;
        announce(i18n.saving);

        var payload = state.blocks.map(function (block, index) {
            return {
                order_index: index,
                block_type: block.block_type,
                exercise_id: block.exercise_id,
                title_override: block.title_override,
                organisation: block.organisation,
                coaching_points: block.coaching_points,
                duration_minutes: block.duration_minutes,
                intensity_band: block.intensity_band
            };
        });

        request('PUT', cfg.restBase + '/training/plans/' + cfg.planId + '/blocks', { blocks: payload })
            .then(function (data) {
                state.dirty = false;
                if (data && data.blocks) {
                    state.blocks = data.blocks.map(hydrate);
                    render(el);
                }
                announce(i18n.saved);
                refreshCoverage();
            })
            .catch(function () {
                announce(i18n.saveFailed);
            })
            .then(function () {
                el.save.disabled = false;
            });
    }

    /** Keep the client-side extras the REST shape does not carry back. */
    function hydrate(block) {
        return {
            id: block.id,
            block_type: block.block_type,
            exercise_id: block.exercise_id,
            exercise_name: block.exercise_name || '',
            title_override: block.title_override || '',
            organisation: block.organisation || '',
            coaching_points: block.coaching_points || '',
            duration_minutes: block.duration_minutes,
            intensity_band: block.intensity_band,
            principle_codes: block.principle_codes || []
        };
    }

    /**
     * Who the plan serves, re-read after a save. This is the number the
     * whole module exists to move, so it must not go stale behind an edit.
     */
    function refreshCoverage() {
        var host = document.querySelector('[data-tt-builder-coverage-body]');
        if (!host) return;

        request('GET', cfg.restBase + '/training/plans/' + cfg.planId + '/coverage')
            .then(function (data) {
                var coverage = (data && data.coverage) || {};
                renderCoverage(host, coverage.players || []);
            })
            .catch(function () { /* the panel keeps its last good answer */ });
    }

    function renderCoverage(host, players) {
        host.textContent = '';

        var missed = [];
        players.forEach(function (player) {
            if (!player.covered) { missed.push(player); return; }

            var row = document.createElement('div');
            row.className = 'tt-builder__goalhit';

            var avatar = document.createElement('span');
            avatar.className = 'tt-builder__avatar';
            avatar.setAttribute('aria-hidden', 'true');
            avatar.textContent = initials(player.name);
            row.appendChild(avatar);

            var name = document.createElement('b');
            name.textContent = player.name;
            row.appendChild(name);

            host.appendChild(row);
        });

        if (missed.length) {
            var note = document.createElement('p');
            note.className = 'tt-builder__missed tt-small';
            note.textContent = missed.map(function (p) { return p.name; }).join(', ');
            host.appendChild(note);
        }
    }

    // ---- plumbing ---------------------------------------------------------

    /**
     * Both copy actions duplicate the SAVED plan, so an unsaved edit
     * would silently not travel. Rather than copy the wrong thing or
     * save on the coach's behalf, say so and let them decide.
     */
    function duplicate(asTemplate) {
        if (state.dirty) { announce(i18n.saveFirst); return; }

        var ask = asTemplate ? i18n.templateAsk : i18n.duplicateAsk;
        if (ask && !window.confirm(ask)) return;

        request('POST', cfg.restBase + '/training/plans/' + cfg.planId + '/duplicate', {
            as_template: asTemplate ? 1 : 0
        }).then(function (data) {
            var id = data && data.plan && data.plan.id;
            if (id) window.location.href = cfg.planUrl.replace(/id=\d+/, 'id=' + id);
        }).catch(function () {
            announce(i18n.copyFailed);
        });
    }

    function bind(el) {
        if (el.add) el.add.addEventListener('click', addBlock);
        if (el.save) el.save.addEventListener('click', function () { save(el); });

        var template = document.querySelector('[data-tt-builder-template]');
        if (template) template.addEventListener('click', function () { duplicate(true); });

        var copy = document.querySelector('[data-tt-builder-duplicate]');
        if (copy) copy.addEventListener('click', function () { duplicate(false); });
        if (el.pickerClose) el.pickerClose.addEventListener('click', closePicker);
        if (el.scrim) el.scrim.addEventListener('click', closePicker);

        if (el.pickerSearch) {
            var timer = null;
            el.pickerSearch.addEventListener('input', function () {
                if (timer) clearTimeout(timer);
                timer = setTimeout(function () { loadOptions(el.pickerSearch.value); }, 250);
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closePicker();
        });

        // A half-built session is worth one browser prompt.
        window.addEventListener('beforeunload', function (e) {
            if (!state.dirty) return;
            e.preventDefault();
            e.returnValue = '';
        });
    }

    function request(method, url, body) {
        var init = {
            method: method,
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce }
        };
        if (body) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(body);
        }

        return fetch(url, init).then(function (response) {
            if (!response.ok) throw new Error(String(response.status));
            return response.json();
        }).then(function (envelope) {
            if (envelope && envelope.success === false) throw new Error('rest');
            return envelope ? envelope.data : null;
        });
    }

    function iconButton(glyph, label, onClick) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'tt-btn-icon';
        button.textContent = glyph;
        button.setAttribute('aria-label', label || '');
        button.addEventListener('click', onClick);
        return button;
    }

    function focusBlock(index) {
        var node = document.querySelector('[data-tt-builder-list] [data-index="' + index + '"]');
        if (!node) return;
        var button = node.querySelector('.tt-builder__move .tt-btn-icon:not(:disabled)');
        if (button) button.focus();
    }

    function announce(text) {
        var msg = document.querySelector('[data-tt-builder-msg]');
        if (msg && text) msg.textContent = text;
    }

    function totalMinutes() {
        return state.blocks.reduce(function (sum, block) {
            return sum + (parseInt(block.duration_minutes, 10) || 0);
        }, 0);
    }

    function safeType(type) {
        var known = ['warmup', 'rondo', 'main', 'game', 'finishing', 'cooldown', 'talk'];
        return known.indexOf(type) === -1 ? 'main' : type;
    }

    function initials(name) {
        return String(name || '')
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map(function (part) { return part.charAt(0).toUpperCase(); })
            .join('') || '?';
    }

    /** Server-side sprintf placeholders, filled client-side. */
    function fmt(template, value) {
        return String(template || '').replace(/%d/, String(value));
    }
})();
