# The app shell's top bar fits on a phone again (#2802)

Bump: patch

With the app shell switched on, the top bar was wider than the screen on
every single page — the search box, notification bell, demo badge, version
number, help button and account chip together needed far more room than a
phone has, so the whole page could be dragged sideways and the bar took up
two rows.

On a phone the bar is now one row: search collapses to a magnifier that
opens a full-width field when tapped, and the version number and demo badge
step aside. The bar went from 127 pixels tall to 69. Desktop is unchanged.
