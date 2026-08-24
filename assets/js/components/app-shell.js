/*
 * app-shell.js (#2456) — behaviour for the `app` shell's navigation.
 *
 * Two jobs, both progressive enhancements over markup that already works
 * without them:
 *
 *   1. Drawer  (<1024px) — open/close the off-canvas nav, trap focus while
 *      it is open, close on Escape, scrim click, or following a link.
 *   2. Rail    (>=1024px) — collapse the sidebar to icons, remembered per
 *      user in localStorage.
 *   3. Header height (#2504) — publish the sticky header's real height as
 *      a CSS variable so the sticky sidebar starts below it. The stylesheet
 *      carries a sensible default, so this only refines it.
 *
 * With JS disabled the nav is still in the DOM and every entry is a real
 * link; only the drawer toggle is inert, which is why the tile hub stays
 * reachable from the dashboard root. Nothing here fetches — the nav is
 * server-rendered from TileRegistry.
 */
(function () {
	'use strict';

	var RAIL_KEY = 'tt-shell-rail';

	var shell = document.querySelector('[data-tt-shell]');
	var nav = document.querySelector('[data-tt-shell-nav]');
	if (!shell || !nav) return;

	var scrim = document.querySelector('[data-tt-shell-drawer-close]');
	var opener = document.querySelector('[data-tt-shell-drawer-open]');
	var collapser = document.querySelector('[data-tt-shell-collapse]');
	var lastFocused = null;

	/* ---- Drawer ---------------------------------------------------- */

	function focusables() {
		return Array.prototype.filter.call(
			nav.querySelectorAll('a[href], button:not([disabled])'),
			function (el) { return el.offsetParent !== null; }
		);
	}

	function openDrawer() {
		lastFocused = document.activeElement;
		nav.classList.add('is-open');
		if (scrim) scrim.hidden = false;
		if (opener) opener.setAttribute('aria-expanded', 'true');
		// Stop the page behind the drawer from scrolling with it.
		document.body.style.overflow = 'hidden';
		var first = focusables()[0];
		if (first) first.focus();
	}

	function closeDrawer() {
		if (!nav.classList.contains('is-open')) return;
		nav.classList.remove('is-open');
		if (scrim) scrim.hidden = true;
		if (opener) opener.setAttribute('aria-expanded', 'false');
		document.body.style.overflow = '';
		if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
		lastFocused = null;
	}

	if (opener) {
		opener.addEventListener('click', function (e) {
			e.preventDefault();
			if (nav.classList.contains('is-open')) { closeDrawer(); } else { openDrawer(); }
		});
	}

	if (scrim) {
		scrim.addEventListener('click', closeDrawer);
	}

	document.addEventListener('keydown', function (e) {
		if (!nav.classList.contains('is-open')) return;

		if (e.key === 'Escape') {
			e.preventDefault();
			closeDrawer();
			return;
		}

		// Focus trap. Without it, Tab walks straight out of an open
		// drawer into the page behind the scrim, which a keyboard user
		// cannot see is inert.
		if (e.key !== 'Tab') return;
		var items = focusables();
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
	});

	// A tap on a nav link navigates; close first so the drawer is not
	// mid-animation over the incoming page on a slow connection.
	nav.addEventListener('click', function (e) {
		if (e.target.closest('a[href]')) closeDrawer();
	});

	// Crossing up into the sidebar breakpoint leaves the drawer state
	// stale — body scroll would stay locked with no visible drawer.
	if (window.matchMedia) {
		var wide = window.matchMedia('(min-width: 1024px)');
		var onChange = function (mq) { if (mq.matches) closeDrawer(); };
		if (typeof wide.addEventListener === 'function') {
			wide.addEventListener('change', onChange);
		} else if (typeof wide.addListener === 'function') {
			wide.addListener(onChange);
		}
	}

	/* ---- Rail ------------------------------------------------------ */

	/* ---- Group slide (#2504) --------------------------------------- */
	/*
	 * <details> snaps open; this animates the height instead. The element
	 * stays the source of truth — we only intercept the click long enough
	 * to run the transition, and every path ends with `open` correct and
	 * the inline height cleared, so a group can never be left clipped.
	 *
	 * With JS off, or under prefers-reduced-motion, the native instant
	 * toggle is what happens. The animation is decoration, not mechanism.
	 */
	var reduceMotion = window.matchMedia
		&& window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function slideGroup(details, panel, opening) {
		var end = opening ? panel.scrollHeight : 0;

		if (opening) details.open = true;   // must be open to measure/paint

		panel.style.height = (opening ? 0 : panel.scrollHeight) + 'px';
		panel.classList.add('is-animating');

		// Force a reflow so the start height is committed before we change it.
		void panel.offsetHeight;
		panel.style.height = end + 'px';

		var done = function () {
			panel.removeEventListener('transitionend', done);
			panel.classList.remove('is-animating');
			panel.style.height = '';
			if (!opening) details.open = false;
		};
		panel.addEventListener('transitionend', done);
		// Belt and braces: if the transition never fires (display:none in a
		// closed rail, a zero-height group), settle anyway.
		window.setTimeout(done, 260);
	}

	var userToggledGroups = false;

	nav.addEventListener('click', function (e) {
		var summary = e.target.closest && e.target.closest('.tt-shell-nav__group');
		if (!summary) return;

		var details = summary.parentElement;
		var panel = details && details.querySelector('.tt-shell-nav__panel');
		if (!details || !panel) return;

		// Once you have folded something yourself, auto-fit stops second-
		// guessing you for the rest of the visit.
		userToggledGroups = true;

		if (reduceMotion) return;            // let the native toggle happen
		if (shell.classList.contains('is-rail')) return;  // rail keeps all open

		e.preventDefault();                  // we drive `open` ourselves
		slideGroup(details, panel, !details.open);
	});

	/* ---- Only collapse when there is actually no room --------------- */
	/*
	 * Collapsing is a response to overflow, not a house style: if every
	 * destination fits in the rail, nothing should be folded and the
	 * sidebar reads exactly like the design.
	 *
	 * The server renders every group open (#2533), so this only ever closes
	 * groups, never opens them: it starts from the full list and folds from
	 * the bottom up until the rail fits, sparing the group holding the
	 * current view. Measuring is one synchronous pass, so the browser paints
	 * the settled state rather than flashing through it.
	 *
	 * #2803 — the previous wording here claimed the server rendered only the
	 * active group open, which was #2504's behaviour and had already been
	 * superseded. Two readers filed bugs against `$is_open = true` on the
	 * strength of it.
	 */
	function fitGroups() {
		if (userToggledGroups) return;
		if (shell.classList.contains('is-rail')) return;
		/*
		 * #2803 — the sidebar is the only presentation that must not scroll.
		 * Below 1024px the nav is an off-canvas drawer that already has
		 * `overflow-y: auto`, and it is measured while still off-canvas at
		 * full viewport height — so it always read as overflowing and this
		 * closed every group. The drawer opened showing 14 headings and zero
		 * destinations for an admin, and on a 360px phone a player's entire
		 * navigation sat behind one collapsed "Ik".
		 */
		if (window.matchMedia && !window.matchMedia('(min-width: 1024px)').matches) return;

		var scroll = nav.querySelector('.tt-shell-nav__scroll');
		var groups = Array.prototype.slice.call(nav.querySelectorAll('.tt-shell-nav__group-wrap'));
		if (!scroll || !groups.length) return;

		/*
		 * #2533 — the rail should not scroll. Collapse exactly as far as it
		 * takes to fit, and no further.
		 *
		 * The measurement is the scroll container, which sits between the
		 * brand block and the account block — so "fits" already means "does
		 * not run under the account", with no separate arithmetic to keep in
		 * step with the layout.
		 *
		 * `+1` absorbs sub-pixel rounding; without it a fractional layout
		 * reads as permanently overflowing and shuts every group.
		 */
		var overflows = function () {
			return scroll.scrollHeight > scroll.clientHeight + 1;
		};

		// The group holding the current page is the last one to give up its
		// space — collapsing where you are is the one unhelpful outcome.
		var activeIndex = -1;
		for (var a = 0; a < groups.length; a++) {
			if (groups[a].querySelector('.tt-shell-nav__link.is-active')) { activeIndex = a; break; }
		}

		// Start from everything visible, then close from the bottom up: the
		// groups nearest the top are the ones in daily use (groupOrder puts
		// them there), so they are the last to be given away.
		groups.forEach(function (d) { d.open = true; });

		for (var i = groups.length - 1; i >= 0 && overflows(); i--) {
			if (i === activeIndex) continue;
			groups[i].open = false;
		}

		// Everything else is shut and it still does not fit — a very short
		// viewport, or a very long group. The active one closes too, and the
		// scroll container is the last resort rather than the first.
		if (overflows() && activeIndex !== -1) {
			groups[activeIndex].open = false;
		}
	}

	fitGroups();

	var fitTimer = null;
	window.addEventListener('resize', function () {
		window.clearTimeout(fitTimer);
		fitTimer = window.setTimeout(fitGroups, 150);
	});

	/*
	 * #2504 — group collapse vs the icon rail.
	 *
	 * Groups are <details>, and a closed one hides its links. In the
	 * collapsed rail the headings are gone, so a closed group would hide its
	 * icons with no control left to reopen it — the entries would simply be
	 * missing. A closed <details> cannot be reliably forced visible from CSS
	 * (engines hide the content slot in ways author styles don't override),
	 * so the rail opens them all and restores the previous state on the way
	 * out.
	 */
	function setGroupsForRail(on) {
		var groups = nav.querySelectorAll('.tt-shell-nav__group-wrap');
		Array.prototype.forEach.call(groups, function (d) {
			if (on) {
				if (!d.hasAttribute('data-tt-was-open')) {
					d.setAttribute('data-tt-was-open', d.open ? '1' : '0');
				}
				d.open = true;
			} else if (d.hasAttribute('data-tt-was-open')) {
				d.open = d.getAttribute('data-tt-was-open') === '1';
				d.removeAttribute('data-tt-was-open');
			}
		});
	}

	function applyRail(on) {
		shell.classList.toggle('is-rail', on);
		// #2533 — the grid now lives on .tt-dashboard (the rail spans both
		// of its rows), so the column-width swap has to be reachable from
		// there. `.tt-shell` keeps the class too: every other rail rule is
		// written against it.
		var gridRoot = document.querySelector('.tt-dashboard.tt-shell-app');
		if (gridRoot) gridRoot.classList.toggle('is-rail', on);
		setGroupsForRail(on);
		if (collapser) {
			collapser.setAttribute('aria-expanded', on ? 'false' : 'true');
			var label = on
				? (window.TT && window.TT.i18n && window.TT.i18n.shell_nav_expand) || 'Expand navigation'
				: (window.TT && window.TT.i18n && window.TT.i18n.shell_nav_collapse) || 'Collapse navigation';
			collapser.setAttribute('aria-label', label);
			collapser.setAttribute('title', label);
		}
	}

	var stored = null;
	try { stored = window.localStorage.getItem(RAIL_KEY); } catch (err) { stored = null; }
	if (stored === '1') applyRail(true);

	if (collapser) {
		collapser.addEventListener('click', function (e) {
			e.preventDefault();
			var next = !shell.classList.contains('is-rail');
			applyRail(next);
			try { window.localStorage.setItem(RAIL_KEY, next ? '1' : '0'); } catch (err) { /* private mode */ }
		});
	}

	/* ---- Header height (#2504) ------------------------------------- */
	/*
	 * The sidebar pins below the sticky header, so it needs the header's
	 * height. CSS cannot measure it, and the default in the stylesheet is
	 * only right for the common case — a long academy name wraps the brand
	 * row, and browser zoom or a larger base font changes it too. Publish
	 * the measured value; if this never runs, the CSS default still holds.
	 */
	var root = document.querySelector('.tt-dashboard.tt-shell-app');
	var header = document.querySelector('.tt-dash-header');

	if (root && header) {
		var syncHeaderHeight = function () {
			var h = Math.round(header.getBoundingClientRect().height);
			if (h > 0) root.style.setProperty('--tt-shell-header-h', h + 'px');
		};

		syncHeaderHeight();

		if (typeof ResizeObserver !== 'undefined') {
			new ResizeObserver(syncHeaderHeight).observe(header);
		} else {
			window.addEventListener('resize', syncHeaderHeight);
		}
	}

	/* ---- Shortcut badge (#2563) ------------------------------------- */
	/*
	 * The search badge is server-rendered as "Ctrl K" — the server cannot
	 * know the platform, so it ships the majority case and this corrects it
	 * on a Mac. It used to be hardcoded to the Command glyph, which told
	 * every Windows user to press a key their keyboard does not have.
	 *
	 * `userAgentData.platform` where available, falling back to the classic
	 * sniff. Both can be spoofed, but the cost of being wrong is a slightly
	 * off label, not broken behaviour: the handler accepts Meta and Control
	 * either way.
	 */
	var hint = document.querySelector('[data-tt-shortcut-hint]');
	if (hint) {
		var plat = (navigator.userAgentData && navigator.userAgentData.platform)
			|| navigator.platform
			|| '';
		if (/mac/i.test(plat)) hint.textContent = '⌘K';
	}
})();
