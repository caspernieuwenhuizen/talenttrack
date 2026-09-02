# The alerts inbox says which filters are actually on (#3320)

The inbox showed a "State: Open" chip the moment you opened it, even though
Open is simply the state the list starts in and nothing was filtered — and
its ✕ led back to the state you were already on. Meanwhile the Area and
Severity you had actually picked showed no chip at all, and the little
number on the Filters button counted something different again.

The chips now name exactly the filters you set, the number on the button is
the number of chips, and an inbox you have not filtered says so.
