/**
 * TT.PdpCoachForm (#4980, epic #4975) — the trainer's side of a PDP
 * conversation form, on the conversation screen.
 *
 * Every score, note, answer and development point autosaves through
 * `TT.FormAutosave` (save model A) to `PATCH …/form/coach`. Each save answers
 * with the form as the trainer reads it, which says what sign-off still
 * needs; the list under the button and the button itself follow it.
 *
 * Sign-off is its own control (CLAUDE.md §6): confirm, then flush the form
 * through the saver (`send()` queues behind a save on its way), then
 * `POST …/signoff`. A flush that did not store, or a halted saver, stops
 * there with the form on screen.
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
			if (msg) msg.textContent = text;
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
				if (err && err.status === 409) saver.halt(t('closed', 'The form can no longer be saved. Reload the page to see why.'));
			}
		});
		if (!saver || !button) return;

		button.addEventListener('click', function () {
			if (button.disabled) return;
			if (!window.confirm(t('confirm', 'Finish and sign this conversation? This cannot be undone.'))) return;

			function stay(text) {
				button.disabled = false;
				say(text);
			}
			function halted() {
				if (!saver.halted) return false;
				stay(t('closed', 'The form can no longer be saved. Reload the page to see why.'));
				return true;
			}
			if (halted()) return;

			button.disabled = true;
			say(t('signing', 'Signing off…'));

			saver.send({
				method: 'PATCH',
				url: restBase() + String(form.getAttribute('data-rest-path') || ''),
				body: TT.FormAutosave.body(form)
			}).then(function (saved) {
				if (halted()) return null;
				if (!saved || saver.isPending()) {
					stay(t('unsaved', 'Your last change has not been saved yet. Check your connection and try again.'));
					return null;
				}
				var state = saved.data && saved.data.state;
				if (state && Array.isArray(state.missing) && state.missing.length) {
					showMissing(state.missing);
					say('');
					return null;
				}
				return saver.send({
					method: 'POST',
					url: restBase() + String(form.getAttribute('data-signoff-path') || ''),
					body: null
				}).then(function (signed) {
					if (signed) { window.location.reload(); return; }
					if (halted()) return;
					stay(t('failed', 'The conversation could not be signed off. Try again.'));
				});
			}, function () {
				stay(t('failed', 'The conversation could not be signed off. Try again.'));
			});
		});
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-tt-pdp-coachform]'), wire);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
