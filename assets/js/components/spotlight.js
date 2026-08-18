/*
 * spotlight.js (#2458, #2531) — inline search + peek panel for the app shell.
 *
 * Two features, one file, because they share a fetch helper and an escape
 * handler; two copies of those is how they drift.
 *
 * Both are progressive enhancements over markup that already works:
 *
 *   - Search:   the header field is a real <input> in a GET form. This turns
 *               it into a combobox that queries as you type and drops results
 *               beneath it. #2531 replaced the modal palette #2458 shipped —
 *               opening an overlay to reach a second input was a context
 *               switch to start something already on screen. Without JS the
 *               form submits to the tile overview.
 *   - Peek:     every peekable link keeps a real href and navigates normally
 *               when JS is off or the fetch fails. Peek is never the only
 *               path to a record.
 *
 * Budget note (CLAUDE.md §2, 50KB gzipped front end): no dependencies, no
 * framework, no template engine — DOM built imperatively.
 */
(function () {
	'use strict';

	var TT = window.TT || {};
	if (TT.shell !== 'app') return;

	var i18n = TT.i18n || {};
	var restUrl = (TT.rest_url || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/');
	var nonce = TT.rest_nonce || '';

	function t(key, fallback) {
		return i18n[key] || fallback;
	}

	function api(path) {
		return fetch(restUrl + path, {
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin'
		}).then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		});
	}

	/* ---- Shared focus handling ------------------------------------- */

	function focusablesIn(root) {
		return Array.prototype.filter.call(
			root.querySelectorAll('a[href], button:not([disabled]), input, [tabindex]:not([tabindex="-1"])'),
			function (el) { return el.offsetParent !== null; }
		);
	}

	function trap(root, e) {
		if (e.key !== 'Tab') return;
		var items = focusablesIn(root);
		if (!items.length) return;
		var first = items[0];
		var last = items[items.length - 1];
		if (e.shiftKey && document.activeElement === first) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault();
			first.focus();
		}
	}

	/* ================================================================
	 * Inline search (#2531)
	 * ============================================================== */
	/*
	 * The header field IS the search. #2458 shipped a trigger that opened a
	 * modal with its own input — two steps and a context switch to start
	 * something the user was already looking at. Typing here queries
	 * directly and results drop down beneath the field.
	 *
	 * Built as a combobox rather than a styled input: focus never leaves
	 * the field, so movement through the options has to be announced with
	 * `aria-activedescendant` and a polite status line. The old modal got
	 * this for free by trapping focus; inline has to do it deliberately.
	 */

	var sInput = document.querySelector('[data-tt-spotlight-input]');
	var sList = document.getElementById('tt-spotlight-results');
	var sStatus = document.querySelector('[data-tt-spotlight-status]');
	var sForm = sInput && sInput.closest('form');

	var sActive = -1;
	var sDebounce = null;
	var sSeq = 0;

	function sOptions() {
		return sList ? sList.querySelectorAll('[role="option"]') : [];
	}

	function sOpen(open) {
		if (!sList || !sInput) return;
		sList.hidden = !open;
		sInput.setAttribute('aria-expanded', open ? 'true' : 'false');
		if (!open) {
			sActive = -1;
			sInput.removeAttribute('aria-activedescendant');
		}
	}

	function sHighlight(index) {
		var items = sOptions();
		if (!items.length) return;

		if (sActive >= 0 && items[sActive]) items[sActive].classList.remove('is-active');
		sActive = (index + items.length) % items.length;

		var el = items[sActive];
		el.classList.add('is-active');
		el.scrollIntoView({ block: 'nearest' });
		// Focus stays in the input; this is what a screen reader follows.
		sInput.setAttribute('aria-activedescendant', el.id);
	}

	function sRender(results) {
		if (!sList) return;
		sList.textContent = '';
		sActive = -1;
		sInput.removeAttribute('aria-activedescendant');

		if (!results.length) {
			var empty = document.createElement('li');
			empty.className = 'tt-spotlight__empty';
			empty.textContent = t('spotlight_empty', 'Nothing matched.');
			sList.appendChild(empty);
			if (sStatus) sStatus.textContent = t('spotlight_empty', 'Nothing matched.');
			sOpen(true);
			return;
		}

		results.forEach(function (r, i) {
			var li = document.createElement('li');
			li.id = 'tt-spotlight-opt-' + i;
			li.setAttribute('role', 'option');
			li.setAttribute('aria-selected', 'false');
			li.className = 'tt-spotlight__item';

			var a = document.createElement('a');
			a.href = r.url;
			a.className = 'tt-spotlight__result';
			a.tabIndex = -1;   // the input keeps focus; arrows do the moving

			var kind = document.createElement('span');
			kind.className = 'tt-spotlight__kind';
			kind.textContent = t('spotlight_type_' + r.type, r.type);

			var label = document.createElement('span');
			label.className = 'tt-spotlight__label';
			label.textContent = r.label;

			a.appendChild(kind);
			a.appendChild(label);

			if (r.sublabel) {
				var sub = document.createElement('span');
				sub.className = 'tt-spotlight__sub';
				sub.textContent = r.sublabel;
				a.appendChild(sub);
			}

			li.appendChild(a);
			sList.appendChild(li);
		});

		if (sStatus) {
			sStatus.textContent = results.length + ' ' + t('spotlight_results', 'results');
		}
		sOpen(true);
		sHighlight(0);
	}

	function sQuery(q) {
		// Out-of-order responses would otherwise let a slow early query
		// overwrite a fast later one.
		var seq = ++sSeq;
		api('search?q=' + encodeURIComponent(q || ''))
			.then(function (data) {
				if (seq !== sSeq) return;
				sRender((data && data.results) || []);
			})
			.catch(function () {
				if (seq === sSeq) sRender([]);
			});
	}

	if (sInput && sList) {
		sInput.addEventListener('input', function () {
			window.clearTimeout(sDebounce);
			sDebounce = window.setTimeout(function () { sQuery(sInput.value); }, 180);
		});

		// Focusing shows the reachable sections before a character is typed —
		// it is a launcher first and a search box second.
		sInput.addEventListener('focus', function () {
			if (!sOptions().length) sQuery(sInput.value);
			else sOpen(true);
		});

		sInput.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown') { e.preventDefault(); sHighlight(sActive + 1); return; }
			if (e.key === 'ArrowUp') { e.preventDefault(); sHighlight(sActive - 1); return; }

			if (e.key === 'Escape') {
				// Close the list but keep focus in the field — Escape should
				// not cost the user their place.
				e.preventDefault();
				sOpen(false);
				return;
			}

			if (e.key === 'Enter') {
				var items = sOptions();
				if (sActive >= 0 && items[sActive]) {
					var link = items[sActive].querySelector('a');
					if (link) { e.preventDefault(); link.click(); }
				}
				// With nothing highlighted, let the form submit (no-JS path).
			}
		});

		// Pointer users get the same highlight the keyboard does.
		sList.addEventListener('mousemove', function (e) {
			var li = e.target.closest('[role="option"]');
			if (!li) return;
			var items = Array.prototype.indexOf.call(sOptions(), li);
			if (items !== -1 && items !== sActive) sHighlight(items);
		});

		document.addEventListener('click', function (e) {
			if (sForm && !sForm.contains(e.target)) sOpen(false);
		});

		// ⌘K / Ctrl-K focuses the field. Still an accelerator — the field is
		// on screen and clickable without it (CLAUDE.md §2).
		document.addEventListener('keydown', function (e) {
			if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
				e.preventDefault();
				sInput.focus();
				sInput.select();
			}
		});
	}

	/* ================================================================
	 * Peek panel
	 * ============================================================== */

	var peek = null;
	var peekBody = null;
	var peekReturn = null;

	function buildPeek() {
		peek = document.createElement('aside');
		peek.className = 'tt-peek';
		peek.setAttribute('role', 'dialog');
		peek.setAttribute('aria-label', t('peek_title', 'Preview'));
		peek.hidden = true;

		var scrim = document.createElement('div');
		scrim.className = 'tt-peek__scrim';
		scrim.addEventListener('click', closePeek);

		var panel = document.createElement('div');
		panel.className = 'tt-peek__panel';

		peekBody = document.createElement('div');
		peekBody.className = 'tt-peek__body';

		panel.appendChild(peekBody);
		peek.appendChild(scrim);
		peek.appendChild(panel);
		document.body.appendChild(peek);

		peek.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { e.preventDefault(); closePeek(); return; }
			trap(peek, e);
		});
	}

	function renderPeek(data) {
		peekBody.textContent = '';

		var head = document.createElement('div');
		head.className = 'tt-peek__head';

		var title = document.createElement('h2');
		title.className = 'tt-peek__title';
		title.textContent = data.title;
		head.appendChild(title);

		if (data.subtitle) {
			var sub = document.createElement('p');
			sub.className = 'tt-peek__sub';
			sub.textContent = data.subtitle;
			head.appendChild(sub);
		}

		var actions = document.createElement('div');
		actions.className = 'tt-peek__actions';

		var open = document.createElement('a');
		open.className = 'tt-btn tt-btn-primary';
		open.href = data.url;
		open.textContent = t('peek_open', 'Open');

		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'tt-btn tt-btn-secondary';
		close.textContent = t('peek_close', 'Close');
		close.addEventListener('click', closePeek);

		actions.appendChild(close);
		actions.appendChild(open);

		var facts = document.createElement('dl');
		facts.className = 'tt-peek__facts';
		(data.facts || []).forEach(function (f) {
			var dt = document.createElement('dt');
			dt.textContent = f.label;
			var dd = document.createElement('dd');
			dd.textContent = f.value;
			facts.appendChild(dt);
			facts.appendChild(dd);
		});

		peekBody.appendChild(head);
		peekBody.appendChild(facts);
		peekBody.appendChild(actions);

		close.focus();
	}

	function openPeek(type, id, href) {
		if (!peek) buildPeek();
		peekReturn = document.activeElement;
		peek.hidden = false;
		peekBody.textContent = t('peek_loading', 'Loading…');

		api(type + '/' + id + '/summary')
			.then(renderPeek)
			.catch(function () {
				// Never strand the user in a dead panel: fall through to the
				// navigation the link would have done anyway.
				closePeek();
				window.location.href = href;
			});
	}

	function closePeek() {
		if (!peek || peek.hidden) return;
		peek.hidden = true;
		if (peekReturn && typeof peekReturn.focus === 'function') peekReturn.focus();
		peekReturn = null;
	}

	document.addEventListener('click', function (e) {
		var link = e.target.closest('[data-tt-peek]');
		if (!link) return;

		// Let the browser do its normal thing for modified clicks — a
		// middle-click or ⌘-click means "open in a new tab", and hijacking
		// that is the fastest way to make a feature feel broken.
		if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

		// Below the sidebar breakpoint a panel covering 90% of the viewport
		// is just a page with extra steps; navigate instead.
		if (window.matchMedia && !window.matchMedia('(min-width: 1024px)').matches) return;

		var spec = (link.getAttribute('data-tt-peek') || '').split(':');
		if (spec.length !== 2) return;

		e.preventDefault();
		openPeek(spec[0], spec[1], link.href);
	});
})();
