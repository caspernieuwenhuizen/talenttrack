/**
 * TT.FormAutosave (#3008, epic #2881) — the bridge between an ordinary
 * TalentTrack form and `TT.Autosave`.
 *
 * `TT.Autosave` owns the debounce, the save state, the error path, undo and
 * revert. It knows nothing about forms: a caller says how to serialise
 * itself and where to send that. Four surfaces now need exactly the same
 * answer to those two questions — evaluations, PDP conversations, the
 * player's self-reflection and goals are all a `<form>` posting itself to a
 * REST path over PUT or PATCH — and the epic's whole point is that this is
 * written once rather than four times.
 *
 * ## Use, from PHP
 *
 *     <form class="tt-autosave-form"
 *           data-tt-autosave-form
 *           data-rest-path="evaluations/12"
 *           data-rest-method="PUT"
 *           data-autosave-key="evaluation:12">
 *
 * plus `SaveState::render()` somewhere inside it. That is the whole
 * integration; this file finds the form and wires it.
 *
 * `\TT\Shared\Frontend\Components\FormAutosave::formAttrs()` emits those
 * attributes so a view cannot spell one of them wrong.
 *
 * ## What it deliberately does not do
 *
 * **It never creates a record.** Every surface wired to it edits something
 * that already exists. Autosaving a create would spawn a row on the first
 * keystroke and leave a trail of empty evaluations behind every coach who
 * opened the form and thought better of it. Creation stays a deliberate
 * act — a wizard, or a Save button.
 *
 * **It does not autosave a commit.** A sign-off, a publish, a submit: those
 * are separate, confirmed actions outside the autosaving form. A checkbox
 * that locks a record forever must not fire because a debounce elapsed.
 *
 * ## The date-input rule
 *
 * `input` fires on every keystroke, including halfway through a date — a
 * coach typing 2026 into a date field passes through 0002 and 0020. Saving
 * those would write a real, wrong date into a player's record. Date-shaped
 * inputs therefore save on `change`, which fires only once the control
 * holds a complete value.
 */
(function () {
	'use strict';

	window.TT = window.TT || {};
	if (window.TT.FormAutosave) return;

	/** Controls whose intermediate values are not worth saving. */
	var DATE_TYPES = ['date', 'datetime-local', 'month', 'week', 'time'];

	function restBase() {
		return (window.TT && TT.rest_url) ? TT.rest_url : '/wp-json/talenttrack/v1/';
	}

	/**
	 * The form as the body it would submit. `TT.formToJSON` is public.js's
	 * own bracket expansion, exposed rather than reimplemented so an
	 * autosave and a submit cannot reach one endpoint with two shapes.
	 */
	function body(form) {
		return (typeof TT.formToJSON === 'function') ? TT.formToJSON(form) : {};
	}

	/**
	 * `players[12][marker]` -> payload.players['12'].marker. The inverse of
	 * the bracket expansion, so a value can be found again by the name of
	 * the control it came from.
	 */
	function lookup(payload, name) {
		var match = name.match(/^([^\[]+)((?:\[[^\]]*\])*)$/);
		if (!match) return undefined;

		var cursor = payload[match[1]];
		var keys   = [];
		match[2].replace(/\[([^\]]*)\]/g, function (_m, k) { keys.push(k); return ''; });

		for (var i = 0; i < keys.length; i++) {
			if (cursor == null || typeof cursor !== 'object') return undefined;
			cursor = cursor[keys[i]];
		}
		return (cursor === undefined || cursor === null) ? undefined : cursor;
	}

	/**
	 * Put a previously committed body back into the form. This is what
	 * makes undo and revert reach a form at all: the component has the old
	 * payload and needs somewhere to put it.
	 *
	 * Absence is meaningful rather than "skip". A rating the snapshot does
	 * not carry is a rating that was not chosen, and an undo that left it
	 * standing would restore three fields out of four. Disabled controls
	 * are exempt because they were never in the serialisation to begin
	 * with — a locked field is not something an undo has an opinion about.
	 */
	function mount(form, payload) {
		if (!payload) return;

		Array.prototype.forEach.call(form.elements, function (el) {
			if (!el.name || el.disabled) return;

			var value = lookup(payload, el.name);

			if (el.type === 'radio' || el.type === 'checkbox') {
				el.checked = value !== undefined && String(el.value) === String(value);
				return;
			}
			if (el.tagName === 'BUTTON') return;

			el.value = value === undefined ? '' : value;
		});

		// Enhanced controls — pickers, tally chips, counters — are drawn
		// from the plain inputs and have to be told the plain inputs moved.
		form.dispatchEvent(new CustomEvent('tt:form-remounted', { bubbles: true }));
	}

	function isDateish(el) {
		return el && el.type && DATE_TYPES.indexOf(el.type) !== -1;
	}

	/**
	 * Wire one form. Returns the saver so a surface can add its own
	 * deliberate commit beside it (match analysis's Mark as final), or null
	 * when the runtime cannot support autosave — in which case the caller's
	 * form is left exactly as the server rendered it.
	 *
	 * @param {HTMLFormElement} form
	 * @param {Object} opts  endpoint(): string — overrides the data-attribute
	 *                       path; onSaved, onError, delay, storageKey.
	 */
	function attach(form, opts) {
		if (!form || !window.TT || !TT.Autosave || typeof TT.formToJSON !== 'function') return null;

		opts = opts || {};

		var method = String(form.getAttribute('data-rest-method') || 'PUT').toUpperCase();
		var path   = String(form.getAttribute('data-rest-path') || '');
		if (!path && typeof opts.endpoint !== 'function') return null;

		var endpoint = typeof opts.endpoint === 'function'
			? opts.endpoint
			: function () { return restBase() + path; };

		var saver = TT.Autosave.create({
			stateEl:  form.querySelector('[data-tt-save-state]'),
			undoEl:   form.querySelector('[data-tt-save-undo]'),
			revertEl: form.querySelector('[data-tt-save-revert]'),
			storageKey: opts.storageKey || String(form.getAttribute('data-autosave-key') || ''),
			nonce:    (window.TT && TT.rest_nonce) || '',
			delay:    typeof opts.delay === 'number' ? opts.delay : 600,
			i18n:     (window.TT_Autosave && window.TT_Autosave.i18n) || {},
			serialise: function () {
				return { method: method, url: endpoint(), body: body(form) };
			},
			apply: function (payload) { mount(form, payload); },
			onSaved: opts.onSaved,
			onError: opts.onError
		});

		saver.seed(body(form));

		form.addEventListener('input', function (e) {
			// See the date-input rule in the file header.
			if (isDateish(e.target)) return;
			saver.change();
		});

		// Radios, selects, checkboxes and date controls settle the moment
		// they are used, so they skip the typing debounce.
		form.addEventListener('change', function () { saver.change(60); });

		// Nothing on an autosaving form submits, but a stray Enter in a
		// text input still tries to. Swallow it and let the save already
		// queued do the work.
		form.addEventListener('submit', function (e) { e.preventDefault(); });

		return saver;
	}

	function init() {
		Array.prototype.slice
			.call(document.querySelectorAll('[data-tt-autosave-form]'))
			.forEach(function (form) { attach(form); });
	}

	window.TT.FormAutosave = {
		attach: attach,
		mount:  mount,
		body:   body
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
