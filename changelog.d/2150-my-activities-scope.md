# My activities now shows only your own sessions (#2150)

Bump: patch

The dedicated **My activities** page could fall through to the broader
team/club result set when the player's linked-player resolution was missing
or mismatched, leaking activities that weren't theirs. The activities REST
list now fails closed for player and parent callers: it re-derives the
scoped player id from the session (a player's own linked player, or a
verified child for a parent) instead of trusting the request, and returns an
empty list when nothing resolves — never the unscoped set. Staff lists are
unchanged.
