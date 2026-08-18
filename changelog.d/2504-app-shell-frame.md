# App shell now fills the window, and its header stays put (#2504)

Bump: patch

With the app shell switched on, the application was still drawn as a centred
document: on a 27" screen at 2560px that meant roughly 550 pixels of empty page
down each side, with the sidebar floating in from the edge rather than sitting
against it. The header also scrolled away with the content, taking search,
notifications and the account menu off screen on any long page.

The app shell now owns the full width of the window, with the sidebar against
the left edge, and the header stays pinned while the content scrolls beneath
it. The sidebar pins directly below the header instead of sliding underneath,
and both allow for the WordPress admin bar where it is shown.

Classic is unchanged — it keeps the centred, width-capped reading layout it has
always had. Switching between the two is still a clean swap either way.
