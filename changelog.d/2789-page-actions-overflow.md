# Page-header actions no longer run off the side of a phone screen (#2789)

Bump: patch

On narrow screens the action buttons beside a page title were laid out at
their combined natural width instead of the width actually available. On an
activity detail page that put eight of nine actions — including the one that
opens match execution — more than a thousand pixels off the right edge, where
a coach could only reach them by scrolling the whole page sideways.

The action slot now shrinks to the room it has and the buttons stack, so
every action is on screen at 360px and above. Desktop layout is unchanged.
