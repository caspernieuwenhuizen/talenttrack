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
 *
 * ## Undo (#3005, epic #2881)
 *
 * Autosave commits a mis-tap the moment it debounces, so the surface owes a
 * coach a way back out of the slip they noticed straight away. That is what
 * `undo()` is: **one level**, the last committed change, not a history stack.
 *
 * The mechanism is a snapshot of the payload, not a diff. Every successful
 * full save records the body it sent; the body it *replaced* becomes the undo
 * target. Undoing hands that older body back to the surface through
 * `apply()`, then pushes it through the same save path — so an undo is itself
 * saved, and survives a reload.
 *
 *     var saver = TT.Autosave.create({
 *         stateEl:   …,
 *         undoEl:    document.querySelector('[data-tt-save-undo]'),
 *         serialise: function () { return { … , body: payload() }; },
 *         apply:     function (body) { mountPayload(body); renderAll(); }
 *     });
 *     saver.seed(payload());   // the state as loaded, so the first edit is undoable
 *
 * `apply()` is what makes undo available: without it the component has
 * somewhere to send the old value but nowhere to put it, so the control is
 * never offered.
 *
 * When the control is *not* offered, deliberately:
 *
 *   - while anything is unsaved, saving or failed. Undo is only ever shown on
 *     a settled record, which is also why it cannot race a save in flight.
 *   - after `send()`. That is a write on another endpoint carrying state the
 *     full payload does not describe (match prep's role picks), so the
 *     snapshot no longer represents the coach's last change — offering it
 *     would undo something older than the thing they just did.
 *   - after an undo commits. One level means one level; the way back to the
 *     value just reverted is to make the edit again.
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
			dirty:     t.dirty     || 'Unsaved changes…',
			saving:    t.saving    || 'Saving…',
			saved:     t.saved     || 'All changes saved',
			error:     t.error     || 'Save failed — retry',
			undoError: t.undoError || 'Undo failed — nothing changed'
		};
	}

	/**
	 * Snapshot a payload. A save body is JSON on its way out anyway, so this
	 * cannot meet anything it fails to copy — and a structural copy is the
	 * point: the surface keeps mutating the object the payload was built from.
	 */
	function snapshot(body) {
		if (body == null) return null;
		try {
			return JSON.parse(JSON.stringify(body));
		} catch (e) {
			return null;
		}
	}

	function Autosave(opts) {
		this.opts     = opts || {};
		this.stateEl  = this.opts.stateEl || null;
		this.undoEl   = this.opts.undoEl || null;
		this.delay    = typeof this.opts.delay === 'number' ? this.opts.delay : DEFAULT_DELAY;
		this.words    = words(this.opts.i18n);

		this.timer    = null;
		this.inFlight = false;
		this.queued   = false;
		this.dirty    = false;
		this.failed   = false;

		// The last body this surface committed, and the one it replaced.
		// `undoTarget` is what `undo()` sends; null means there is nothing
		// safe to offer.
		this.committed  = null;
		this.undoTarget = null;
		this.undoFailed = false;
		this._undoing   = false;

		this._prepareStateEl();
		this._prepareUndoEl();
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

	/**
	 * A real `<button>`, so Enter and Space come free and the control is in
	 * the tab order without any of it being re-implemented here. Hidden until
	 * there is something to undo.
	 */
	Autosave.prototype._prepareUndoEl = function () {
		if (!this.undoEl) return;
		var self = this;
		this.undoEl.hidden = true;
		this.undoEl.addEventListener('click', function (e) {
			e.preventDefault();
			self.undo();
		});
	};

	/**
	 * The state as loaded, before anyone touched it. Without it the first
	 * autosave of a session has nothing to fall back to and undo stays hidden
	 * until the second — which is the one mis-tap most worth catching.
	 */
	Autosave.prototype.seed = function (body) {
		this.committed = snapshot(body);
	};

	/** Something changed. Debounce, then save. */
	Autosave.prototype.change = function (delay) {
		this.dirty      = true;
		this.failed     = false;
		this.undoFailed = false;
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

			// What this save replaced becomes the way back. An undo's own
			// save is excluded — reverting a revert is a redo, and this is
			// one level by design.
			self.undoTarget = self._undoing ? null : self.committed;
			self.committed  = snapshot(req.body);

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

		// This writes state the full payload does not carry, so the snapshot
		// stops describing the coach's last change. Offering undo now would
		// revert something older than what they just did.
		this.undoTarget = null;
		this.undoFailed = false;

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

	/**
	 * Is there a change that can still be taken back? True only on a settled
	 * record with a snapshot behind it and somewhere to put it.
	 */
	Autosave.prototype.canUndo = function () {
		return !!this.undoTarget
			&& typeof this.opts.apply === 'function'
			&& !this.inFlight
			&& !this.queued
			&& !this.dirty
			&& !this.failed
			&& !this.timer;
	};

	/**
	 * Take back the last committed change.
	 *
	 * The old body goes back to the surface first, then through the save
	 * path, because a revert that only repainted the screen would be a lie
	 * the next reload exposes.
	 *
	 * If that save fails, the surface is put back to the value it had before
	 * the undo. That is the un-undone value, and it is the truth — the server
	 * still holds it. Showing the reverted one would leave a coach believing
	 * a change was taken back when it was not.
	 */
	Autosave.prototype.undo = function () {
		if (!this.canUndo()) return Promise.resolve(null);

		var self    = this;
		var target  = this.undoTarget;
		var before  = this.committed;

		this.undoTarget = null;
		this.undoFailed = false;
		this._undoing   = true;
		this.dirty      = true;
		this.render();

		this.opts.apply(target);

		return this.saveNow().then(function (resp) {
			self._undoing = false;

			if (self.failed) {
				self.opts.apply(before);
				self.committed  = before;
				self.dirty      = false;
				self.undoFailed = true;
				self.render();
			}

			return resp;
		});
	};

	/** Are there edits not yet stored? Used by beforeunload guards. */
	Autosave.prototype.isPending = function () {
		return this.dirty || this.inFlight || this.queued;
	};

	Autosave.prototype.render = function () {
		this._renderState();
		this._renderUndo();
	};

	/**
	 * Shown only in the settled state — see `canUndo()`. That is the rule the
	 * component's contract makes: undo is an offer about a record that is
	 * currently saved, so it cannot appear beside "Saving…" and cannot race
	 * the request that word refers to.
	 */
	Autosave.prototype._renderUndo = function () {
		if (!this.undoEl) return;
		this.undoEl.hidden = !this.canUndo();
	};

	Autosave.prototype._renderState = function () {
		var el = this.stateEl;
		if (!el) return;

		el.classList.remove('is-dirty', 'is-saving', 'is-saved', 'is-error');

		if (this.undoFailed) {
			el.classList.add('is-error');
			el.textContent = this.words.undoError;
			return;
		}
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
