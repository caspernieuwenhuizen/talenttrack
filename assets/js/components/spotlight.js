/*
 * spotlight.js (#2458, #2531, #4672) — the header search and the peek panel.
 *
 * Two features, one file, because they share a fetch helper and an escape
 * handler; two copies of those is how they drift.
 *
 * Both are progressive enhancements over markup that already works:
 *
 *   - Search:   the header field is a real <input> in a GET form that
 *               submits to the results page (?tt_view=search). This turns
 *               it into a combobox with grouped quick results, the recent
 *               list on an empty box, and on a phone a full-screen sheet
 *               behind the 48px search button. Without JS the button is a
 *               link to the results page. Runs in both shells.
 *   - Peek:     every peekable link keeps a real href and navigates normally
 *               when JS is off or the fetch fails. Peek is never the only
 *               path to a record. App shell only.
 *
 * Budget note (CLAUDE.md §2, 50KB gzipped front end): no dependencies, no
 * framework, no template engine — DOM built imperatively.
 */
(function () {
	'use strict';

	var TT = window.TT || {};
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
			root.querySelectorAll('a[href], button:not([disabled]), input:not([type="hidden"]), [tabindex]:not([tabindex="-1"])'),
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
	 * Header search (#2531, #4672)
	 * ============================================================== */
	/*
	 * Built as a combobox: focus never leaves the field, so movement through
	 * the options is carried by `aria-activedescendant` and a polite status
	 * line. Options are plain elements with the URL in data-href, not links:
	 * a focusable element inside an option is a nested interactive control.
	 *
	 * Nothing is selected until an arrow key moves, so Enter with no row
	 * selected submits the form to the results page.
	 */

	var sForm = document.querySelector('[data-tt-spotlight-form]');
	var sInput = sForm && sForm.querySelector('[data-tt-spotlight-input]');
	var sPanel = sForm && sForm.querySelector('[data-tt-spotlight-panel]');
	var sList = document.getElementById('tt-spotlight-results');
	var sUnderstood = sForm && sForm.querySelector('[data-tt-spotlight-understood]');
	var sEmpty = sForm && sForm.querySelector('[data-tt-spotlight-empty]');
	var sStatus = sForm && sForm.querySelector('[data-tt-spotlight-status]');
	var sOpenBtn = document.querySelector('[data-tt-spotlight-open]');
	var sCloseBtn = sForm && sForm.querySelector('[data-tt-spotlight-close]');

	var GROUPS = Array.isArray(TT.search_groups) ? TT.search_groups : [];
	var RECENT_SHOWN = 8;

	var sActive = -1;
	var sDebounce = null;
	var sSeq = 0;
	var sSheet = false;
	var sSheetPushed = false;
	var sOptSeq = 0;

	/* ---- Text helpers ------------------------------------------------ */

	function fold(s) {
		var out = String(s || '');
		if (out.normalize) out = out.normalize('NFD').replace(/[̀-ͯ]/g, '');
		return out.toLowerCase();
	}

	function initials(name) {
		var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
		if (!parts.length) return '?';
		if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
		return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
	}

	/*
	 * The label with every typed word marked. Folding is per character so
	 * a match found in the folded text maps back onto the original one:
	 * "jose" marks the "José" it found.
	 */
	function highlighted(label, query) {
		var frag = document.createDocumentFragment();
		var text = String(label || '');
		var words = String(query || '').split(/\s+/).map(fold).filter(function (w) {
			return w.length > 0 && w.charAt(0) !== '#';
		});
		if (!words.length) {
			frag.appendChild(document.createTextNode(text));
			return frag;
		}

		var folded = '';
		var map = [];
		for (var i = 0; i < text.length; i++) {
			var f = fold(text.charAt(i));
			for (var k = 0; k < f.length; k++) map.push(i);
			folded += f;
		}

		var marks = new Array(text.length);
		words.forEach(function (w) {
			var from = 0;
			var at;
			while ((at = folded.indexOf(w, from)) !== -1) {
				for (var j = at; j < at + w.length; j++) marks[map[j]] = true;
				from = at + w.length;
			}
		});

		var run = '';
		var inMark = false;
		function flush() {
			if (!run) return;
			if (inMark) {
				var m = document.createElement('mark');
				m.className = 'tt-spotlight__mark';
				m.textContent = run;
				frag.appendChild(m);
			} else {
				frag.appendChild(document.createTextNode(run));
			}
			run = '';
		}
		for (var c = 0; c < text.length; c++) {
			var on = !!marks[c];
			if (on !== inMark) { flush(); inMark = on; }
			run += text.charAt(c);
		}
		flush();
		return frag;
	}

	/* ---- Open state -------------------------------------------------- */

	function sOptions() {
		return sList ? sList.querySelectorAll('[role="option"]') : [];
	}

	function sOpen(open) {
		if (!sPanel || !sInput) return;
		sPanel.hidden = !open;
		sInput.setAttribute('aria-expanded', open && sOptions().length ? 'true' : 'false');
		if (!open) sClearActive();
	}

	function sClearActive() {
		var items = sOptions();
		if (sActive >= 0 && items[sActive]) {
			items[sActive].classList.remove('is-active');
			items[sActive].setAttribute('aria-selected', 'false');
		}
		sActive = -1;
		if (sInput) sInput.removeAttribute('aria-activedescendant');
	}

	function sHighlight(index) {
		var items = sOptions();
		if (!items.length) return;
		if (sPanel.hidden) sOpen(true);

		if (sActive >= 0 && items[sActive]) {
			items[sActive].classList.remove('is-active');
			items[sActive].setAttribute('aria-selected', 'false');
		}
		sActive = (index + items.length) % items.length;

		var el = items[sActive];
		el.classList.add('is-active');
		el.setAttribute('aria-selected', 'true');
		el.scrollIntoView({ block: 'nearest' });
		sInput.setAttribute('aria-activedescendant', el.id);
		if (sStatus) sStatus.textContent = el.getAttribute('aria-label') || el.textContent;
	}

	function sGo(option, newTab) {
		var href = option && option.getAttribute('data-href');
		if (!href) return;
		if (newTab) {
			window.open(href, '_blank', 'noopener');
			return;
		}
		// From the sheet, replace the history entry the sheet pushed, so
		// Back on the next page returns here and not to an empty sheet.
		if (sSheetPushed) window.location.replace(href);
		else window.location.href = href;
	}

	/* ---- Rendering --------------------------------------------------- */

	function option(cls, href, name) {
		var el = document.createElement('div');
		el.id = 'tt-spotlight-opt-' + (++sOptSeq);
		el.className = 'tt-spotlight__option ' + cls;
		el.setAttribute('role', 'option');
		el.setAttribute('aria-selected', 'false');
		el.setAttribute('data-href', href || '');
		if (name) el.setAttribute('aria-label', name);
		return el;
	}

	function resultsUrl(q, kind) {
		var url = new URL(sForm.getAttribute('action') || window.location.href, window.location.href);
		url.searchParams.set('tt_view', 'search');
		url.searchParams.set('q', q);
		if (kind) url.searchParams.set('type', kind);
		else url.searchParams.delete('type');
		return url.toString();
	}

	function row(item, query) {
		var wrap = document.createElement('div');
		wrap.className = 'tt-spotlight__row tt-spotlight__row--' + item.type;
		wrap.setAttribute('role', 'presentation');

		var main = option('tt-spotlight__result', item.url, '');
		if (item.type === 'player') {
			if (item.photo_url) {
				var img = document.createElement('img');
				img.className = 'tt-spotlight__avatar';
				img.src = item.photo_url;
				img.width = 32;
				img.height = 32;
				img.alt = '';
				main.appendChild(img);
			} else {
				var ini = document.createElement('span');
				ini.className = 'tt-spotlight__avatar tt-spotlight__avatar--initials';
				ini.setAttribute('aria-hidden', 'true');
				ini.textContent = initials(item.label);
				main.appendChild(ini);
			}
		}

		var text = document.createElement('span');
		text.className = 'tt-spotlight__text';
		var label = document.createElement('span');
		label.className = 'tt-spotlight__label';
		label.appendChild(highlighted(item.label, query));
		// #4936 — a switched-off module's row says so.
		if (item.disabled) {
			var state = document.createElement('span');
			state.className = 'tt-spotlight__state';
			state.textContent = t('search_off', 'Off');
			label.appendChild(document.createTextNode(' '));
			label.appendChild(state);
		}
		text.appendChild(label);
		// #4936 — a player's shirt number and primary position, before the team.
		var facts = [];
		if (item.shirt_number) facts.push('#' + item.shirt_number);
		if (item.position) facts.push(item.position);
		var subText = [facts.join(' '), item.sublabel || ''].filter(Boolean).join(' · ');
		if (subText) {
			var sub = document.createElement('span');
			sub.className = 'tt-spotlight__sub';
			sub.textContent = subText;
			text.appendChild(sub);
		}
		// #4936 — an excerpt of free text, only ever text the viewer may read.
		if (item.excerpt) {
			var excerpt = document.createElement('span');
			excerpt.className = 'tt-spotlight__excerpt';
			excerpt.appendChild(highlighted(item.excerpt, query));
			text.appendChild(excerpt);
		}
		main.appendChild(text);
		wrap.appendChild(main);

		(item.actions || []).slice(0, 2).forEach(function (action) {
			if (!action || !action.url) return;
			var a = option('tt-spotlight__action', action.url, action.label + ': ' + item.label);
			a.textContent = action.label;
			wrap.appendChild(a);
		});
		return wrap;
	}

	function group(kind, title, items, query, total) {
		var g = document.createElement('div');
		g.className = 'tt-spotlight__group';
		g.setAttribute('role', 'group');
		var head = document.createElement('div');
		head.className = 'tt-spotlight__group-title';
		head.id = 'tt-spotlight-group-' + kind;
		head.setAttribute('role', 'presentation');
		head.textContent = title;
		g.setAttribute('aria-labelledby', head.id);
		g.appendChild(head);

		items.forEach(function (item) { g.appendChild(row(item, query)); });

		if (total > items.length && query) {
			var all = option('tt-spotlight__all', resultsUrl(query, kind), '');
			all.textContent = t('search_all', 'All %d').replace('%d', String(total)) + ' ›';
			g.appendChild(all);
		}
		return g;
	}

	function sShow(groups, understood, emptyText) {
		sList.textContent = '';
		sClearActive();

		var count = 0;
		groups.forEach(function (g) {
			if (!g.items.length && !(g.total > 0)) return;
			sList.appendChild(group(g.kind, g.title, g.items, g.query, g.total || 0));
			count += g.items.length;
		});

		if (sUnderstood) {
			sUnderstood.hidden = !understood;
			sUnderstood.textContent = understood || '';
		}
		var none = sOptions().length === 0;
		sList.hidden = none;
		if (sEmpty) {
			sEmpty.hidden = !none || !emptyText;
			sEmpty.textContent = emptyText || '';
		}
		if (sStatus) {
			sStatus.textContent = none ? (emptyText || '') : count + ' ' + t('spotlight_results', 'results');
		}
		sOpen(!none || !!emptyText || !!understood);
	}

	// #4936 — a group can hold more than one kind (Evaluations & goals):
	// its rows in the ranked order the server sent, its count summed.
	function kindsOf(g) {
		return Array.isArray(g.kinds) && g.kinds.length ? g.kinds : [g.kind];
	}

	function inGroup(items, g) {
		var kinds = kindsOf(g);
		return (items || []).filter(function (it) { return kinds.indexOf(it.type) !== -1; });
	}

	function countOf(counts, g) {
		return kindsOf(g).reduce(function (n, k) { return n + (counts[k] || 0); }, 0);
	}

	// The paged routes answer in the { success, data, errors } envelope.
	function payload(body) {
		return (body && body.data) || {};
	}

	function sSearch(q) {
		var seq = ++sSeq;
		api('search?page=1&per_page=50&q=' + encodeURIComponent(q))
			.then(function (body) {
				if (seq !== sSeq) return;
				var data = payload(body);
				var items = (data && data.items) || [];
				var counts = (data && data.counts) || {};
				var groups = GROUPS.map(function (g) {
					return {
						kind: g.kind,
						title: g.label,
						items: inGroup(items, g).slice(0, g.limit),
						total: countOf(counts, g),
						query: q
					};
				});
				var understood = ((data && data.understood) || []).map(function (u) { return u.label; }).join(', ');
				sShow(
					groups,
					understood ? t('search_understood', 'Understood: %s').replace('%s', understood) : '',
					t('spotlight_empty', 'Nothing matched.') + ' ' + t('search_see_all', 'Press Enter to see every result.')
				);
			})
			.catch(function () {
				if (seq === sSeq) sShow([], '', t('spotlight_empty', 'Nothing matched.'));
			});
	}

	/*
	 * An empty box: the records opened last, re-checked by the server. With
	 * none yet, the sections the viewer can reach, so the box still works as
	 * a launcher.
	 */
	function sRecent() {
		var seq = ++sSeq;
		api('me/recent')
			.then(function (body) {
				if (seq !== sSeq) return null;
				var items = (payload(body).items || []).slice(0, RECENT_SHOWN);
				if (items.length) {
					sShow([{ kind: 'recent', title: t('search_recent', 'Recently opened'), items: items, query: '' }], '', '');
					return null;
				}
				return api('search?q=');
			})
			.then(function (data) {
				if (!data || seq !== sSeq) return;
				var sections = GROUPS.filter(function (g) { return g.kind === 'view'; })[0];
				sShow([{
					kind: 'view',
					title: sections ? sections.label : t('spotlight_type_view', 'Section'),
					items: (data.results || []).map(function (r) { r.actions = []; return r; }),
					query: ''
				}], '', '');
			})
			.catch(function () {
				if (seq === sSeq) sShow([], '', '');
			});
	}

	function sRefresh() {
		var q = sInput.value.trim();
		if (q === '') sRecent();
		else sSearch(q);
	}

	/* ---- The phone sheet --------------------------------------------- */

	function sheetOpen() {
		if (sSheet) return;
		sSheet = true;
		sForm.classList.add('is-sheet');
		document.documentElement.classList.add('tt-search-sheet-open');
		if (window.history && history.pushState) {
			history.pushState({ ttSearchSheet: true }, '');
			sSheetPushed = true;
		}
		// The focus handler fills the sheet: the recent list, or the results
		// for what is already typed.
		sInput.focus();
	}

	function sheetHide() {
		if (!sSheet) return;
		sSheet = false;
		sSheetPushed = false;
		sForm.classList.remove('is-sheet');
		document.documentElement.classList.remove('tt-search-sheet-open');
		sOpen(false);
		if (sOpenBtn) sOpenBtn.focus();
	}

	// Closing goes through history when the sheet pushed an entry, so Back
	// and the close button end in the same state.
	function sheetClose() {
		if (sSheetPushed) history.back();
		else sheetHide();
	}

	function isPhone() {
		return !!(sOpenBtn && sOpenBtn.offsetParent !== null);
	}

	function focusSearch() {
		if (isPhone()) {
			sheetOpen();
			return;
		}
		sInput.focus();
		sInput.select();
	}

	/* ---- Wiring ------------------------------------------------------ */

	function sOptOut() {
		sList.textContent = '';
		sOpen(false);
	}

	if (sForm && sInput && sList && sPanel) {
		sInput.addEventListener('input', function () {
			window.clearTimeout(sDebounce);
			sDebounce = window.setTimeout(sRefresh, 180);
		});

		sInput.addEventListener('focus', function () {
			if (sOptions().length || (sEmpty && !sEmpty.hidden)) sOpen(true);
			else sRefresh();
		});

		sInput.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown') { e.preventDefault(); sHighlight(sActive + 1); return; }
			if (e.key === 'ArrowUp') { e.preventDefault(); sHighlight(sActive - 1); return; }

			if (e.key === 'Escape') {
				e.preventDefault();
				if (sSheet) { sheetClose(); return; }
				// First Escape closes the list, the second clears the field.
				if (!sPanel.hidden) { sOpen(false); return; }
				if (sInput.value !== '') {
					sInput.value = '';
					sOptOut();
				}
				return;
			}

			if (e.key === 'Enter') {
				var items = sOptions();
				if (sActive >= 0 && items[sActive]) {
					e.preventDefault();
					sGo(items[sActive], e.ctrlKey || e.metaKey);
				}
				// With nothing selected the form submits to the results page.
			}
		});

		sList.addEventListener('click', function (e) {
			var opt = e.target.closest('[role="option"]');
			if (!opt) return;
			e.preventDefault();
			sGo(opt, e.ctrlKey || e.metaKey);
		});

		sList.addEventListener('auxclick', function (e) {
			var opt = e.target.closest('[role="option"]');
			if (opt && e.button === 1) sGo(opt, true);
		});

		// Pointer users get the same highlight the keyboard does.
		sList.addEventListener('mousemove', function (e) {
			var opt = e.target.closest('[role="option"]');
			if (!opt) return;
			var index = Array.prototype.indexOf.call(sOptions(), opt);
			if (index !== -1 && index !== sActive) sHighlight(index);
		});

		document.addEventListener('click', function (e) {
			if (!sSheet && !sForm.contains(e.target)) sOpen(false);
		});

		if (sOpenBtn) {
			sOpenBtn.addEventListener('click', function (e) {
				if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
				e.preventDefault();
				sheetOpen();
			});
		}

		if (sCloseBtn) {
			sCloseBtn.addEventListener('click', function (e) {
				e.preventDefault();
				sheetClose();
			});
		}

		sForm.addEventListener('keydown', function (e) {
			if (sSheet) trap(sForm, e);
		});

		window.addEventListener('popstate', function () {
			if (sSheet) sheetHide();
		});

		// ⌘K / Ctrl-K: an accelerator for a control that is on screen and
		// clickable without it (CLAUDE.md §2).
		document.addEventListener('keydown', function (e) {
			if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
				e.preventDefault();
				focusSearch();
			}
		});

		// #4252 — a page can hand the reader to the search with a control
		// marked data-tt-spotlight-focus.
		document.addEventListener('click', function (e) {
			if (!e.target.closest('[data-tt-spotlight-focus]')) return;
			e.preventDefault();
			if (!isPhone()) sInput.scrollIntoView({ block: 'nearest' });
			focusSearch();
		});

		var hint = sForm.querySelector('[data-tt-shortcut-hint]');
		if (hint) {
			var plat = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '';
			if (/mac/i.test(plat)) hint.textContent = '⌘K'; // tt-i18n-ok — a key name
		}
	}

	/* ================================================================
	 * Peek panel — app shell only
	 * ============================================================== */

	if (TT.shell !== 'app') return;

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
