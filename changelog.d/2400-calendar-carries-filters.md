# Activities calendar keeps the filters you set on the list (#2400)

Bump: patch

Switching the activities page to **Calendar view** used to reset the window: the
grid always showed its own default forward range and ignored the period, the
From/To dates and the activity Type you had scoped the list to. Now those carry
across, so the calendar shows the same activities over the same dates you were
just looking at.

Two things the grid states plainly rather than doing silently: it paints whole
weeks, so a window starting mid-week is drawn from that week's first day (never
less than you asked for), and with the period set to **All** there is no bounded
range to draw, so it falls back to the default forward window. The dates being
shown now appear above the grid either way.

The calendar stays a read-only glance — creating and editing activities is still
the list's job, and the editable planner keeps its own page.
