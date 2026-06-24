# Player accounts: compact rows for not-yet-connected players (#1824)

Bump: patch

Rows for players without an account were much taller than connected rows because the link controls wrapped onto several lines. On tablet/desktop the account dropdown + Link + Invite buttons now sit on a single line, so an unconnected row is no taller than a connected one. Also fixes the "WordPress user to link" screen-reader label leaking visible under canvas mode (it relied on the theme's screen-reader-text class, which canvas isolation strips) by giving the plugin its own SR-only utility.
