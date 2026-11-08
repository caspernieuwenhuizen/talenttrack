/**
 * TT.ReportBlocks (#4591) — how a Report Studio block's data is drawn, for
 * the builder's canvas and the reader's view alike, so both show a block the
 * same way.
 *
 *   TT.ReportBlocks.body(block, {
 *       i18n:    { loading, nothing, truncated, value, periodRange, sincePrevious,
 *                  emptyText, pageBreakNote },  // the last two only on the canvas
 *       columns: { week: 'Week', … },   // column headings, from the server
 *       catalog: { key: blockFromGetReportBlocks },
 *       context: dataResponse.context,
 *       errorText: 'Not available to you'  // optional: replaces the error
 *   })
 *
 * `block` is one entry of `GET /reports/{id}/data` (or a preview), or
 * undefined while it is loading. Every word comes from the caller, which
 * reads it from the server; the payload is written with textContent.
 */
(function () {
	'use strict';

	window.TT = window.TT || {};

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

	function day(ymd) {
		if (!ymd) return '';
		var d = new Date(ymd + 'T00:00:00');
		if (isNaN(d.getTime())) return ymd;
		try { return d.toLocaleDateString(document.documentElement.lang || undefined, { day: 'numeric', month: 'long', year: 'numeric' }); } catch (e) { return ymd; }
	}

	function optionOf(catalog, key, name) {
		var b = catalog && catalog[key];
		if (!b || !b.options) return null;
		for (var i = 0; i < b.options.length; i++) if (b.options[i].key === name) return b.options[i];
		return null;
	}

	/** A column's heading: the measure or breakdown the block names, else the server's word. */
	function column(c, d, opts) {
		var data = d.data || {};
		var t = opts.i18n || {};
		if (c === 'value' && data.measure_label) return data.measure_label;
		if (c === 'label' && data.breakdown) {
			var opt = optionOf(opts.catalog, d.key, 'breakdown');
			var set = opt && opt.choices ? opt.choices[(d.options || {}).fact] || [] : [];
			for (var i = 0; i < set.length; i++) if (set[i].value === data.breakdown) return set[i].label;
		}
		return (opts.columns && opts.columns[c]) || t.value;
	}

	function signed(v) {
		var n = Number(v);
		return (n > 0 ? '+' : '') + String(v);
	}

	function figureValue(f) {
		if (f.value == null) return '–';
		if (f.unit === 'count_of') return f.of > 0 ? String(f.value) + '/' + String(f.of) : '–';
		return String(f.value) + (f.unit === 'percent' ? '%' : '');
	}

	/** Blocks that are not a figure or a table (#4572), drawn by key. */
	var RENDERERS = {
		letterhead: function (box, data, opts) {
			var t = opts.i18n || {};
			var head = el('header', 'tt-rs-letterhead');
			// The club colours the server resolved (ReportBrandColour), the
			// ones a printed report carries; the theme's colour otherwise.
			var brand = data.brand || {};
			var hex = /^#[0-9a-f]{6}$/i;
			if (hex.test(brand.primary || '')) head.style.setProperty('--tt-rs-brand', brand.primary);
			if (hex.test(brand.tint || '')) head.style.setProperty('--tt-rs-brand-tint', brand.tint);
			if (hex.test(brand.number || '')) head.style.setProperty('--tt-rs-brand-ink', brand.number);
			head.appendChild(el(opts.headingTag || 'h3', 'tt-rs-letterhead-title', data.name || data.team_name || ''));
			var meta = [data.subject === 'player' ? data.team_name : '', data.age_group, data.head_coach]
				.filter(function (s) { return !!s; }).join(' · ');
			if (meta) head.appendChild(el('p', 'tt-rs-letterhead-meta', meta));
			if (data.from && data.to) head.appendChild(el('p', 'tt-rs-letterhead-meta', fmt(t.periodRange, day(data.from), day(data.to))));
			box.appendChild(head);
		},
		key_figures: function (box, data, opts) {
			var t = opts.i18n || {};
			var list = el('ul', 'tt-rs-kpis');
			(Array.isArray(data.figures) ? data.figures : []).forEach(function (f) {
				var li = el('li', 'tt-rs-kpi');
				li.appendChild(el('span', 'tt-rs-kpi-value', figureValue(f)));
				li.appendChild(el('span', 'tt-rs-kpi-label', f.label || ''));
				if (f.delta != null) {
					li.appendChild(el('span', 'tt-rs-kpi-delta', fmt(t.sincePrevious, signed(f.delta) + (f.delta_unit ? ' ' + f.delta_unit : ''))));
				}
				list.appendChild(li);
			});
			box.appendChild(list);
		},
		heading: function (box, data, opts) {
			var t = opts.i18n || {};
			if (data.heading) box.appendChild(el(opts.headingTag || 'h3', 'tt-rs-heading', data.heading));
			else if (t.emptyText) box.appendChild(el('p', 'tt-rs-block-msg', t.emptyText));
		},
		text: function (box, data, opts) {
			var t = opts.i18n || {};
			if (data.heading) box.appendChild(el(opts.headingTag || 'h3', 'tt-rs-heading', data.heading));
			if (data.text) box.appendChild(el('p', 'tt-rs-text', data.text));
			if (!data.heading && !data.text && t.emptyText) box.appendChild(el('p', 'tt-rs-block-msg', t.emptyText));
		},
		page_break: function (box, data, opts) {
			var t = opts.i18n || {};
			box.classList.add('tt-rs-break-body');
			if (t.pageBreakNote) box.appendChild(el('p', 'tt-rs-block-msg', t.pageBreakNote));
		}
	};

	function body(d, opts) {
		opts = opts || {};
		var t = opts.i18n || {};
		var box = el('div', 'tt-rs-card-body');
		if (!d) {
			box.appendChild(el('p', 'tt-rs-block-msg', t.loading));
			return box;
		}
		if (d.error) {
			box.classList.add('is-off');
			// A field the block names is gone (#4860): say so, in the
			// server's words, to the builder and the reader alike.
			var missing = d.error.code === 'unknown_dimension';
			if (missing) box.classList.add('is-missing-field');
			box.appendChild(el('p', 'tt-rs-block-msg', missing ? d.error.message : (opts.errorText || d.error.message)));
			return box;
		}
		var data = d.data || {};
		if (Object.prototype.hasOwnProperty.call(RENDERERS, d.key)) {
			RENDERERS[d.key](box, data, opts);
			return box;
		}
		if (data.value !== undefined && !Array.isArray(data.rows)) {
			var fig = el('p', 'tt-rs-figure');
			fig.appendChild(el('span', 'tt-rs-figure-value', data.value == null ? '–' : String(data.value) + (data.unit === 'percent' ? '%' : '')));
			fig.appendChild(el('span', 'tt-rs-figure-label', data.measure_label || ''));
			box.appendChild(fig);
			target(box, data, t);
			return box;
		}
		var rows = Array.isArray(data.rows) ? data.rows : [];
		if (!rows.length) {
			var ctx = opts.context || {};
			// A flags block with nobody flagged is an answer, not missing data (#4572).
			var none = data.threshold != null && t.noneFlagged ? t.noneFlagged : t.nothing;
			box.appendChild(el('p', 'tt-rs-block-msg', fmt(none, day(ctx.from), day(ctx.to))));
			attendanceNotes(box, data, t);
			return box;
		}
		var cols = Object.keys(rows[0]).filter(function (k) { return k !== 'player_id' && k !== 'key'; });
		var wrap = el('div', 'tt-rs-table-wrap');
		var table = el('table', 'tt-rs-table');
		var thead = el('thead');
		var hr = el('tr');
		cols.forEach(function (c) { hr.appendChild(el('th', null, column(c, d, opts))); });
		thead.appendChild(hr);
		table.appendChild(thead);
		var tbody = el('tbody');
		rows.forEach(function (r) {
			var tr = el('tr');
			cols.forEach(function (c) { tr.appendChild(el('td', null, cell(c, r[c]))); });
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		wrap.appendChild(table);
		box.appendChild(wrap);
		if (data.truncated) box.appendChild(el('p', 'tt-rs-block-msg', t.truncated));
		target(box, data, t);
		attendanceNotes(box, data, t);
		return box;
	}

	/** Blocks that are their own heading, so a reader sees no block name above them. */
	var UNTITLED = ['letterhead', 'heading', 'text', 'page_break'];
	function untitled(key) { return UNTITLED.indexOf(key) !== -1; }

	/** A cell's text: a date column reads as a date (#4572). */
	function cell(c, v) {
		if (v == null) return '–';
		if (/(^|_)date$/.test(c)) return day(String(v));
		return String(v);
	}

	/** The academy's minutes target under a playing-time block (#4572). */
	function target(box, data, t) {
		if (data.target_pct == null || !t.target) return;
		box.appendChild(el('p', 'tt-rs-block-msg', fmt(t.target, data.target_pct)));
	}

	/** The squad's attendance, or the flag thresholds, under an attendance block (#4572). */
	function attendanceNotes(box, data, t) {
		if (data.team_avg_pct != null && t.squadAttendance) {
			box.appendChild(el('p', 'tt-rs-block-msg', fmt(t.squadAttendance, data.team_avg_pct)));
		}
		if (data.threshold != null && t.flagThresholds) {
			box.appendChild(el('p', 'tt-rs-block-msg', fmt(t.flagThresholds, data.threshold, data.late_threshold)));
		}
	}

	window.TT.ReportBlocks = { body: body, fmt: fmt, day: day, untitled: untitled };
})();
