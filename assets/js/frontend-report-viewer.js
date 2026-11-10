/**
 * Report Studio reader's view (#4591) — `?tt_view=report&id=N`, a published
 * report for the people it is published to. Read only.
 *
 * Everything comes from REST: the report from `GET /reports/{id}`, the
 * subjects the reader may switch to from `GET /reports/{id}/subjects`, and
 * the report itself from one `GET /reports/{id}/document?source=published`
 * (#5021): the document the PDF prints, drawn on the server, so the screen and
 * the paper cannot show a block two ways. The server decides the subject, the
 * period and every block's access on each call; a block the reader may not
 * see is printed as "not available to you".
 *
 * Taking a snapshot (#5021) is its own control with its own confirm, and
 * lands on the frozen copy.
 */
(function () {
	'use strict';

	var cfg = window.TTReportViewer;
	var root = document.querySelector('[data-tt-rv]');
	if (!cfg || !root) return;

	var t = cfg.i18n || {};
	var state = { report: null, subject: {}, period: '', seq: 0 };

	function $(sel) { return root.querySelector(sel); }
	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text != null) n.textContent = text;
		return n;
	}

	function query(q) {
		var parts = [];
		Object.keys(q || {}).forEach(function (k) {
			var v = q[k];
			if (v === '' || v == null) return;
			parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
		});
		return parts.join('&');
	}

	function api(method, path, q, body) {
		var url = cfg.rest + path;
		var qs = query(q);
		if (qs) url += '?' + qs;
		var init = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-WP-Nonce': cfg.nonce } };
		if (body) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(body);
		}
		return fetch(url, init).then(function (r) {
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

	/** What the reader chose: the subject and the period, for every request. */
	function choice() {
		var q = { period: state.period };
		if (state.subject.player_id) q.player_id = state.subject.player_id;
		if (state.subject.team_id) q.team_id = state.subject.team_id;
		return q;
	}

	function renderSubjects(subjects) {
		var box = $('[data-tt-rv-subject]');
		if (!box) return;
		box.textContent = '';
		var isPlayer = state.report.scope === 'player';
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
			load();
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
				load();
			});
			lab.appendChild(input);
			lab.appendChild(el('span', null, p.label));
			box.appendChild(lab);
		});
	}

	/** The PDF of what is on screen: the same subject and period. */
	function renderPdf(ok) {
		var link = $('[data-tt-rv-pdf]');
		if (!link || !cfg.pdf) return;
		var qs = query(choice());
		link.href = cfg.pdf + (qs ? '&' + qs : '');
		link.hidden = !ok;
	}

	function load() {
		var seq = ++state.seq;
		var box = $('[data-tt-rv-document]');
		notice(t.loading, false);
		var q = choice();
		q.source = 'published';
		return api('GET', 'reports/' + cfg.id + '/document', q).then(function (d) {
			if (seq !== state.seq) return;
			notice('', false);
			// The server escaped every value in it; it is the PDF's markup.
			if (box) box.innerHTML = d.html || '';
			renderPdf(true);
		})['catch'](function (err) {
			if (seq !== state.seq) return;
			if (box) box.textContent = '';
			renderPdf(false);
			notice(err.message || t.loadError, true);
		});
	}

	function bindSnapshot() {
		var open = $('[data-tt-rv-open-snapshot]');
		var dialog = $('[data-tt-rv-snapshot]');
		if (!open || !dialog || typeof dialog.showModal !== 'function') {
			if (open) open.hidden = true;
			return;
		}
		var form = dialog.querySelector('form');
		var name = dialog.querySelector('[data-tt-rv-snapshot-name]');
		var error = dialog.querySelector('[data-tt-rv-snapshot-error]');
		var busy = false;

		open.addEventListener('click', function () {
			if (error) error.textContent = '';
			dialog.showModal();
			if (name) name.focus();
		});
		form.addEventListener('submit', function (e) {
			var submitter = e.submitter;
			if (!submitter || submitter.value !== 'confirm') return;
			e.preventDefault();
			if (busy) return;
			busy = true;
			submitter.disabled = true;
			var body = choice();
			if (name && name.value.trim()) body.title = name.value.trim();
			if (!body.period) delete body.period;
			api('POST', 'reports/' + cfg.id + '/snapshots', null, body).then(function (s) {
				notice(t.snapshotTaken, false);
				window.location.href = String(cfg.snapshotUrl || '').replace('__uuid__', encodeURIComponent(s.uuid));
			})['catch'](function (err) {
				if (error) error.textContent = err.message || t.loadError;
			}).then(function () {
				busy = false;
				submitter.disabled = false;
			});
		});
	}

	bindSnapshot();
	api('GET', 'reports/' + cfg.id).then(function (report) {
		state.report = report;
		return api('GET', 'reports/' + cfg.id + '/subjects').catch(function () { return { teams: [], players: [] }; });
	}).then(function (subjects) {
		renderSubjects(subjects);
		renderPeriods();
		return load();
	})['catch'](function (err) {
		notice(err.message || t.loadError, true);
	});
})();
