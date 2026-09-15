# Filter chips: named, removable, and the same everywhere (#3346)

Bump: patch

Every filter bar in the app now builds its own summary chips from the controls
it is showing, instead of each screen writing its own list by hand.

What changes on screen. A chip says which filter it stands for — *Team: Ajax
U17*, *Period: Last week*, rather than a bare *Ajax U17* — and carries a ✕ that
takes off just that one filter and leaves the rest set. **Clear** still takes
them all off. The badge on the **Filters** button is now always the number of
chips, so the two can no longer disagree. A filter sitting on the value the
screen opens with raises no chip and does not count, on every screen rather
than on the ones that remembered to check.

Fourteen screens had their own hand-written version of this: the activities
list, the trials list, the audit log, the message log, the player comparison,
the attendance and minutes reports, the standard reports, both spreadsheet
grids and every list surface. They had drifted — several counted a custom date
window nowhere, so the bar reported "nothing filtered" over a filtered report,
and none of the hand-written chips could be removed or read by a screen reader.

Two smaller improvements fall out of it: a player filter's chip now reads the
player's name where it used to show their database id, and a custom From/To
window is chipped only when it differs from the one the report seeds — its ✕
returns you to that default rather than to an empty range.
