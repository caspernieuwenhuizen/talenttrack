/**
 * TT.PdpPreparation (#4979, epic #4975) — the player's stepped preparation
 * of a PDP conversation form, in My PDP.
 *
 * One step per section, then the strongest quality. Every score and answer
 * autosaves through `TT.FormAutosave` (save model A) to
 * `PATCH …/form/player`. "Ready" is its own control: it flushes the pending
 * save, then `PUT …/form/player/preparation`. "Change it after all" is the
 * `DELETE` of that resource. Both reload the page, which draws the new state
 * from the server.
 *
 * Without this script every step shows at once and the scores still post
 * nowhere, which is why the server keeps the window and the lock: the
 * client never decides either.
 */
(function () {
	'use strict';

	var cfg  = window.TT_PdpPreparation || {};
	var i18n = cfg.i18n || {};

	function t(key, fallback) {
		return i18n[key] || fallback;
	}

	function restBase() {
		return (window.TT && TT.rest_url) ? TT.rest_url : '/wp-json/talenttrack/v1/';
	}

	function request(method, path) {
		return fetch(restBase() + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'X-WP-Nonce': (window.TT && TT.rest_nonce) || '' }
		}).then(function (r) {
			return r.json().catch(function () { return null; }).then(function (json) {
				return { ok: r.ok, json: json };
			});
		});
	}

	function errorText(res, fallback) {
		var err = res && res.json && res.json.errors && res.json.errors[0];
		return (err && err.message) ? err.message : fallback;
	}

	function say(root, text) {
		var msg = root.querySelector('.tt-form-msg');
		if (msg) msg.textContent = text;
	}

	/** Wait for the autosave to settle: a save queued behind one in flight. */
	function settled(saver) {
		return new Promise(function (resolve) {
			var tries = 0;
			(function check() {
				if (!saver.isPending() || tries++ > 40) { resolve(); return; }
				setTimeout(check, 150);
			}());
		});
	}

	function wireForm(form) {
		var steps   = Array.prototype.slice.call(form.querySelectorAll('[data-tt-prep-step]'));
		var marks   = Array.prototype.slice.call(form.querySelectorAll('[data-tt-prep-goto]'));
		var back    = form.querySelector('[data-tt-prep-back]');
		var next    = form.querySelector('[data-tt-prep-next]');
		var done    = form.querySelector('[data-tt-prep-done]');
		var labels  = marks.map(function (m) {
			var l = m.querySelector('.tt-pdp-prep__plabel');
			return l ? l.textContent : '';
		});
		var current = 0;

		var saver = (window.TT && TT.FormAutosave) ? TT.FormAutosave.attach(form, {
			onError: function (err) {
				// 409: outside the window, locked, or signed off meanwhile.
				// Retrying cannot help; say why and stop.
				if (err && err.status === 409) {
					saver.halt(t('closed', 'Your preparation can no longer be saved. Reload the page to see why.'));
				}
			}
		}) : null;

		form.classList.add('tt-pdp-prep__form--stepped');

		function show(index, focus) {
			current = Math.max(0, Math.min(steps.length - 1, index));
			steps.forEach(function (s, i) { s.hidden = i !== current; });
			marks.forEach(function (m, i) {
				if (i === current) m.setAttribute('aria-current', 'step');
				else m.removeAttribute('aria-current');
				m.classList.toggle('is-done', i < current);
			});
			var last = current === steps.length - 1;
			if (back) back.hidden = current === 0;
			if (next) {
				next.hidden = last;
				next.textContent = String(t('next', 'Next: %s')).replace('%s', labels[current + 1] || '');
			}
			if (done) done.hidden = !last;
			if (focus) {
				var legend = steps[current].querySelector('legend');
				if (legend) {
					legend.setAttribute('tabindex', '-1');
					legend.focus();
				}
				steps[current].scrollIntoView({ block: 'start', behavior: 'auto' });
			}
		}

		marks.forEach(function (m, i) {
			m.addEventListener('click', function () { show(i, true); });
		});
		if (back) back.addEventListener('click', function () { show(current - 1, true); });
		if (next) next.addEventListener('click', function () { show(current + 1, true); });

		if (done) {
			done.addEventListener('click', function () {
				done.disabled = true;
				say(form, t('saving', 'Saving your preparation…'));
				var flush = saver ? saver.saveNow().then(function () { return settled(saver); }) : Promise.resolve();
				flush.then(function () {
					return request('PUT', String(form.getAttribute('data-prepared-path') || ''));
				}).then(function (res) {
					if (res.ok) { window.location.reload(); return; }
					done.disabled = false;
					say(form, errorText(res, t('failed', 'Your preparation could not be marked ready. Try again.')));
				}, function () {
					done.disabled = false;
					say(form, t('failed', 'Your preparation could not be marked ready. Try again.'));
				});
			});
		}

		show(0, false);
	}

	function wireReopen(button) {
		button.addEventListener('click', function () {
			var root = button.closest('.tt-pdp-prep__ready') || button.parentNode;
			button.disabled = true;
			request('DELETE', String(button.getAttribute('data-tt-prep-reopen') || '')).then(function (res) {
				if (res.ok) { window.location.reload(); return; }
				button.disabled = false;
				say(root, errorText(res, t('reopenErr', 'Your preparation could not be reopened. Try again.')));
			}, function () {
				button.disabled = false;
				say(root, t('reopenErr', 'Your preparation could not be reopened. Try again.'));
			});
		});
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-tt-pdp-prep]'), wireForm);
		Array.prototype.forEach.call(document.querySelectorAll('[data-tt-prep-reopen]'), wireReopen);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
