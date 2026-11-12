/**
 * TT.PdpConversationMode (#5082) — a PDP talk on the screen in the room.
 *
 * The page is complete without this file: every step is in the DOM, the
 * step pills are RecordSpine panel tabs, and the close control is a link.
 * This adds, in order:
 *
 *   - the close control moved into the header, beside the save state;
 *   - "Previous" / "Next: <step>" driving the same tabs;
 *   - scoring through `TT.FormAutosave` to `PATCH …/form/coach`. The form
 *     carries only the scores, so it attaches **without** the page's
 *     `storageKey` and offers undo only (save model A, docs/save-model.md);
 *   - after each save, the overview and the "Bespreken" flags re-read from
 *     `GET …/form/display`. Nothing is worked out here;
 *   - Escape and the close control store a pending score first, then go
 *     back to the talk's page. Escape is left alone while a dialog is open
 *     or a rating control has focus.
 */
(function () {
	'use strict';

	var cfg  = window.TT_PdpConversationMode || {};
	var i18n = cfg.i18n || {};

	function restBase() {
		return (window.TT && TT.rest_url) ? TT.rest_url : '/wp-json/talenttrack/v1/';
	}

	function tabs() {
		var strip = document.querySelector('.tt-pdp-mode [data-tt-spine-tabs]');
		return strip ? Array.prototype.slice.call(strip.querySelectorAll('[role="tab"]')) : [];
	}

	function currentIndex(list) {
		for (var i = 0; i < list.length; i++) {
			if (list[i].getAttribute('aria-selected') === 'true') return i;
		}
		return 0;
	}

	function steps(form) {
		var prev = form.querySelector('[data-tt-pdp-mode-prev]');
		var next = form.querySelector('[data-tt-pdp-mode-next]');
		var body = form.querySelector('.tt-pdp-mode__body');

		function sync() {
			var list = tabs();
			var i    = currentIndex(list);
			if (prev) prev.disabled = i <= 0;
			if (next) {
				var after = list[i + 1];
				next.disabled = !after;
				next.textContent = after
					? String(i18n.next || 'Next: %s').replace('%s', after.textContent)
					: String(i18n.done || 'Last step');
			}
			// Each step scrolls inside its own area; a new step starts at its top.
			if (body) body.scrollTop = 0;
		}

		function go(delta) {
			var list   = tabs();
			var target = list[currentIndex(list) + delta];
			if (target) target.click();
		}

		if (prev) prev.addEventListener('click', function () { go(-1); });
		if (next) next.addEventListener('click', function () { go(1); });
		document.addEventListener('tt-spine-tab-change', sync);
		sync();
	}

	function formatAverage(value) {
		if (value === null || value === undefined) return '—';
		try {
			return new Intl.NumberFormat(document.documentElement.lang || undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value);
		} catch (e) {
			return String(value);
		}
	}

	/** Put the server's answer back on the overview and the flags. */
	function show(form, data) {
		if (!data || !Array.isArray(data.sections)) return;
		var ready = data.player_ready === true;
		data.sections.forEach(function (s) {
			var p = form.querySelector('[data-tt-pdp-mode-avg="' + s.key + ':player"]');
			var c = form.querySelector('[data-tt-pdp-mode-avg="' + s.key + ':coach"]');
			var d = form.querySelector('[data-tt-pdp-mode-discuss="' + s.key + '"]');
			if (p) p.textContent = ready ? formatAverage(s.player_average) : '—';
			if (c) c.textContent = formatAverage(s.coach_average);
			if (d) d.textContent = String(s.to_discuss || 0);
		});
		Object.keys(data.scores || {}).forEach(function (key) {
			var flag = form.querySelector('[data-tt-pdp-mode-flag="' + key + '"]');
			if (flag) flag.hidden = !data.scores[key].discuss;
		});
	}

	function refresh(form) {
		var path = form.getAttribute('data-display-path');
		if (!path || !window.fetch) return;
		fetch(restBase() + path, {
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'X-WP-Nonce': (window.TT && TT.rest_nonce) || '' }
		}).then(function (r) { return r.ok ? r.json() : null; })
			.then(function (json) { if (json) show(form, json.data || json); })
			['catch'](function () { /* the overview keeps its last numbers */ });
	}

	function wire(form) {
		var saver = null;
		if (form.querySelector('[data-tt-save-state]') && window.TT && TT.FormAutosave) {
			saver = TT.FormAutosave.attach(form, {
				// No storageKey: this form carries the scores only, so it must
				// never write, read or clear the page's sitting snapshot.
				storageKey: '',
				onSaved: function () { refresh(form); },
				onError: function (err) {
					if (TT.PdpCoachForm && typeof TT.PdpCoachForm.haltOnRefusal === 'function') {
						TT.PdpCoachForm.haltOnRefusal(saver, err, i18n.closed);
					}
				}
			});
		}

		var close = document.querySelector('[data-tt-presentation-close]');
		var slot  = form.querySelector('[data-tt-pdp-mode-close-slot]');
		if (close && slot) slot.appendChild(close);

		function leave() {
			if (!close) return;
			var href = close.getAttribute('href');
			var go   = function () { window.location.href = href; };
			if (saver && typeof saver.flush === 'function') saver.flush().then(go);
			else go();
		}

		if (close) {
			close.addEventListener('click', function (e) {
				e.preventDefault();
				leave();
			});
		}

		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape' || e.defaultPrevented) return;
			if (document.querySelector('dialog[open]')) return;
			var active = document.activeElement;
			if (active && active.closest && active.closest('.tt-rating-stars, .tt-rating-row, [role="radiogroup"]')) return;
			e.preventDefault();
			leave();
		});
	}

	function init() {
		var form = document.querySelector('[data-tt-pdp-mode]');
		if (!form) return;
		steps(form);
		wire(form);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
