/**
 * Report Studio snapshot notes (#5021) — the note under one block of a frozen
 * copy, saved with `PUT /report-snapshots/{uuid}/notes/{block}`. Explicit
 * Save: nothing is written until the coach saves, and Cancel is a link that
 * reopens the snapshot. After a save the page reloads, so the note prints
 * under its block in the same document the PDF prints.
 */
(function () {
	'use strict';

	var cfg = window.TTReportSnapshot;
	if (!cfg) return;
	var t = cfg.i18n || {};

	document.querySelectorAll('[data-tt-rv-note]').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var button = form.querySelector('button[type="submit"]');
			var error = form.querySelector('[data-tt-rv-note-error]');
			var field = form.querySelector('textarea');
			if (button) button.disabled = true;
			if (error) error.textContent = '';

			fetch(cfg.rest + encodeURIComponent(form.dataset.block || ''), {
				method: 'PUT',
				credentials: 'same-origin',
				headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify({ body: field ? field.value : '' })
			}).then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (json) {
					if (!r.ok) {
						var e0 = json && json.errors && json.errors[0] ? json.errors[0] : {};
						throw new Error(e0.message || t.error);
					}
					window.location.reload();
				});
			})['catch'](function (err) {
				if (error) error.textContent = err.message || t.error;
				if (button) button.disabled = false;
			});
		});
	});
})();
