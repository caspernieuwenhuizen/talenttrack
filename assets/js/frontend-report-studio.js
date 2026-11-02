/**
 * Report Studio canvas (#4571, epic #4564) — the builder at
 * `?tt_view=report-studio&id=N`, in Rows.
 *
 * Everything it shows comes from REST: the report and its draft from
 * `GET /reports/{id}`, the library from `GET /report-blocks`, the canvas's
 * data from one `GET /reports/{id}/data` (on open, and when the subject, the
 * period or the audience changes), one block's data from
 * `POST /report-blocks/{key}/preview` when its options change. Reasons and
 * labels come from the server or from `TTReportStudio.i18n`; nothing here
 * decides a rule.
 *
 * Saving is `TT.Autosave` against `PATCH /reports/{id}`, the one save loop.
 * On top of it sits the Studio's multi-step undo (an owner decision for this
 * surface only, see docs/save-model.md): a history of the draft in this
 * sitting, at most 50 steps; every undo is saved through the same saver.
 * Revert is `TT.Autosave`'s, back to the draft as the page found it.
 */
(function () {
	'use strict';

	var cfg = window.TTReportStudio;
	var root = document.querySelector('[data-tt-rs]');
	if (!cfg || !root || !window.TT || !window.TT.Autosave) return;

	var t = cfg.i18n || {};
	var MAX_HISTORY = 50;
	var MAX_CHARS = 200000;
	var PREVIEW_DELAY = 300;

	var state = {
		report: null,
		name: '',
		blocks: [],
		revision: 0,
		catalog: {},
		unavailable: [],
		data: {},
		context: null,
		pageFit: null,
		subject: {},
		period: { from: '', to: '' },
		audience: '',
		view: 'screen',
		selected: '',
		history: [],
		search: ''
	};
	var saver = null;
	var previewTimers = {};
	var drag = null;
	var wide = window.matchMedia ? window.matchMedia('(min-width: 1024px)') : { matches: false };

	function $(sel, el) { return (el || root).querySelector(sel); }
	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text != null) n.textContent = text;
		return n;
	}
	function fmt(s) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(s || '').replace(/%(\d+\$)?([sd%])/g, function (m, pos, type) {
			if (type === '%') return '%';
			var v = pos ? args[parseInt(pos, 10) - 1] : args[i++];
			return v == null ? '' : String(v);
		});
	}
	function copy(v) { return JSON.parse(JSON.stringify(v)); }

	// ---- REST ---------------------------------------------------------------

	function api(method, path, body, query) {
		var url = cfg.rest + path;
		if (query) {
			var parts = [];
			Object.keys(query).forEach(function (k) {
				var v = query[k];
				if (v === '' || v == null) return;
				parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
			});
			if (parts.length) url += (url.indexOf('?') === -1 ? '?' : '&') + parts.join('&');
		}
		return fetch(url, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body != null ? JSON.stringify(body) : null
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (json) {
				if (r.ok) return json && json.data !== undefined ? json.data : json;
				var e0 = json && json.errors && json.errors[0] ? json.errors[0] : {};
				var err = new Error(e0.message || t.loadError);
				err.status = r.status;
				err.code = e0.code || '';
				throw err;
			});
		});
	}

	function reportPath(tail) { return 'reports/' + cfg.id + (tail || ''); }

	// ---- the draft and its history -----------------------------------------

	function snapshot() { return { name: state.name, blocks: copy(state.blocks) }; }

	function body() { return { name: state.name, definition: { blocks: state.blocks } }; }

	function apply(b) {
		if (!b) return;
		state.name = typeof b.name === 'string' ? b.name : state.name;
		state.blocks = b.definition && Array.isArray(b.definition.blocks) ? copy(b.definition.blocks) : [];
		renderAll();
		previewMissing();
	}

	/** One change by the builder: remember the draft as it was, change it, save. */
	function commit(change) {
		var before = JSON.stringify(snapshot());
		change();
		if (JSON.stringify(snapshot()) === before) return;
		if (before.length <= MAX_CHARS) {
			state.history.push(before);
			if (state.history.length > MAX_HISTORY) state.history.shift();
		}
		renderAll();
		saver.change();
	}

	function undo() {
		var last = state.history.pop();
		if (!last) return;
		var s = JSON.parse(last);
		state.name = s.name;
		state.blocks = s.blocks;
		renderAll();
		previewMissing();
		saver.saveNow();
	}

	function renderUndo() {
		var btn = $('[data-tt-save-undo]');
		if (btn) btn.hidden = state.history.length === 0;
	}

	// ---- load ----------------------------------------------------------------

	function load() {
		api('GET', reportPath()).then(function (report) {
			state.report = report;
			state.name = report.name || '';
			var draft = report.draft || { definition: { blocks: [] }, revision: 0 };
			state.blocks = draft.definition && Array.isArray(draft.definition.blocks) ? copy(draft.definition.blocks) : [];
			state.revision = draft.revision || 0;
			state.audience = report.audience;
			startSaver();
			return Promise.all([
				api('GET', 'report-blocks', null, { scope: report.scope, audience: report.audience }),
				api('GET', 'reports/start-options', null, { kind: report.kind, scope: report.scope }).catch(function () { return null; }),
				(report.scope === 'player' || report.scope === 'team') && report.binding !== 'viewer'
					? api('GET', 'reports/subject-options').catch(function () { return null; })
					: Promise.resolve(null)
			]);
		}).then(function (res) {
			state.catalog = {};
			(res[0].blocks || []).forEach(function (b) { state.catalog[b.key] = b; });
			state.unavailable = res[0].unavailable || [];
			renderContext(res[1], res[2]);
			renderAll();
			staleNotice();
			loadData();
		})['catch'](function (err) {
			notice(err.message || t.loadError, true);
		});
	}

	function startSaver() {
		saver = window.TT.Autosave.create({
			stateEl: $('[data-tt-save-state]'),
			revertEl: $('[data-tt-save-revert]'),
			storageKey: 'report-studio:' + cfg.id,
			nonce: cfg.nonce,
			i18n: window.TT_Autosave ? window.TT_Autosave.i18n : null,
			serialise: function () {
				return { method: 'PATCH', url: cfg.rest + reportPath('?revision=' + state.revision), body: body() };
			},
			apply: apply,
			onSaved: function (resp) {
				var d = resp && resp.data && resp.data.draft;
				if (d && typeof d.revision === 'number') state.revision = d.revision;
				if (state.view === 'a4') scheduleData();
			},
			onError: function (err) {
				if (err && err.status === 409) {
					saver.halt(t.stale);
					try { window.sessionStorage.setItem('tt-rs-stale-' + cfg.id, '1'); } catch (e) { /* no storage */ }
					window.setTimeout(function () { window.location.reload(); }, 1200);
				}
			}
		});
		saver.seed(body());
	}

	function staleNotice() {
		try {
			if (window.sessionStorage.getItem('tt-rs-stale-' + cfg.id)) {
				window.sessionStorage.removeItem('tt-rs-stale-' + cfg.id);
				notice(t.reloaded, false);
			}
		} catch (e) { /* no storage */ }
	}

	function dataQuery() {
		var q = { source: 'draft', from: state.period.from, to: state.period.to };
		if (state.audience && state.report && state.audience !== state.report.audience) q.audience = state.audience;
		if (state.subject.player_id) q.player_id = state.subject.player_id;
		if (state.subject.team_id) q.team_id = state.subject.team_id;
		return q;
	}

	var dataTimer = null;
	var dataFailed = false;
	var dataSeq = 0;
	var reachSeq = 0;
	function scheduleData() {
		if (dataTimer) window.clearTimeout(dataTimer);
		dataTimer = window.setTimeout(loadData, 600);
	}

	/** The whole canvas in one request, after the draft it reads is saved. */
	function loadData() {
		// A slower answer for an older subject or period must not land over
		// the newer one: only the latest request is applied.
		var seq = ++dataSeq;
		var settle = pending();
		return Promise.resolve(settle).then(function () {
			return api('GET', reportPath('/data'), null, dataQuery());
		}).then(function (d) {
			if (seq !== dataSeq) return;
			state.data = {};
			(d.blocks || []).forEach(function (b) { state.data[b.id] = b; });
			state.context = d.context;
			state.pageFit = d.page_fit || null;
			if (!state.period.from && d.context) {
				state.period = { from: d.context.from, to: d.context.to };
				var from = $('[data-tt-rs-from]');
				var to = $('[data-tt-rs-to]');
				if (from) from.value = d.context.from;
				if (to) to.value = d.context.to;
			}
			if (dataFailed) { dataFailed = false; notice('', false); }
			renderCanvas();
			previewMissing();
		})['catch'](function (err) {
			if (seq !== dataSeq) return;
			state.context = null;
			state.data = {};
			dataFailed = true;
			notice(err.message || t.loadError, true);
			renderCanvas();
		});
	}

	/** Save an edit still waiting, before reading or writing past the saver. */
	function pending() {
		return saver && saver.isPending() ? Promise.resolve(saver.saveNow()) : Promise.resolve(null);
	}

	/** A block the data route has not read yet (just added) gets its own preview. */
	function previewMissing() {
		state.blocks.forEach(function (b) {
			if (!state.data[b.id]) previewBlock(b.id);
		});
	}

	function previewBlock(id) {
		if (!state.context) return;
		if (previewTimers[id]) window.clearTimeout(previewTimers[id]);
		previewTimers[id] = window.setTimeout(function () {
			delete previewTimers[id];
			var block = findBlock(id);
			if (!block) return;
			var asked = JSON.stringify(block.options || {});
			api('POST', 'report-blocks/' + block.key + '/preview', { context: state.context, options: block.options || {} }).then(function (r) {
				var now = findBlock(id);
				if (!now || JSON.stringify(now.options || {}) !== asked) return;
				state.data[id] = { id: id, key: block.key, options: r.options, data: r.data };
				renderCanvas();
			})['catch'](function (err) {
				state.data[id] = { id: id, key: block.key, error: { code: err.code, message: err.message } };
				renderCanvas();
			});
		}, PREVIEW_DELAY);
	}

	// ---- edits ---------------------------------------------------------------

	function findBlock(id) {
		for (var i = 0; i < state.blocks.length; i++) if (state.blocks[i].id === id) return state.blocks[i];
		return null;
	}
	function indexOf(id) {
		for (var i = 0; i < state.blocks.length; i++) if (state.blocks[i].id === id) return i;
		return -1;
	}
	function newId() {
		var n = state.blocks.length + 1;
		while (findBlock('b' + n)) n++;
		return 'b' + n;
	}

	function addBlock(key, at) {
		if (state.blocks.length >= cfg.maxBlocks) { notice(fmt(t.tooMany, cfg.maxBlocks), true); return; }
		var id = newId();
		commit(function () {
			var block = { id: id, key: key, options: {}, width: 'full' };
			if (typeof at === 'number' && at >= 0 && at <= state.blocks.length) state.blocks.splice(at, 0, block);
			else state.blocks.push(block);
		});
		previewBlock(id);
		announce(fmt(t.added, label(key)));
	}

	function moveBlock(id, to) {
		var from = indexOf(id);
		if (from < 0 || to < 0 || to >= state.blocks.length || to === from) return;
		commit(function () {
			var b = state.blocks.splice(from, 1)[0];
			state.blocks.splice(to, 0, b);
		});
		focusBlock(id);
		announce(fmt(t.moved, label(findBlock(id).key), to + 1));
	}

	function removeBlock(id) {
		var at = indexOf(id);
		if (at < 0) return;
		var key = state.blocks[at].key;
		commit(function () { state.blocks.splice(at, 1); });
		if (state.selected === id) closeSettings();
		var next = state.blocks[Math.min(at, state.blocks.length - 1)];
		if (next) focusBlock(next.id);
		announce(fmt(t.removed, label(key)));
	}

	function duplicateBlock(id) {
		var at = indexOf(id);
		if (at < 0) return;
		if (state.blocks.length >= cfg.maxBlocks) { notice(fmt(t.tooMany, cfg.maxBlocks), true); return; }
		var twin = copy(state.blocks[at]);
		twin.id = newId();
		commit(function () { state.blocks.splice(at + 1, 0, twin); });
		state.data[twin.id] = state.data[id] ? Object.assign({}, state.data[id], { id: twin.id }) : undefined;
		if (!state.data[twin.id]) previewBlock(twin.id);
		renderCanvas();
	}

	function toggleWidth(id) {
		commit(function () {
			var b = findBlock(id);
			b.width = b.width === 'half' ? 'full' : 'half';
		});
		focusBlock(id);
	}

	function setOption(id, key, value, resets) {
		commit(function () {
			var b = findBlock(id);
			b.options = b.options || {};
			if (value === '' || value == null) delete b.options[key]; else b.options[key] = value;
			(resets || []).forEach(function (k) { delete b.options[k]; });
		});
		previewBlock(id);
	}

	// ---- rendering -------------------------------------------------------------

	function label(key) {
		if (state.catalog[key]) return state.catalog[key].label;
		for (var i = 0; i < state.unavailable.length; i++) if (state.unavailable[i].key === key) return state.unavailable[i].label;
		return t.block;
	}

	function renderAll() {
		var name = $('[data-tt-rs-name]');
		if (name && document.activeElement !== name) name.value = state.name;
		renderLibrary();
		renderCanvas();
		renderUndo();
		if (state.selected) renderSettings();
	}

	function renderLibrary() {
		var list = $('[data-tt-rs-library-list]');
		if (!list) return;
		list.textContent = '';
		var q = state.search.toLowerCase();
		var full = state.blocks.length >= cfg.maxBlocks;
		Object.keys(state.catalog).forEach(function (key) {
			var b = state.catalog[key];
			if (q && b.label.toLowerCase().indexOf(q) === -1) return;
			var li = el('li', 'tt-rs-lib-item');
			var add = el('button', 'tt-rs-lib-add', b.label);
			add.type = 'button';
			add.disabled = full;
			add.addEventListener('click', function () {
				addBlock(key);
				if (!wide.matches) closeSheet('[data-tt-rs-library]');
			});
			var grip = el('button', 'tt-rs-grip');
			grip.type = 'button';
			grip.setAttribute('aria-label', fmt(t.dragAdd, b.label));
			grip.dataset.ttRsDragKey = key;
			grip.disabled = full;
			li.appendChild(grip);
			li.appendChild(add);
			list.appendChild(li);
		});
		state.unavailable.forEach(function (b) {
			if (q && b.label.toLowerCase().indexOf(q) === -1) return;
			var li = el('li', 'tt-rs-lib-item tt-rs-lib-item--off');
			li.appendChild(el('span', 'tt-rs-lib-label', b.label));
			li.appendChild(el('span', 'tt-rs-reason', b.reason));
			list.appendChild(li);
		});
		if (!list.children.length) list.appendChild(el('li', 'tt-rs-empty', t.noBlocks));
	}

	function renderCanvas() {
		var canvas = $('[data-tt-rs-canvas]');
		if (!canvas) return;
		canvas.dataset.view = state.view;
		canvas.textContent = '';
		if (!state.blocks.length) {
			canvas.appendChild(el('li', 'tt-rs-empty', t.emptyCanvas));
		}
		var breaks = state.view === 'a4' && state.pageFit ? state.pageFit.breaks || [] : [];
		var page = 1;
		state.blocks.forEach(function (b, i) {
			if (breaks.indexOf(b.id) !== -1) {
				page++;
				var br = el('li', 'tt-rs-page-break', fmt(t.page, page));
				br.setAttribute('aria-hidden', 'true');
				canvas.appendChild(br);
			}
			canvas.appendChild(renderBlock(b, i));
		});
		renderMeter();
	}

	function renderBlock(b, i) {
		var li = el('li', 'tt-rs-block tt-rs-block--' + (b.width === 'half' ? 'half' : 'full'));
		var art = el('article', 'tt-rs-card');
		art.tabIndex = 0;
		art.dataset.ttRsBlock = b.id;
		art.setAttribute('aria-label', fmt(t.blockAt, label(b.key), i + 1, state.blocks.length));
		if (state.selected === b.id) art.classList.add('is-selected');

		var head = el('header', 'tt-rs-card-head');
		var grip = el('button', 'tt-rs-grip');
		grip.type = 'button';
		grip.dataset.ttRsDragId = b.id;
		grip.setAttribute('aria-label', fmt(t.dragMove, label(b.key)));
		head.appendChild(grip);
		head.appendChild(el('h3', 'tt-rs-card-title', label(b.key)));

		var bar = el('div', 'tt-rs-toolbar');
		bar.setAttribute('role', 'toolbar');
		bar.setAttribute('aria-label', fmt(t.toolbar, label(b.key)));
		bar.appendChild(tool('↑', t.up, i === 0, function () { moveBlock(b.id, i - 1); }));
		bar.appendChild(tool('↓', t.down, i === state.blocks.length - 1, function () { moveBlock(b.id, i + 1); }));
		bar.appendChild(tool(b.width === 'half' ? '▭' : '◫', b.width === 'half' ? t.makeFull : t.makeHalf, false, function () { toggleWidth(b.id); }));
		bar.appendChild(tool('⧉', t.duplicate, false, function () { duplicateBlock(b.id); }));
		bar.appendChild(tool('⚙', t.settings, false, function () { select(b.id); }));
		bar.appendChild(tool('✕', t.remove, false, function () { removeBlock(b.id); }));
		head.appendChild(bar);
		art.appendChild(head);
		art.appendChild(renderData(b));

		art.addEventListener('keydown', function (e) {
			if (e.target !== art) return;
			if (e.key === 'Enter') { e.preventDefault(); select(b.id); }
			if (e.key === 'Delete') { e.preventDefault(); removeBlock(b.id); }
		});
		li.appendChild(art);
		return li;
	}

	function tool(glyph, name, disabled, fn) {
		var btn = el('button', 'tt-rs-tool', glyph);
		btn.type = 'button';
		btn.setAttribute('aria-label', name);
		btn.disabled = disabled;
		btn.addEventListener('click', fn);
		return btn;
	}

	function day(ymd) {
		if (!ymd) return '';
		var d = new Date(ymd + 'T00:00:00');
		if (isNaN(d.getTime())) return ymd;
		try { return d.toLocaleDateString(document.documentElement.lang || undefined, { day: 'numeric', month: 'long', year: 'numeric' }); } catch (e) { return ymd; }
	}

	function renderData(b) {
		var box = el('div', 'tt-rs-card-body');
		var d = state.data[b.id];
		if (!d) {
			box.appendChild(el('p', 'tt-rs-block-msg', t.loading));
			return box;
		}
		if (d.error) {
			box.classList.add('is-off');
			box.appendChild(el('p', 'tt-rs-block-msg', d.error.message));
			return box;
		}
		var data = d.data || {};
		if (data.value !== undefined && !Array.isArray(data.rows)) {
			var fig = el('p', 'tt-rs-figure');
			fig.appendChild(el('span', 'tt-rs-figure-value', data.value == null ? '–' : String(data.value) + (data.unit === 'percent' ? '%' : '')));
			fig.appendChild(el('span', 'tt-rs-figure-label', data.measure_label || ''));
			box.appendChild(fig);
			return box;
		}
		var rows = Array.isArray(data.rows) ? data.rows : [];
		if (!rows.length) {
			var ctx = state.context || {};
			box.appendChild(el('p', 'tt-rs-block-msg', fmt(t.nothing, day(ctx.from), day(ctx.to))));
			return box;
		}
		var cols = Object.keys(rows[0]).filter(function (k) { return k !== 'player_id' && k !== 'key'; });
		var wrap = el('div', 'tt-rs-table-wrap');
		var table = el('table', 'tt-rs-table');
		var thead = el('thead');
		var hr = el('tr');
		cols.forEach(function (c) { hr.appendChild(el('th', null, column(c, d))); });
		thead.appendChild(hr);
		table.appendChild(thead);
		var tbody = el('tbody');
		rows.forEach(function (r) {
			var tr = el('tr');
			cols.forEach(function (c) { tr.appendChild(el('td', null, r[c] == null ? '–' : String(r[c]))); });
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		wrap.appendChild(table);
		box.appendChild(wrap);
		if (data.truncated) box.appendChild(el('p', 'tt-rs-block-msg', t.truncated));
		return box;
	}

	/** A column's heading: the measure or breakdown the block names, else the server's word. */
	function column(c, d) {
		var data = d.data || {};
		if (c === 'value' && data.measure_label) return data.measure_label;
		if (c === 'label' && data.breakdown) {
			var opt = optionOf(d.key, 'breakdown');
			var set = opt && opt.choices ? opt.choices[(d.options || {}).fact] || [] : [];
			for (var i = 0; i < set.length; i++) if (set[i].value === data.breakdown) return set[i].label;
		}
		return (cfg.columns && cfg.columns[c]) || t.value;
	}

	function optionOf(key, name) {
		var b = state.catalog[key];
		if (!b) return null;
		for (var i = 0; i < b.options.length; i++) if (b.options[i].key === name) return b.options[i];
		return null;
	}

	function renderMeter() {
		var meter = $('[data-tt-rs-meter]');
		if (!meter) return;
		meter.hidden = state.view !== 'a4' || !state.pageFit;
		if (meter.hidden) return;
		meter.textContent = '';
		var fit = state.pageFit;
		var last = fit.fill[fit.fill.length - 1];
		meter.appendChild(el('p', 'tt-rs-meter-text', fit.pages === 1 ? fmt(t.onePage, last) : fmt(t.pages, fit.pages, last)));
		var bars = el('div', 'tt-rs-meter-bars');
		bars.setAttribute('aria-hidden', 'true');
		fit.fill.forEach(function (f) {
			var bar = el('span', 'tt-rs-meter-bar');
			var fill = el('span', 'tt-rs-meter-fill');
			fill.style.inlineSize = Math.max(0, Math.min(100, f)) + '%';
			bar.appendChild(fill);
			bars.appendChild(bar);
		});
		meter.appendChild(bars);
	}

	// ---- the settings panel ------------------------------------------------------

	function select(id) {
		state.selected = id;
		renderCanvas();
		renderSettings();
		openSheet('[data-tt-rs-settings]');
	}

	function closeSettings() {
		state.selected = '';
		closeSheet('[data-tt-rs-settings]');
		renderCanvas();
	}

	function renderSettings() {
		var box = $('[data-tt-rs-settings-body]');
		var block = findBlock(state.selected);
		if (!box) return;
		box.textContent = '';
		if (!block) return;
		var meta = state.catalog[block.key];
		var title = $('[data-tt-rs-settings-title]');
		if (title) title.textContent = label(block.key);
		var options = meta ? meta.options : [];
		var values = block.options || {};
		if (!options.length) box.appendChild(el('p', 'tt-rs-hint', t.noOptions));
		options.forEach(function (o) {
			var id = 'tt-rs-opt-' + block.id + '-' + o.key;
			var field = el('div', 'tt-field');
			var lab = el('label', 'tt-field-label', o.label);
			lab.htmlFor = id;
			field.appendChild(lab);
			var input;
			if (o.type === 'bool') {
				input = el('input', 'tt-rs-check');
				input.type = 'checkbox';
				input.checked = !!values[o.key];
				input.addEventListener('change', function () { setOption(block.id, o.key, input.checked); });
			} else if (o.type === 'int') {
				input = el('input', 'tt-input');
				input.type = 'number';
				input.inputMode = 'numeric';
				if (o.min != null) input.min = o.min;
				if (o.max != null) input.max = o.max;
				input.value = values[o.key] != null ? values[o.key] : '';
				input.addEventListener('change', function () { setOption(block.id, o.key, input.value === '' ? '' : parseInt(input.value, 10)); });
			} else {
				input = el('select', 'tt-input');
				var choices = o.depends_on ? (o.choices[values[o.depends_on]] || []) : o.choices;
				var none = el('option', null, o.required ? t.choose : t.none);
				none.value = '';
				input.appendChild(none);
				(choices || []).forEach(function (c) {
					var opt = el('option', null, c.label);
					opt.value = c.value;
					if (values[o.key] === c.value) opt.selected = true;
					input.appendChild(opt);
				});
				input.addEventListener('change', function () {
					var resets = options.filter(function (x) { return x.depends_on === o.key; }).map(function (x) { return x.key; });
					setOption(block.id, o.key, input.value, resets);
				});
			}
			input.id = id;
			field.appendChild(input);
			box.appendChild(field);
		});
		if (meta) {
			box.appendChild(el('h4', 'tt-rs-settings-sub', t.whoSees));
			box.appendChild(el('p', 'tt-rs-hint', meta.family));
			box.appendChild(el('h4', 'tt-rs-settings-sub', t.source));
			box.appendChild(el('p', 'tt-rs-hint', meta.source));
		}
	}

	// ---- sheets ------------------------------------------------------------------

	function openSheet(sel) {
		var d = $(sel);
		if (!d || d.open) return;
		if (wide.matches || typeof d.showModal !== 'function') d.setAttribute('open', '');
		else d.showModal();
	}
	function closeSheet(sel) {
		var d = $(sel);
		if (!d || !d.open) return;
		if (typeof d.close === 'function') d.close(); else d.removeAttribute('open');
	}
	function layoutSheets() {
		var lib = $('[data-tt-rs-library]');
		if (!lib) return;
		var modal = false;
		try { modal = lib.matches(':modal'); } catch (e) { modal = false; }
		if (wide.matches) { if (!lib.open) lib.setAttribute('open', ''); }
		else if (lib.open && !modal) lib.removeAttribute('open');
	}

	// ---- the context bar ---------------------------------------------------------

	function renderContext(starts, subjects) {
		var report = state.report;
		var subjectBox = $('[data-tt-rs-subject]');
		if (subjectBox) {
			subjectBox.textContent = '';
			if (subjects && (report.scope === 'player' || report.scope === 'team')) {
				var id = 'tt-rs-subject-select';
				var lab = el('label', 'tt-field-label', report.scope === 'player' ? t.player : t.team);
				lab.htmlFor = id;
				var sel = el('select', 'tt-input');
				sel.id = id;
				var current = report.scope === 'player' ? report.subject.player_id : report.subject.team_id;
				if (!current) {
					var pick = el('option', null, t.choose);
					pick.value = '';
					sel.appendChild(pick);
				}
				if (report.scope === 'team') {
					subjects.teams.forEach(function (tm) {
						var o = el('option', null, tm.name);
						o.value = tm.id;
						if (tm.id === current) o.selected = true;
						sel.appendChild(o);
					});
				} else {
					var groups = {};
					subjects.teams.forEach(function (tm) {
						groups[tm.id] = el('optgroup');
						groups[tm.id].label = tm.name;
						sel.appendChild(groups[tm.id]);
					});
					subjects.players.forEach(function (p) {
						var o = el('option', null, p.name);
						o.value = p.id;
						if (p.id === current) o.selected = true;
						(groups[p.team_id] || sel).appendChild(o);
					});
				}
				sel.addEventListener('change', function () {
					state.subject = {};
					if (sel.value) state.subject[report.scope === 'player' ? 'player_id' : 'team_id'] = parseInt(sel.value, 10);
					loadData();
				});
				subjectBox.appendChild(lab);
				subjectBox.appendChild(sel);
			} else {
				subjectBox.appendChild(el('span', 'tt-field-label', t.subject));
				subjectBox.appendChild(el('span', 'tt-rs-subject-text', cfg.scopeLabel || ''));
			}
		}

		var audienceBox = $('[data-tt-rs-audience]');
		if (audienceBox && starts && starts.options && starts.options.audience) {
			audienceBox.textContent = '';
			audienceBox.appendChild(el('legend', null, t.audience));
			starts.options.audience.forEach(function (o) {
				var lab = el('label', 'tt-rs-radio' + (o.ok ? '' : ' is-off'));
				var input = el('input');
				input.type = 'radio';
				input.name = 'tt-rs-audience';
				input.value = o.value;
				input.checked = o.value === state.audience;
				input.disabled = !o.ok;
				input.addEventListener('change', function () {
					state.audience = o.value;
					loadData();
				});
				lab.appendChild(input);
				var txt = el('span', null, o.label);
				lab.appendChild(txt);
				if (!o.ok && o.reason) lab.appendChild(el('span', 'tt-rs-reason', o.reason));
				audienceBox.appendChild(lab);
			});
		}
	}

	// ---- versions and publishing -----------------------------------------------

	function openVersions() {
		var list = $('[data-tt-rs-versions-list]');
		var dialog = $('[data-tt-rs-versions]');
		if (!list || !dialog) return;
		list.textContent = '';
		list.appendChild(el('li', 'tt-rs-hint', t.loading));
		dialog.showModal();
		api('GET', reportPath('/versions')).then(function (r) {
			list.textContent = '';
			var versions = r.versions || [];
			if (!versions.length) list.appendChild(el('li', 'tt-rs-hint', t.noVersions));
			versions.forEach(function (v) {
				var li = el('li', 'tt-rs-version');
				li.appendChild(el('span', 'tt-rs-version-title', fmt(t.version, v.version_no, day(String(v.published_at || '').slice(0, 10)))));
				if (v.note) li.appendChild(el('span', 'tt-rs-hint', v.note));
				var use = el('button', 'tt-btn tt-btn-secondary', t.useVersion);
				use.type = 'button';
				use.addEventListener('click', function () { useVersion(v.version_no, dialog); });
				li.appendChild(use);
				list.appendChild(li);
			});
		})['catch'](function (err) {
			list.textContent = '';
			list.appendChild(el('li', 'tt-rs-hint', err.message));
		});
	}

	function useVersion(no, dialog) {
		var before = JSON.stringify(snapshot());
		pending().then(function () {
			return api('POST', reportPath('/draft'), { from_version: no, revision: state.revision });
		}).then(function (r) {
			state.history.push(before);
			if (state.history.length > MAX_HISTORY) state.history.shift();
			state.blocks = r.draft && r.draft.definition ? copy(r.draft.definition.blocks || []) : [];
			state.revision = r.draft ? r.draft.revision : state.revision;
			dialog.close();
			renderAll();
			notice(fmt(t.versionUsed, no), false);
			loadData();
		})['catch'](function (err) { notice(err.message, true); });
	}

	function openPublish() {
		var dialog = $('[data-tt-rs-publish]');
		if (!dialog) return;
		var report = state.report;
		var roles = $('[data-tt-rs-personas]', dialog);
		var reach = $('[data-tt-rs-reach]', dialog);
		var note = $('[data-tt-rs-note]', dialog);
		var error = $('[data-tt-rs-publish-error]', dialog);
		error.textContent = '';
		note.value = '';
		var forRole = report.sharing === 'role' && report.permissions && report.permissions.can_publish;
		roles.hidden = !forRole;
		roles.querySelectorAll('input').forEach(function (i) {
			i.checked = (report.persona_keys || []).indexOf(i.value) !== -1;
		});
		function keys() {
			if (!forRole) return [];
			return Array.prototype.map.call(roles.querySelectorAll('input:checked'), function (i) { return i.value; });
		}
		// Ticking roles quickly sends several counts; only the answer for the
		// roles ticked last may land.
		function count() {
			var seq = ++reachSeq;
			var k = keys();
			if (!k.length) { reach.textContent = t.reachMe; return; }
			reach.textContent = t.counting;
			api('GET', reportPath('/reach'), null, { persona_keys: k.join(',') }).then(function (r) {
				if (seq !== reachSeq) return;
				var names = r.personas.map(function (p) { return p.label; }).join(', ');
				reach.textContent = fmt(r.count === 1 ? t.reachOne : t.reachMany, r.count, names);
			})['catch'](function (err) {
				if (seq === reachSeq) reach.textContent = err.message;
			});
		}
		roles.onchange = count;
		count();
		dialog.returnValue = '';
		dialog.showModal();
		var form = $('form', dialog);
		form.onsubmit = function (e) {
			if (e.submitter && e.submitter.value === 'cancel') return;
			e.preventDefault();
			if (!note.value.trim()) { error.textContent = t.noteNeeded; note.focus(); return; }
			pending().then(function () {
				return api('POST', reportPath('/versions'), { note: note.value.trim(), persona_keys: keys() });
			}).then(function (r) {
				report.persona_keys = r.persona_keys;
				dialog.close();
				notice(fmt(t.published, r.version_no), false);
			})['catch'](function (err) { error.textContent = err.message; });
		};
	}

	// ---- drag and drop (pointer events, no library) ----------------------------

	function dropIndex(y) {
		var cards = root.querySelectorAll('[data-tt-rs-block]');
		for (var i = 0; i < cards.length; i++) {
			var r = cards[i].getBoundingClientRect();
			if (y < r.top + r.height / 2) return i;
		}
		return cards.length;
	}

	function onPointerDown(e) {
		var grip = e.target.closest ? e.target.closest('[data-tt-rs-drag-id], [data-tt-rs-drag-key]') : null;
		if (!grip || grip.disabled || e.button > 0) return;
		drag = { id: grip.dataset.ttRsDragId || '', key: grip.dataset.ttRsDragKey || '', moved: false, x: e.clientX, y: e.clientY };
		try { grip.setPointerCapture(e.pointerId); } catch (err) { /* old browser */ }
	}

	function onPointerMove(e) {
		if (!drag) return;
		if (!drag.moved && Math.abs(e.clientY - drag.y) + Math.abs(e.clientX - drag.x) < 6) return;
		drag.moved = true;
		e.preventDefault();
		var canvas = $('[data-tt-rs-canvas]');
		var r = canvas.getBoundingClientRect();
		var over = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top - 24 && e.clientY <= r.bottom + 24;
		canvas.classList.toggle('is-drop-target', over);
		drag.over = over;
		drag.at = dropIndex(e.clientY);
	}

	function onPointerUp() {
		if (!drag) return;
		var d = drag;
		drag = null;
		var canvas = $('[data-tt-rs-canvas]');
		if (canvas) canvas.classList.remove('is-drop-target');
		if (!d.moved || !d.over) return;
		if (d.key) { addBlock(d.key, d.at); return; }
		var from = indexOf(d.id);
		var to = d.at > from ? d.at - 1 : d.at;
		moveBlock(d.id, Math.max(0, Math.min(state.blocks.length - 1, to)));
	}

	// ---- small things ------------------------------------------------------------

	function focusBlock(id) {
		var card = root.querySelector('[data-tt-rs-block="' + id + '"]');
		if (card) card.focus();
	}

	function notice(text, isError) {
		var n = $('[data-tt-rs-notice]');
		if (!n) return;
		n.textContent = text || '';
		n.hidden = !text;
		n.classList.toggle('is-error', !!isError);
	}

	function announce(text) {
		var live = $('[data-tt-rs-live]');
		if (live) live.textContent = text;
	}

	function bind() {
		// Typing a name is one step of undo, not one per letter.
		var name = $('[data-tt-rs-name]');
		var renaming = false;
		if (name) {
			name.addEventListener('input', function () {
				if (!renaming) {
					renaming = true;
					state.history.push(JSON.stringify(snapshot()));
					if (state.history.length > MAX_HISTORY) state.history.shift();
				}
				state.name = name.value;
				renderUndo();
				saver.change();
			});
			name.addEventListener('blur', function () { renaming = false; });
		}
		var settings = $('[data-tt-rs-settings]');
		if (settings) settings.addEventListener('close', function () {
			if (!state.selected) return;
			state.selected = '';
			renderCanvas();
		});
		var undoBtn = $('[data-tt-save-undo]');
		if (undoBtn) undoBtn.addEventListener('click', function (e) { e.preventDefault(); undo(); });

		root.querySelectorAll('[data-tt-rs-view] input').forEach(function (input) {
			input.addEventListener('change', function () {
				state.view = input.value;
				renderCanvas();
				if (state.view === 'a4') loadData();
			});
		});
		['from', 'to'].forEach(function (k) {
			var input = $('[data-tt-rs-' + k + ']');
			if (input) input.addEventListener('change', function () {
				state.period[k] = input.value;
				if (state.period.from && state.period.to) loadData();
			});
		});
		var search = $('[data-tt-rs-search]');
		if (search) search.addEventListener('input', function () { state.search = search.value; renderLibrary(); });

		var openLib = $('[data-tt-rs-open-library]');
		if (openLib) openLib.addEventListener('click', function () { openSheet('[data-tt-rs-library]'); });
		root.querySelectorAll('[data-tt-rs-close]').forEach(function (b) {
			b.addEventListener('click', function () {
				var sheet = b.closest('dialog');
				if (sheet && sheet.matches('[data-tt-rs-settings]')) closeSettings();
				else if (sheet) closeSheet('[' + (sheet.hasAttribute('data-tt-rs-library') ? 'data-tt-rs-library' : 'data-tt-rs-versions') + ']');
			});
		});
		var versions = $('[data-tt-rs-open-versions]');
		if (versions) versions.addEventListener('click', openVersions);
		var publish = $('[data-tt-rs-open-publish]');
		if (publish) publish.addEventListener('click', openPublish);

		root.addEventListener('pointerdown', onPointerDown);
		document.addEventListener('pointermove', onPointerMove, { passive: false });
		document.addEventListener('pointerup', onPointerUp);
		document.addEventListener('pointercancel', function () { drag = null; });

		if (wide.addEventListener) wide.addEventListener('change', layoutSheets);
		layoutSheets();
	}

	bind();
	load();
})();
