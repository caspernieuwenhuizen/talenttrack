# Test reports were unreadable on desktop (#2585)

The Test trends report collapsed into a row of narrow columns — chart, rankings
and table each squeezed to a sliver with text breaking one word per line —
because a styling rule written years ago for the small player card was being
applied to any panel that happened to share its name. That rule now belongs to
the player card alone, and the report lays out as intended: full-width chart,
rankings side by side, readable table.

The Test results table also wasted most of its width on the player-name column
while team names wrapped onto three lines and dates onto two. Its columns are
now sized to their content, so each row reads on a single line.

Both reports keep their mobile card layout unchanged.
