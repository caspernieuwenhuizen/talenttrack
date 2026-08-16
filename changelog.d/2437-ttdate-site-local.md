# Timestamps no longer render hours into the future (#2437)

Bump: patch

Dates and times shown across the plugin were read as UTC and then printed
in the academy's timezone, adding the offset twice. On a Dutch install the
"Team last synced from Spond" line on an activity claimed a sync two hours
into the future — a sync at 22:24 printed as 00:24 the next day. The same
skew quietly affected the created/changed audit footer, PDP sign-off and
acknowledgement stamps, and the scout-report history.

Timestamps stored by the plugin are now read in the academy's timezone, so
they print the wall-clock time they were recorded at. Two columns that
genuinely hold UTC keep converting first: a scout link's expiry date now
shows the same moment the expiry check uses, and new scout-report rows
record their creation time in the academy timezone instead of whichever
timezone the database server happens to run in. Date-only values (activity
dates, evaluation dates) also stop slipping to the previous day for
academies west of UTC.
