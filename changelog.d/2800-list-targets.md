# List controls and record links are now full-size tap targets (#2800)

Bump: patch

Opening a player, team or activity from a list meant hitting a link about
19 pixels tall, and the pagination underneath every shared list was smaller
still — 26-pixel page buttons and a per-page selector to match. Checkboxes
sat at the browser's own 13-pixel default with nothing sizing them.

All three now meet the intended size on touch devices. Desktop density is
unchanged.
