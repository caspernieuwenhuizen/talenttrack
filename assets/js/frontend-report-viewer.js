/**
 * Report Studio reader's view (#4591) — `?tt_view=report&id=N`, a published
 * report for the people it is published to. Read only.
 *
 * Everything comes from REST: the report from `GET /reports/{id}`, the block
 * names from `GET /report-blocks`, the subjects the reader may switch to from
 * `GET /reports/{id}/subjects`, and the figures from one
 * `GET /reports/{id}/data?source=published`. The server decides the subject,
 * the period and every block's access on each call; a block the reader may
 * not see comes back as an error and is shown as "not available to you".
 */
(function () {
	'use strict';

	var cfg = window.TTReportViewer;
	var root = document.querySelector('[data-tt-rv]');
	if (!cfg || !root || !window.TT || !window.TT.ReportBlocks) return;

	var t = cfg.i18n || {};
	var state = { report: null, catalog: {}, subject: {}, period: '', seq: 0 };

	function $(sel) { return root.querySelector(sel); }
	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text != null) n.textContent = text;
		return n;
	}

	function api(path, query) {
		var url = cfg.rest + path;
		var parts = [];
		Object.keys(query || {}).forEach(function (k) {
			var v = query[k];
			if (v === '' || v == null) return;
			parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
		});
		if (parts.length) url += '?' + parts.join('&');
		return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-WP-Nonce': cfg.nonce } })
			.then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (json) {
					if (r.ok) return json && json.data !== undefined ? json.data : json;
					var e0 = json && json.errors && json.errors[0] ? json.errors[0] : {};
					var err = new Error(e0.message || t.loadError);
					err.status = r.status;
					throw err;
				});
			});
	}

	function notice(text, isError) {
		var n = $('[data-tt-rv-notice]');
		if (!n) return;
		n.textContent = text || '';
		n.hidden = !text;
		n.classList.toggle('is-error', !!isError);
	}

	function label(key) {
		return state.catalog[key] ? state.catalog[key].label : t.block;
	}

	function renderSubjects(subjects) {
		var box = $('[data-tt-rv-subject]');
		if (!box) return;
		box.textContent = '';
		var report = state.report;
		var isPlayer = report.scope === 'player';
		var list = isPlayer ? subjects.players : subjects.teams;
		if (!list || !list.length) { box.hidden = true; return; }
		box.hidden = false;

		var id = 'tt-rv-subject-select';
		var lab = el('label', 'tt-field-label', isPlayer ? t.player : t.team);
		lab.htmlFor = id;
		var sel = el('select', 'tt-input');
		sel.id = id;
		var pick = el('option', null, t.choose);
		pick.value = '';
		sel.appendChild(pick);
		list.forEach(function (s) {
			var o = el('option', null, s.name);
			o.value = s.id;
			sel.appendChild(o);
		});
		sel.addEventListener('change', function () {
			state.subject = {};
			if (sel.value) state.subject[isPlayer ? 'player_id' : 'team_id'] = parseInt(sel.value, 10);
			loadData();
		});
		box.appendChild(lab);
		box.appendChild(sel);
	}

	function renderPeriods() {
		var box = $('[data-tt-rv-periods]');
		if (!box) return;
		if (state.report.period_mode !== 'on_open' || !cfg.periods || !cfg.periods.length) { box.hidden = true; return; }
		box.hidden = false;
		cfg.periods.forEach(function (p) {
			var lab = el('label', 'tt-rs-radio');
			var input = el('input');
			input.type = 'radio';
			input.name = 'tt-rv-period';
			input.value = p.key;
			input.checked = p.key === state.period;
			input.addEventListener('change', function () {
				state.period = p.key;
				loadData();
			});
			lab.appendChild(input);
			lab.appendChild(el('span', null, p.label));
			box.appendChild(lab);
		});
	}

	function renderBlocks(data) {
		var list = $('[data-tt-rv-blocks]');
		if (!list) return;
		list.textContent = '';
		var blocks = data ? data.blocks || [] : [];
		if (data && !blocks.length) list.appendChild(el('li', 'tt-rs-empty', t.empty));
		blocks.forEach(function (b) {
			var li = el('li', 'tt-rs-block tt-rs-block--' + (b.width === 'half' ? 'half' : 'full'));
			var art = el('article', 'tt-rs-card');
			var head = el('header', 'tt-rs-card-head');
			head.appendChild(el('h2', 'tt-rs-card-title', label(b.key)));
			art.appendChild(head);
			art.appendChild(window.TT.ReportBlocks.body(b, {
				i18n: t, columns: cfg.columns, catalog: state.catalog, context: data.context, errorText: t.notAvailable
			}));
			li.appendChild(art);
			list.appendChild(li);
		});
	}

	function loadData() {
		var seq = ++state.seq;
		var q = { source: 'published', period: state.period };
		if (state.subject.player_id) q.player_id = state.subject.player_id;
		if (state.subject.team_id) q.team_id = state.subject.team_id;
		notice(t.loading, false);
		return api('reports/' + cfg.id + '/data', q).then(function (d) {
			if (seq !== state.seq) return;
			notice('', false);
			renderBlocks(d);
		})['catch'](function (err) {
			if (seq !== state.seq) return;
			renderBlocks(null);
			notice(err.message || t.loadError, true);
		});
	}

	api('reports/' + cfg.id).then(function (report) {
		state.report = report;
		return Promise.all([
			api('report-blocks', { scope: report.scope, audience: report.audience }).catch(function () { return { blocks: [] }; }),
			api('reports/' + cfg.id + '/subjects').catch(function () { return { teams: [], players: [] }; })
		]);
	}).then(function (res) {
		(res[0].blocks || []).forEach(function (b) { state.catalog[b.key] = b; });
		(res[0].unavailable || []).forEach(function (b) { state.catalog[b.key] = state.catalog[b.key] || b; });
		renderSubjects(res[1]);
		renderPeriods();
		return loadData();
	})['catch'](function (err) {
		notice(err.message || t.loadError, true);
	});
})();
