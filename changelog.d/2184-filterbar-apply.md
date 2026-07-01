# FilterBar: explicit Apply button for the date range on the inline bar (#2184)

Bump: patch

The shared filter bar now shows an explicit **Apply** button next to a
from/to date range on the inline (desktop) layout, so changing a date
range has a clear, keyboard-reachable way to commit — the inline bar
previously had no visible commit action for a date change. The mobile
bottom sheet keeps its single footer Apply (no duplicate). The button is a
plain submit: on a bare filter bar it reloads with the new range, and on a
list that filters live it hands off to the existing hydrator instead of
double-submitting. Every view using the filter bar with a date range
(audit log, comparison, and others) benefits.
