/**
 * TT.ReportBlocks (#4591) — how a Report Studio block's data is drawn, for
 * the builder's canvas and the reader's view alike, so both show a block the
 * same way.
 *
 *   TT.ReportBlocks.body(block, {
 *       i18n:    { loading, nothing, truncated, value },
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
			box.appendChild(el('p', 'tt-rs-block-msg', opts.errorText || d.error.message));
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
			var ctx = opts.context || {};
			box.appendChild(el('p', 'tt-rs-block-msg', fmt(t.nothing, day(ctx.from), day(ctx.to))));
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
			cols.forEach(function (c) { tr.appendChild(el('td', null, r[c] == null ? '–' : String(r[c]))); });
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		wrap.appendChild(table);
		box.appendChild(wrap);
		if (data.truncated) box.appendChild(el('p', 'tt-rs-block-msg', t.truncated));
		return box;
	}

	window.TT.ReportBlocks = { body: body, fmt: fmt, day: day };
})();
