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
})();
