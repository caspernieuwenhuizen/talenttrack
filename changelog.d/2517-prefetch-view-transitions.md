# Moving between screens no longer flashes, and lands faster (#2517)

Bump: patch

Clicking through the app reloaded the whole page: the screen blanked, the
sidebar and header were redrawn identically, and you waited.

Two changes make that feel like it used to look. Hovering a link now quietly
starts loading that page, so by the time you click it is usually already there.
And where the browser supports it, moving between screens cross-fades instead
of blanking — the sidebar and header hold still and only the content changes.

Neither alters how the app works: every click is still a normal page load, so
the back button, bookmarks, refresh and opening links in a new tab all behave
exactly as before. Browsers without these features simply navigate the way they
always have.

Two details worth knowing. Prefetching is skipped when your device asks for
reduced data or is on a slow connection, and it never runs ahead of a link that
changes something. And a page loaded in advance is **not** counted as a visit,
so your usage statistics still show where people actually went, not where they
hovered.
