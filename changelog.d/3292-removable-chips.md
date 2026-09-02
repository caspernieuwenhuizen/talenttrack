# Filter chips say what is applied — and can be taken off (#3292)

The chips beside **Filters** were the only place the bar named which filters
were applied, and they were hidden from screen readers entirely: the
announcement was "Filters, 3", with no way to learn what the three were. They
could not be tapped off either, so dropping one filter meant opening the sheet
and hunting for the control that set it — and for the period pills and the
archive menu there is often no "none" option to go back to.

Each chip now names its filter and carries a ✕ that removes only that one,
leaving the rest in place. The chips are readable by screen readers and
reachable by keyboard, and the count on the **Filters** button is the number of
chips, so the two can no longer disagree.

The alerts inbox and the player comparison, which never showed chips at all,
get them.
