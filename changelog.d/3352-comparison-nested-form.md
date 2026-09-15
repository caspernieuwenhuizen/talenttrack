# Player comparison: filters apply in place, and the surface stops emitting invalid HTML (#3352)

Bump: patch

The comparison view opened its own form and the filter bar emitted a second
one inside it. Nested forms are invalid HTML, and browsers repair them by
discarding the inner one — which is the only reason the surface worked, and
why it could never take the in-place filtering the rest of the app moved to:
the opt-in marker landed on the form the parser had already thrown away.

`FilterBar` now takes `form => false`, so it renders its groups without a form
of its own and the surface's own form owns them. Changing the date range or the
evaluation type updates the comparison without a page reload. Picking players
is unchanged and still waits for **Compare** — a four-player line-up is worth
assembling before it runs, not recomputing after every pick.

Two things fixed along the way: the radar and trend charts are drawn from an
enqueued script rather than an inline one, so they redraw after a filter
change instead of coming back blank; and a filter-bar select that opts out of
auto-submit now keeps its inline and bottom-sheet copies in step, which is why
changing the evaluation type on a wide screen and pressing Compare used to
send the sheet's stale value.
