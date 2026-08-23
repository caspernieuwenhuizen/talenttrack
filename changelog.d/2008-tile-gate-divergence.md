# Tiles nobody can open are now caught before they ship (#2008)

Bump: patch

A dashboard tile is offered on one permission and the surface behind it
sometimes demanded a stronger one, so a coach could be shown a tile that
refused them the moment they clicked it. It had happened four times, each fixed
individually, each time discovered by the person it happened to.

The check now runs on every change: it walks every registered tile, works out
which roles are offered it and which of those the destination would turn away,
and fails the build when a new mismatch appears. The four that exist today are
recorded rather than silently allowed — each needs a decision about whether the
surface should open read-only or the role should not be offered it at all, and
that is a judgement per surface rather than a mechanical change.
