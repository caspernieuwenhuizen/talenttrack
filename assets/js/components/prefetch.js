/*
 * prefetch.js (#2517) — warm the next page while the pointer is still
 * travelling toward the link.
 *
 * TalentTrack navigations are real page loads (server-rendered PHP, routed
 * on `?tt_view=`). That is deliberate and not changing here; this only
 * removes the *wait*, which is most of what makes a reload feel heavy.
 * Hovering a nav link for ~65ms starts the fetch, so by the time the click
 * lands the document is usually already in the HTTP cache.
 *
 * Why not WordPress's own speculation rules: core hardcodes an exclusion for
 * any URL carrying a query string when pretty permalinks are on, and its
 * filter can only ADD exclusions ("the base paths cannot be removed").
 * Every TalentTrack route is `?tt_view=…`, so core's rules can never cover
 * the app. Speculation rules also require an inline <script>, which
 * CLAUDE.md §2 forbids. A `<link rel=prefetch>` from an enqueued file has
 * neither problem and gives us control over exactly which links qualify.
 *
 * The server side matters as much as this file: a prefetch renders the page
 * for real, so `UsageTracker::record()` ignores requests carrying
 * `Sec-Purpose: prefetch`. Without that, hovering would count as a visit.
 */
(function () {
	'use strict';

	// Hover intent. Long enough that sweeping the pointer across a sidebar
	// does not fetch every entry; short enough to still win the race.
	var HOVER_DELAY = 65;
	var MAX_PREFETCH = 25;

	if (!supportsPrefetch()) return;

	// Respect the user's data preferences and slow connections — prefetching
	// spends bandwidth on a page they may never open.
	var conn = navigator.connection;
	if (conn) {
		if (conn.saveData) return;
		if (/(^|-)2g$/.test(conn.effectiveType || '')) return;
	}

	var done = Object.create(null);
	var count = 0;
	var timer = null;

	function supportsPrefetch() {
		var l = document.createElement('link');
		return l.relList && l.relList.supports && l.relList.supports('prefetch');
	}

	/** Same-origin app links only, and never anything that mutates. */
	function eligible(a) {
		if (!a || !a.href) return false;
		if (a.origin !== window.location.origin) return false;
		if (a.hasAttribute('download') || a.target === '_blank') return false;
		if (a.dataset.ttNoPrefetch !== undefined) return false;
		// Anchors on the current page fetch nothing useful.
		if (a.pathname === window.location.pathname && a.search === window.location.search) return false;

		var url = a.href.toLowerCase();
		// A GET that changes state is rare here (actions go through
		// admin-post / REST with nonces) but a nonce in the URL is the
		// signal that this one does — never warm those.
		if (url.indexOf('nonce') !== -1 || url.indexOf('action=') !== -1) return false;
		if (url.indexOf('/wp-admin/') !== -1 || url.indexOf('wp-login') !== -1) return false;
		if (url.indexOf('logout') !== -1) return false;

		return true;
	}

	function prefetch(href) {
		if (done[href] || count >= MAX_PREFETCH) return;
		done[href] = true;
		count++;

		var link = document.createElement('link');
		link.rel = 'prefetch';
		link.href = href;
		// `document` tells the browser this is a navigation target, which is
		// what lets it reuse the response for the real click.
		link.as = 'document';
		document.head.appendChild(link);
	}

	function onEnter(e) {
		var a = e.target.closest && e.target.closest('a[href]');
		if (!eligible(a)) return;

		window.clearTimeout(timer);
		timer = window.setTimeout(function () { prefetch(a.href); }, HOVER_DELAY);
	}

	function onLeave() {
		window.clearTimeout(timer);
	}

	document.addEventListener('mouseover', onEnter, { passive: true, capture: true });
	document.addEventListener('mouseout', onLeave, { passive: true, capture: true });

	// Touch has no hover: touchstart fires well before the click completes,
	// which is enough of a head start to matter.
	document.addEventListener('touchstart', function (e) {
		var a = e.target.closest && e.target.closest('a[href]');
		if (eligible(a)) prefetch(a.href);
	}, { passive: true, capture: true });
}());
