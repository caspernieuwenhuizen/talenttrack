# Prospects status filter no longer shows "All" twice (#2093)

The Prospects overview status filter listed an explicit "All" option on top
of the FilterBar placeholder's own "All", so the dropdown showed it twice
after the FilterBar migration. The redundant option is removed; the filter
behaves identically.
