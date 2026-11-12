/**
 * TT.PdpCoachForm (#4980, epic #4975) — the trainer's side of a PDP
 * conversation form, on the conversation screen.
 *
 * Every score, note, answer and development point autosaves through
 * `TT.FormAutosave` (save model A) to `PATCH …/form/coach`. Each save answers
 * with the form as the trainer reads it, which says what sign-off still
 * needs; the list under the button and the button itself follow it.
 *
 * Sign-off is its own control (CLAUDE.md §6): confirm, then `saver.commit()`,
 * which flushes the form and sends `POST …/signoff` on the same stream. A
 * refusal is the server's sentence under the button; it leaves undo and
 * autosaving alone, so a stale sign-off never freezes the form.
 */
(function () {
	'use strict';

	var cfg  = window.TT_PdpCoachForm || {};
	var i18n = cfg.i18n || {};

	function t(key, fallback) {
		return i18n[key] || fallback;
	}

	function restBase() {
		return (window.TT && TT.rest_url) ? TT.rest_url : '/wp-json/talenttrack/v1/';
	}

	function wire(form) {
		// A read-only form (signed off, or a later talk) has no save state.
		if (!form.querySelector('[data-tt-save-state]') || !window.TT || !TT.FormAutosave) return;

		var box     = document.querySelector('[data-tt-coach-signoff-box]');
		var button  = box ? box.querySelector('[data-tt-coach-signoff]') : null;
		var listBox = box ? box.querySelector('[data-tt-coach-missing]') : null;
		var msg     = box ? box.querySelector('.tt-form-msg') : null;

		function say(text) {
			if (msg) msg.textContent = text || '';
		}

		function showMissing(missing) {
			if (!button || !listBox || !Array.isArray(missing)) return;
			var ul = listBox.querySelector('ul');
			if (ul) {
				ul.textContent = '';
				missing.forEach(function (m) {
					var li = document.createElement('li');
					li.textContent = (m && m.label) ? m.label : '';
					ul.appendChild(li);
				});
			}
			listBox.hidden = missing.length === 0;
			button.disabled = missing.length > 0;
		}

		var saver = TT.FormAutosave.attach(form, {
			onSaved: function (resp) {
				var state = resp && resp.data && resp.data.state;
				if (state) showMissing(state.missing);
			},
			onError: function (err) {
				// An autosave the server refuses for good (signed off
				// meanwhile, a later talk, the form switched): say its reason
				// and stop writing.
				if (err && err.status === 409) saver.halt(err.reason || t('closed', 'The form can no longer be saved. Reload the page to see why.'));
			}
		});
		if (!saver || !button) return;

		button.addEventListener('click', function () {
			if (button.disabled) return;
			if (!window.confirm(t('confirm', 'Finish and sign this conversation? This cannot be undone.'))) return;

			button.disabled = true;
			say(t('signing', 'Signing off…'));

			saver.commit({
				method: 'POST',
				url: restBase() + String(form.getAttribute('data-signoff-path') || ''),
				body: null
			}).then(function (res) {
				if (res.ok) { window.location.reload(); return; }
				button.disabled = false;
				if (res.code === 'not_saved') {
					say(t('unsaved', 'Your last change has not been saved yet. Check your connection and try again.'));
					return;
				}
				if (res.code === 'missing_fields' && Array.isArray(res.details.missing)) showMissing(res.details.missing);
				// The halt text, or the server's reason: one sentence, the
				// same one the save state shows when the form is halted.
				say(res.message || t('failed', 'The conversation could not be signed off. Try again.'));
			});
		});
	}

	/**
	 * #5081 — the sections as folds: open all / close all, a section that
	 * opens itself when it holds the focused field, and each count line
	 * ("M of N scored") following the scores. Read-only forms get the folds
	 * too, so this does not wait for a save state.
	 */
	function folds(form) {
		var sections = form.querySelectorAll('[data-tt-pdp-section]');

		Array.prototype.forEach.call(form.querySelectorAll('[data-tt-pdp-folds]'), function (button) {
			button.addEventListener('click', function () {
				var open = button.getAttribute('data-tt-pdp-folds') === 'open';
				Array.prototype.forEach.call(sections, function (d) { d.open = open; });
			});
		});

		form.addEventListener('focusin', function (e) {
			var target = e.target;
			if (!target || target.tagName === 'SUMMARY') return;
			var d = target.closest ? target.closest('[data-tt-pdp-section]') : null;
			if (d && !d.open) d.open = true;
		});

		function recount() {
			Array.prototype.forEach.call(sections, function (d) {
				var line = d.querySelector('[data-tt-pdp-scored]');
				if (!line) return;
				var scored = 0;
				Array.prototype.forEach.call(d.querySelectorAll('[data-tt-rating-value]'), function (h) {
					if (h.value !== '') scored++;
				});
				var total = line.getAttribute('data-total') || '0';
				line.textContent = t('scored', '%1$d of %2$d scored')
					.replace('%1$d', String(scored))
					.replace('%2$d', total);
			});

			// A text the trainer agrees: filled in or not.
			Array.prototype.forEach.call(form.querySelectorAll('[data-tt-pdp-filled]'), function (line) {
				var d    = line.closest('[data-tt-pdp-section]');
				var area = d ? d.querySelector('textarea[name^="answers["]') : null;
				if (!area) return;
				line.textContent = area.value.trim() !== '' ? t('filled', 'Filled in') : t('unfilled', 'Not filled in yet');
			});

			// The SMART points: empty until action, help and deadline are set.
			Array.prototype.forEach.call(form.querySelectorAll('[data-tt-pdp-goals-empty]'), function (line) {
				var d = line.closest('[data-tt-pdp-section]');
				if (!d) return;
				var empty = 0;
				Array.prototype.forEach.call(d.querySelectorAll('.tt-pdp-coachform__goal'), function (goal) {
					var parts = goal.querySelectorAll('[name$="[action]"], [name$="[support]"], [name$="[due_date]"]');
					var full  = Array.prototype.every.call(parts, function (el) { return String(el.value).trim() !== ''; });
					if (!full) empty++;
				});
				line.textContent = empty === 0 ? t('allFull', 'all filled in') : t('empty', '%d still empty').replace('%d', String(empty));
			});
		}
		form.addEventListener('change', recount);
		form.addEventListener('input', recount);
		form.addEventListener('tt:form-remounted', recount);
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-tt-pdp-coachform]'), function (form) {
			folds(form);
			wire(form);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
