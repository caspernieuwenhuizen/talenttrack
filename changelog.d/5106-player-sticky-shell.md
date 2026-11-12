# App shell: pinned record strips sit right below the visible chrome (#5106)

Under the app shell, the strip that keeps a player's name and the profile tabs
in view while scrolling, and the PDP conversation's pinned header, now pin
exactly where the visible chrome ends. On a phone and a tablet that is below
the sticky shell header. From 1024px, where the header scrolls away and the
sidebar carries the navigation, it is directly under the WordPress admin bar
for signed-in staff, or at the top of the screen otherwise, with no gap for
the page to show through. The shell publishes this position once, as
`--tt-shell-sticky-top`. The classic shell is unchanged.
