/**
 * TT.Autosave (#3004, epic #2881) — one debounce, one save state, one error
 * path, for every surface that saves as you work.
 *
 * Four surfaces autosave today and each grew its own loop: match prep, the
 * attendance grid, the minutes grid and the ratings grid. They debounce on
 * different intervals, disagree about what "saved" is called, and handle a
 * failed request three different ways. Epic #2881 adds five more autosaving
 * surfaces plus undo, so the choice is one component or nine copies.
 *
 * This is the component. It owns:
 *
 *   - the debounce, so a coach typing does not fire a request per keystroke
 *   - **in-flight coalescing** — see below, this is the part the copies got
 *     wrong
 *   - the save state and its words, so a coach reads the same sentence
 *     wherever they are
 *   - the error path, including keeping the edit in the field
 *
 * It owns nothing about a particular surface. The caller says how to
 * serialise itself and where to send that; everything else is here.
 *
 * ## In-flight coalescing, and why it matters
 *
 * The existing loops count requests (`savingCount++`) and let them overlap.
 * Two saves in flight over the same record race, and the one that arrives
 * second wins regardless of which was composed second — so a fast typist can
 * watch a character disappear.
 *
 * Here there is never more than one request in the air. A change made while
 * one is running sets a flag; when the response lands, the flag fires exactly
 * one more save carrying the *current* state. The last write always reflects
 * the last edit, and the number of requests falls out of a burst of typing
 * rather than climbing with it.
 *
 * ## Usage
 *
 *     var saver = TT.Autosave.create({
 *         stateEl:   document.querySelector('[data-tt-save-state]'),
 *         nonce:     cfg.rest_nonce,
 *         delay:     250,
 *         serialise: function () {
 *             return { method: 'PUT', url: base + id, body: payload() };
 *         }
 *     });
 *
 *     input.addEventListener('input', function () { saver.change(); });
 *     button.addEventListener('click', function () { saver.saveNow(); });
 *
 * `serialise()` returning null means "nothing to send" — the surface is not
 * ready, or there is genuinely no change. That is not an error and not a
 * failure state.
 */
(function () {
	'use strict';

	window.TT = window.TT || {};
	if (window.TT.Autosave) return;

	var DEFAULT_DELAY = 250;

	/**
	 * The four states, and their words. Fixed here so nine surfaces cannot
	 * each invent a synonym — "Saved", "All changes saved.", "Up to date" and
	 * "✓" were four ways of saying one thing across four files.
	 *
	 * `dirty` is the state between a keystroke and the debounce firing. The
	 * epic named three states; this is the fourth, and it earns its place —
	 * without it a coach who stops typing sees "All changes saved" for a
	 * quarter second before the request that will save those changes has even
	 * left, which is the one moment the label must not lie.
	 */
	function words(i18n) {
		var t = i18n || {};
		return {
			dirty:  t.dirty  || 'Unsaved changes…',
			saving: t.saving || 'Saving…',
			saved:  t.saved  || 'All changes saved',
			error:  t.error  || 'Save failed — retry'
		};
	}

	function Autosave(opts) {
		this.opts     = opts || {};
		this.stateEl  = this.opts.stateEl || null;
		this.delay    = typeof this.opts.delay === 'number' ? this.opts.delay : DEFAULT_DELAY;
		this.words    = words(this.opts.i18n);

		this.timer    = null;
		this.inFlight = false;
		this.queued   = false;
		this.dirty    = false;
		this.failed   = false;

		this._prepareStateEl();
		this.render();
	}

	/**
	 * The save state is a status region, not an alert. `polite` waits for a
	 * pause in what the screen reader is already saying; `assertive` would
	 * interrupt a coach mid-sentence every time a debounce fires, which on a
	 * grid is every few seconds.
	 */
	Autosave.prototype._prepareStateEl = function () {
		if (!this.stateEl) return;
		if (!this.stateEl.getAttribute('aria-live')) {
			this.stateEl.setAttribute('aria-live', 'polite');
		}
		this.stateEl.setAttribute('role', 'status');
	};

	/** Something changed. Debounce, then save. */
	Autosave.prototype.change = function (delay) {
		this.dirty  = true;
		this.failed = false;
		this.render();

		if (this.timer) clearTimeout(this.timer);
		var self = this;
		var wait = typeof delay === 'number' ? delay : this.delay;
		this.timer = setTimeout(function () {
			self.timer = null;
			self.saveNow();
		}, wait);
	};

	/**
	 * Save immediately, skipping the debounce. For discrete actions — picking
	 * a slot, toggling a flag — where the coach expects the state to settle
	 * the moment they act rather than a beat later.
	 */
	Autosave.prototype.saveNow = function () {
		if (this.timer) { clearTimeout(this.timer); this.timer = null; }

		// One request at a time. A change made during a save is remembered
		// and fires once this one lands, carrying the state as it is *then* —
		// which is why this queues a flag rather than a payload.
		if (this.inFlight) { this.queued = true; return Promise.resolve(null); }

		var req = null;
		try {
			req = typeof this.opts.serialise === 'function' ? this.opts.serialise() : null;
		} catch (e) {
			req = null;
		}

		// Nothing to send is not a failure. A surface that is still loading,
		// or has genuinely nothing changed, should not flash an error.
		if (!req || !req.url) { this.dirty = false; this.render(); return Promise.resolve(null); }

		var self = this;
		this.inFlight = true;
		this.failed   = false;
		this.render();

		return fetch(req.url, {
			method: req.method || 'PUT',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-WP-Nonce': this.opts.nonce || ''
			},
			body: req.body != null ? JSON.stringify(req.body) : null
		}).then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.status === 204 ? null : r.json();
		}).then(function (resp) {
			self.inFlight = false;
			self.dirty    = false;
			self.failed   = false;

			if (typeof self.opts.onSaved === 'function') self.opts.onSaved(resp);

			if (self.queued) { self.queued = false; self.dirty = true; self.render(); return self.saveNow(); }

			self.render();
			return resp;
		})['catch'](function (err) {
			self.inFlight = false;
			self.queued   = false;

			// The edit stays in the field and the record stays dirty. A
			// failed save that quietly reset the input would throw away the
			// work it just failed to store, which is the one outcome worse
			// than the failure.
			self.dirty  = true;
			self.failed = true;
			self.render();

			if (typeof self.opts.onError === 'function') self.opts.onError(err);
			return null;
		});
	};

	/**
	 * A one-off write on a different endpoint, shown in the same save state.
	 *
	 * Match prep needs this: picking a player for a role is a `PUT …/role`
	 * rather than a slice of the full-record payload, but a coach watching
	 * the status line should not be able to tell. Without it the surface
	 * would keep a second, private save loop, which is what this component
	 * exists to prevent.
	 *
	 * Queued behind whatever is in flight, so a role write and a full save
	 * cannot race each other on the same record.
	 */
	Autosave.prototype.send = function (req) {
		if (!req || !req.url) return Promise.resolve(null);

		var self = this;
		if (this.inFlight) {
			// Wait for the current write, then take our turn. One level deep
			// is enough: `saveNow()` drains its own queue before resolving.
			return new Promise(function (resolve) {
				setTimeout(function () { resolve(self.send(req)); }, 40);
			});
		}

		this.inFlight = true;
		this.failed   = false;
		this.render();

		return fetch(req.url, {
			method: req.method || 'PUT',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-WP-Nonce': this.opts.nonce || ''
			},
			body: req.body != null ? JSON.stringify(req.body) : null
		}).then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.status === 204 ? null : r.json();
		}).then(function (resp) {
			self.inFlight = false;
			self.dirty    = false;
			self.failed   = false;
			self.render();
			return resp;
		})['catch'](function (err) {
			self.inFlight = false;
			self.dirty    = true;
			self.failed   = true;
			self.render();
			if (typeof self.opts.onError === 'function') self.opts.onError(err);
			return null;
		});
	};

	/** Are there edits not yet stored? Used by beforeunload guards. */
	Autosave.prototype.isPending = function () {
		return this.dirty || this.inFlight || this.queued;
	};

	Autosave.prototype.render = function () {
		var el = this.stateEl;
		if (!el) return;

		el.classList.remove('is-dirty', 'is-saving', 'is-saved', 'is-error');

		if (this.failed) {
			el.classList.add('is-error');
			el.textContent = this.words.error;
			return;
		}
		if (this.inFlight) {
			el.classList.add('is-saving');
			el.textContent = this.words.saving;
			return;
		}
		if (this.dirty) {
			el.classList.add('is-dirty');
			el.textContent = this.words.dirty;
			return;
		}
		el.classList.add('is-saved');
		el.textContent = this.words.saved;
	};

	window.TT.Autosave = {
		create: function (opts) { return new Autosave(opts); }
	};
})();
