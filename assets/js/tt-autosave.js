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
 *
 * ## Revert the sitting (#3006, epic #2881)
 *
 * Undo answers the slip noticed straight away. This answers the one noticed
 * ten minutes later, still in the same sitting: **put it back to how it was
 * when I opened this**.
 *
 * The target is a snapshot of the record taken at `seed()` and kept in
 * `localStorage` under `storageKey`. Keeping it in the browser rather than on
 * the server is what makes it survive a reload — closing the tab by accident
 * does not lose the way back — and is also what bounds it honestly: a coach
 * who opens the same record on a laptop the next morning gets the saved
 * document with no revert offered. The sitting ended.
 *
 *     var saver = TT.Autosave.create({
 *         …,
 *         revertEl:   document.querySelector('[data-tt-save-revert]'),
 *         storageKey: 'match-prep:' + activityId,
 *         apply:      function (body) { mountPayload(body); renderAll(); }
 *     });
 *
 * This is **not** version history and must not grow into one: one snapshot,
 * per surface, per record, per device, for the length of a sitting. No
 * server-side copies, no per-field history, no restore-to-a-date.
 *
 * ### Storage discipline
 *
 * `localStorage` comes back empty in a private window, after cleared site
 * data and in a different browser, and the accessor itself throws in some
 * contexts. Every read and write here is wrapped, and a surface that cannot
 * snapshot degrades to *revert not available* — it still autosaves, still
 * renders, still offers undo. The snapshot is also size-bounded: a record too
 * large to store is the same "not available", never a half-written key or a
 * quota exception thrown into the save path.
 *
 * ## Halting (#3007, epic #2881)
 *
 * `halt(message)` is the terminal state, for the failure that retrying makes
 * worse. Match analysis uses it when the server answers 409: the record moved
 * underneath the surface, so every further autosave would overwrite somebody
 * else's work with a document composed against a different starting point.
 *
 * Nothing writes again afterwards — `change()`, `saveNow()` and `send()` all
 * return immediately, and undo and revert stop being offered, because both
 * would write. The edits stay in the fields; a reload is the way out, because
 * a reload is the only thing that puts the coach back on the version that
 * exists.
 *
 * `onError` receives an `Error` carrying `.status`, which is how a surface
 * tells a 409 from a 500 in the first place.
 */
(function () {
	'use strict';

	window.TT = window.TT || {};
	if (window.TT.Autosave) return;

	var DEFAULT_DELAY = 250;

	/**
	 * Namespaced so a stored snapshot cannot collide with anything else, and
	 * scoped to the logged-in user: academy laptops get shared, and a coach
	 * must never be offered a way back into somebody else's sitting.
	 */
	function storePrefix() {
		var user = (window.TT_Autosave && window.TT_Autosave.user) || '0';
		return 'tt.autosave.sitting.' + user + '.';
	}

	/**
	 * How long a sitting lasts. A snapshot older than this is discarded on
	 * read rather than offered: "how it was when I opened this" stops being
	 * true once the coach has gone home and come back, and restoring
	 * yesterday's state over today's work would be the opposite of the
	 * safety this control exists to provide.
	 */
	var SITTING_MS = 12 * 60 * 60 * 1000;

	/**
	 * Snapshot ceiling, in serialised characters. Roughly 200KB of UTF-16 —
	 * comfortably above any payload these surfaces build and comfortably
	 * below the ~5MB a browser gives the whole origin, so one large record
	 * cannot evict every other surface's snapshot or push the store to a
	 * quota error mid-save.
	 */
	var MAX_SNAPSHOT_CHARS = 200000;

	/**
	 * The three storage primitives. Every one of them assumes the store is
	 * absent, full, or throwing, because in a private window it is.
	 */
	function storeClear(key) {
		if (!key) return;
		try { window.localStorage.removeItem(storePrefix() + key); } catch (e) {}
	}

	function storeRead(key) {
		if (!key) return null;

		var raw = null;
		try { raw = window.localStorage.getItem(storePrefix() + key); } catch (e) { return null; }
		if (!raw) return null;

		try {
			var wrapped = JSON.parse(raw);
			if (!wrapped || typeof wrapped !== 'object' || typeof wrapped.at !== 'number') {
				storeClear(key);
				return null;
			}
			if ((Date.now() - wrapped.at) > SITTING_MS) {
				storeClear(key);
				return null;
			}
			return wrapped.body != null ? wrapped.body : null;
		} catch (e) {
			// Someone else's key, a truncated write, a value from an older
			// shape of this component. Unreadable is the same as absent.
			storeClear(key);
			return null;
		}
	}

	function storeWrite(key, body) {
		if (!key || body == null) return false;

		var raw;
		try { raw = JSON.stringify({ at: Date.now(), body: body }); } catch (e) { return false; }
		if (raw.length > MAX_SNAPSHOT_CHARS) return false;

		try { window.localStorage.setItem(storePrefix() + key, raw); } catch (e) { return false; }
		return true;
	}

	/**
	 * How many top-level fields differ between two payloads. The number the
	 * confirm dialog quotes, and the test for whether there is anything to
	 * revert at all — a coach who has changed nothing should not be offered
	 * a destructive-sounding action.
	 *
	 * Top-level is the right granularity because that is the granularity the
	 * payload has: `lineup` is one field whatever moved inside it, and a
	 * deeper count would promise a precision the restore does not have.
	 */
	function changedFields(a, b) {
		if (!a || !b || typeof a !== 'object' || typeof b !== 'object') return 0;

		var seen = {};
		Object.keys(a).forEach(function (k) { seen[k] = true; });
		Object.keys(b).forEach(function (k) { seen[k] = true; });

		var n = 0;
		Object.keys(seen).forEach(function (k) {
			var x, y;
			try { x = JSON.stringify(a[k]); } catch (e) { x = 'unserialisable-a'; }
			try { y = JSON.stringify(b[k]); } catch (e) { y = 'unserialisable-b'; }
			if (x !== y) n++;
		});
		return n;
	}

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
			undoError: t.undoError || 'Undo failed — nothing changed',

			// #3006 — the revert confirm. Its words are here for the same
			// reason the states are: a destructive-sounding offer that is
			// phrased differently on each surface is one a coach has to
			// re-read every time.
			revert:       t.revert       || 'Revert changes',
			revertBody:   t.revertBody   || 'Restore this record to how it was when you opened it? This cannot be undone.',
			revertOne:    t.revertOne    || '1 field will be restored.',
			revertMany:   t.revertMany   || '%d fields will be restored.',
			revertCancel: t.revertCancel || 'Cancel',
			revertError:  t.revertError  || 'Revert failed — nothing changed'
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

	/**
	 * A failed response, as an Error that still says which failure it was.
	 * `onError` handlers need the status: a 409 from a record someone else
	 * has moved is a different sentence to a 500, and a component that
	 * flattened both into "Save failed" would make the useful one
	 * unreachable (#3007).
	 */
	function httpError(r) {
		var err = new Error('HTTP ' + r.status);
		err.status = r.status;
		return err;
	}

	var REVERT_DIALOG_ID = 'tt-autosave-revert-dialog';

	function escapeHtml(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	}

	/**
	 * The confirm, built once per page and shared by every saver on it.
	 *
	 * A native `<dialog>` because `showModal()` already does the things a
	 * hand-rolled modal gets wrong: focus moves into it, Tab is trapped
	 * inside it, Escape closes it, and the page behind it is inert. A
	 * `method="dialog"` form carries which button was pressed out through
	 * `returnValue`, so there is no click plumbing either.
	 *
	 * Returns null where `<dialog>` is unsupported; the caller falls back.
	 */
	function ensureRevertDialog(w) {
		var existing = document.getElementById(REVERT_DIALOG_ID);
		if (existing) return existing;
		if (typeof HTMLDialogElement === 'undefined') return null;

		var dialog = document.createElement('dialog');
		dialog.id = REVERT_DIALOG_ID;
		dialog.className = 'tt-modal tt-modal--revert';
		dialog.innerHTML =
			'<form method="dialog" class="tt-modal-form">' +
				'<h2 class="tt-modal-title">' + escapeHtml(w.revert) + '</h2>' +
				'<p class="tt-modal-message" data-tt-revert-msg></p>' +
				'<div class="tt-modal-actions">' +
					'<button type="submit" value="cancel" class="tt-btn tt-btn-secondary">' + escapeHtml(w.revertCancel) + '</button>' +
					'<button type="submit" value="confirm" class="tt-btn tt-btn-danger">' + escapeHtml(w.revert) + '</button>' +
				'</div>' +
			'</form>';
		document.body.appendChild(dialog);
		return dialog;
	}

	function Autosave(opts) {
		this.opts     = opts || {};
		this.stateEl  = this.opts.stateEl || null;
		this.undoEl   = this.opts.undoEl || null;
		this.revertEl = this.opts.revertEl || null;
		this.delay    = typeof this.opts.delay === 'number' ? this.opts.delay : DEFAULT_DELAY;
		this.words    = words(this.opts.i18n);

		// Surface + record. Empty means this surface has not opted into the
		// sitting snapshot, and revert is simply never offered.
		this.storageKey = this.opts.storageKey ? String(this.opts.storageKey) : '';

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

		// The record as this sitting found it (#3006). Null means there is
		// nothing to revert to — no key, no storage, or a record too large
		// to snapshot. All three degrade the same way: no offer.
		this.sitting      = null;
		this.revertFailed = false;

		// #3007 — a terminal state. Set by `halt()` when carrying on would
		// do harm; nothing writes again until the page is reloaded.
		this.halted      = false;
		this.haltMessage = '';

		this._prepareStateEl();
		this._prepareUndoEl();
		this._prepareRevertEl();
		this._prepareLeave();
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

	Autosave.prototype._prepareRevertEl = function () {
		if (!this.revertEl) return;
		var self = this;
		this.revertEl.hidden = true;
		this.revertEl.addEventListener('click', function (e) {
			e.preventDefault();
			self.confirmRevert();
		});
	};

	/**
	 * Leaving on purpose ends the sitting. A surface with a Done or a Cancel
	 * marks it `data-tt-save-leave` and the snapshot goes with the click —
	 * so the next visit starts a fresh sitting rather than being offered a
	 * way back into one the coach already walked out of.
	 *
	 * Surfaces without such an affordance — match prep leaves by breadcrumb —
	 * fall back to the age check in `storeRead()`. Deliberately not bound to
	 * `beforeunload`: that fires on the accidental tab close too, which is
	 * the exact case this feature exists to survive.
	 */
	Autosave.prototype._prepareLeave = function () {
		if (!this.storageKey) return;
		var self = this;
		document.addEventListener('click', function (e) {
			var el = e.target && e.target.closest ? e.target.closest('[data-tt-save-leave]') : null;
			if (el) self.clearSnapshot();
		});
	};

	/**
	 * The state as loaded, before anyone touched it. Without it the first
	 * autosave of a session has nothing to fall back to and undo stays hidden
	 * until the second — which is the one mis-tap most worth catching.
	 *
	 * It is also where the sitting snapshot (#3006) is taken. A snapshot
	 * already in the store wins over the state just loaded: that is precisely
	 * what makes revert survive a reload, because otherwise every refresh
	 * would quietly re-point the target at the edits made so far.
	 */
	Autosave.prototype.seed = function (body) {
		this.committed = snapshot(body);

		if (this.storageKey) {
			var stored = storeRead(this.storageKey);
			if (stored) {
				this.sitting = stored;
			} else if (storeWrite(this.storageKey, this.committed)) {
				this.sitting = snapshot(this.committed);
			} else {
				this.sitting = null;
			}
		}

		this.render();
	};

	/**
	 * Stop writing, permanently, and say why (#3007).
	 *
	 * For the failure that retrying makes worse. The case it was built for
	 * is a record that moved underneath the surface: the coach is looking at
	 * a version the server no longer holds, so every further autosave would
	 * overwrite somebody else's work with a document composed against a
	 * different starting point.
	 *
	 * Deliberately terminal, and deliberately not a "retry" state. The only
	 * way out is a reload, because a reload is the only thing that gets the
	 * coach back onto the version that exists. The edits stay in the fields
	 * either way — nothing on screen is thrown away.
	 */
	Autosave.prototype.halt = function (message) {
		this.halted      = true;
		this.haltMessage = String(message || this.words.error);

		if (this.timer) { clearTimeout(this.timer); this.timer = null; }
		this.queued = false;
		this.render();
	};

	/** Something changed. Debounce, then save. */
	Autosave.prototype.change = function (delay) {
		if (this.halted) return;

		this.dirty        = true;
		this.failed       = false;
		this.undoFailed   = false;
		this.revertFailed = false;
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
		if (this.halted) return Promise.resolve(null);
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
			if (!r.ok) throw httpError(r);
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
		if (this.halted) return Promise.resolve(null);
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

		// And the sitting snapshot with it (#3006), for a sharper reason:
		// this write changes state the full payload does not carry, so a
		// revert could not put it back. A control promising "how it was when
		// you opened this" that silently leaves the role pick standing is
		// worse than no control, so the offer is retired for the sitting.
		this.clearSnapshot();

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
			if (!r.ok) throw httpError(r);
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
		return !this.halted
			&& !!this.undoTarget
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

	/* -----------------------------------------------------------------
	 * Revert the sitting (#3006)
	 * ----------------------------------------------------------------- */

	/**
	 * How many fields the revert would put back. Zero means the record is
	 * already how it was found, and the control is not offered — a coach who
	 * has changed nothing should not be shown a destructive-sounding action.
	 */
	Autosave.prototype.revertCount = function () {
		return changedFields(this.sitting, this.committed);
	};

	/**
	 * Settled, snapshotted, changed, and with somewhere to put the old value.
	 * The same settled-record rule undo follows, for the same reason: a
	 * revert must not race a request already carrying the state it is about
	 * to replace.
	 */
	Autosave.prototype.canRevert = function () {
		return !this.halted
			&& !!this.sitting
			&& typeof this.opts.apply === 'function'
			&& !this.inFlight
			&& !this.queued
			&& !this.dirty
			&& !this.failed
			&& !this.timer
			&& this.revertCount() > 0;
	};

	/**
	 * Forget the sitting. Called on a successful revert, and available to a
	 * surface that has a deliberate way out (a Done or Cancel button) so
	 * leaving on purpose ends the sitting rather than leaving a stale offer
	 * for the next visit.
	 */
	Autosave.prototype.clearSnapshot = function () {
		this.sitting = null;
		storeClear(this.storageKey);
		this.render();
	};

	/**
	 * Ask first. The confirm names the number of fields and says the restore
	 * cannot be undone, because both are things a coach cannot see from the
	 * button: the changes may be spread over three panels, and undo — which
	 * is sitting right beside it — does not reach a revert.
	 */
	Autosave.prototype.confirmRevert = function () {
		if (!this.canRevert()) return;

		var self  = this;
		var count = this.revertCount();
		var line  = count === 1
			? this.words.revertOne
			: this.words.revertMany.replace('%d', String(count));

		var dialog = ensureRevertDialog(this.words);

		// No `<dialog>` support: `window.confirm()` is uglier but it is a
		// confirm, and losing the confirm is not an acceptable degradation
		// for an action that cannot be undone.
		if (!dialog) {
			if (window.confirm(this.words.revertBody + '\n\n' + line)) this.revert();
			return;
		}

		var msg = dialog.querySelector('[data-tt-revert-msg]');
		if (msg) msg.textContent = this.words.revertBody + ' ' + line;

		dialog.returnValue = '';
		dialog.addEventListener('close', function onClose() {
			dialog.removeEventListener('close', onClose);
			// Escape closes with an empty returnValue, which is a cancel.
			if (dialog.returnValue === 'confirm') self.revert();
		});
		dialog.showModal();
	};

	/**
	 * Put the record back to how the sitting found it.
	 *
	 * Structurally the same move as `undo()` — apply, then save, then fall
	 * back to the truth if the save fails — with two differences. The
	 * snapshot is cleared on success, because the sitting's starting point
	 * has now been reached and offering it again would be a no-op. And the
	 * revert is not itself undoable: `_undoing` suppresses the undo target,
	 * which is what the confirm's "cannot be undone" promises.
	 */
	Autosave.prototype.revert = function () {
		if (!this.canRevert()) return Promise.resolve(null);

		var self   = this;
		var target = this.sitting;
		var before = this.committed;

		this.revertFailed = false;
		this.undoTarget   = null;
		this.undoFailed   = false;
		this._undoing     = true;
		this.dirty        = true;
		this.render();

		this.opts.apply(target);

		return this.saveNow().then(function (resp) {
			self._undoing = false;

			if (self.failed) {
				// The server still holds the pre-revert record, so that is
				// what the surface must show. Leaving the reverted values on
				// screen would tell a coach the restore happened.
				self.opts.apply(before);
				self.committed    = before;
				self.dirty        = false;
				self.revertFailed = true;
				self.render();
				return null;
			}

			self.clearSnapshot();
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
		this._renderRevert();
	};

	/**
	 * Hidden until there is a sitting to go back to and a change worth going
	 * back from — see `canRevert()`. On a device with no usable storage that
	 * is never, which is the intended degradation: the surface autosaves and
	 * offers undo exactly as before, and simply does not claim a way back it
	 * cannot deliver.
	 */
	Autosave.prototype._renderRevert = function () {
		if (!this.revertEl) return;
		this.revertEl.hidden = !this.canRevert();
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

		// Terminal, so it outranks everything below it — including a
		// half-finished "Saving…" from the request that produced it.
		if (this.halted) {
			el.classList.add('is-error');
			el.textContent = this.haltMessage;
			return;
		}
		if (this.revertFailed) {
			el.classList.add('is-error');
			el.textContent = this.words.revertError;
			return;
		}
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
