/*
 * spotlight.js (#2458) — command palette + peek panel for the app shell.
 *
 * Two features, one file, because they share a fetch helper, a focus-trap
 * and an escape handler; three copies of those is how they drift.
 *
 * Both are progressive enhancements over markup that already works:
 *
 *   - Palette:  the utility-bar search field opens it. Without JS the field
 *               is a plain link to the tile overview.
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
	 * Command palette
	 * ============================================================== */

	var palette = null;
	var paletteInput = null;
	var paletteList = null;
	var paletteReturn = null;
	var activeIndex = -1;
	var debounce = null;

	function buildPalette() {
		palette = document.createElement('div');
		palette.className = 'tt-spotlight';
		palette.setAttribute('role', 'dialog');
		palette.setAttribute('aria-modal', 'true');
		palette.setAttribute('aria-label', t('spotlight_title', 'Jump to'));
		palette.hidden = true;

		var scrim = document.createElement('div');
		scrim.className = 'tt-spotlight__scrim';
		scrim.addEventListener('click', closePalette);

		var panel = document.createElement('div');
		panel.className = 'tt-spotlight__panel';

		paletteInput = document.createElement('input');
		paletteInput.type = 'search';
		paletteInput.className = 'tt-spotlight__input';
		paletteInput.setAttribute('autocomplete', 'off');
		paletteInput.setAttribute('aria-controls', 'tt-spotlight-list');
		paletteInput.placeholder = t('spotlight_placeholder', 'Search players, teams, activities…');

		paletteList = document.createElement('ul');
		paletteList.className = 'tt-spotlight__list';
		paletteList.id = 'tt-spotlight-list';
		paletteList.setAttribute('role', 'listbox');

		panel.appendChild(paletteInput);
		panel.appendChild(paletteList);
		palette.appendChild(scrim);
		palette.appendChild(panel);
		document.body.appendChild(palette);

		paletteInput.addEventListener('input', function () {
			window.clearTimeout(debounce);
			debounce = window.setTimeout(function () { query(paletteInput.value); }, 180);
		});

		palette.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { e.preventDefault(); closePalette(); return; }
			if (e.key === 'ArrowDown') { e.preventDefault(); move(1); return; }
			if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); return; }
			if (e.key === 'Enter') {
				var current = paletteList.querySelector('.is-active a');
				if (current) { e.preventDefault(); current.click(); }
				return;
			}
			trap(palette, e);
		});
	}

	function move(delta) {
		var items = paletteList.querySelectorAll('li');
		if (!items.length) return;
		if (activeIndex >= 0 && items[activeIndex]) items[activeIndex].classList.remove('is-active');
		activeIndex = (activeIndex + delta + items.length) % items.length;
		items[activeIndex].classList.add('is-active');
		items[activeIndex].scrollIntoView({ block: 'nearest' });
	}

	function renderResults(results) {
		paletteList.textContent = '';
		activeIndex = -1;

		if (!results.length) {
			var empty = document.createElement('li');
			empty.className = 'tt-spotlight__empty';
			empty.textContent = t('spotlight_empty', 'Nothing matched.');
			paletteList.appendChild(empty);
			return;
		}

		results.forEach(function (r, i) {
			var li = document.createElement('li');
			li.setAttribute('role', 'option');
			if (i === 0) { li.classList.add('is-active'); activeIndex = 0; }

			var a = document.createElement('a');
			a.href = r.url;
			a.className = 'tt-spotlight__result';

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
			paletteList.appendChild(li);
		});
	}

	function query(q) {
		api('search?q=' + encodeURIComponent(q || ''))
			.then(function (data) { renderResults((data && data.results) || []); })
			.catch(function () { renderResults([]); });
	}

	function openPalette() {
		if (!palette) buildPalette();
		paletteReturn = document.activeElement;
		palette.hidden = false;
		document.body.style.overflow = 'hidden';
		paletteInput.value = '';
		paletteInput.focus();
		// Show reachable views before a single character is typed — the
		// palette is a launcher first and a search box second.
		query('');
	}

	function closePalette() {
		if (!palette || palette.hidden) return;
		palette.hidden = true;
		document.body.style.overflow = '';
		if (paletteReturn && typeof paletteReturn.focus === 'function') paletteReturn.focus();
		paletteReturn = null;
	}

	// Keyboard shortcut is an accelerator, never the only way in — the
	// utility-bar trigger below opens the same overlay (CLAUDE.md §2).
	document.addEventListener('keydown', function (e) {
		if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
			e.preventDefault();
			if (palette && !palette.hidden) { closePalette(); } else { openPalette(); }
		}
	});

	document.addEventListener('click', function (e) {
		var trigger = e.target.closest('[data-tt-spotlight-open]');
		if (trigger) { e.preventDefault(); openPalette(); }
	});

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
