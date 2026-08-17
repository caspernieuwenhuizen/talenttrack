# Usage statistics: "last N days" windows now use the site's timezone (#2444)

Bump: patch

Every "last N days" figure on the usage-statistics surfaces was off by the
site's UTC offset. Events are stamped in site-local time, but each window
boundary was built in UTC, so on a Dutch install the window started two hours
late: activity between 00:00 and 02:00 on the oldest day of the window was
left out, and the same two hours from the day before were counted in. The
daily-active-users chart and the "events on this day" drill-down could also
disagree at those edges, filing a 00:30 event under the neighbouring day. The
90-day retention prune deleted two hours early for the same reason.

All of these boundaries are now built in the site's timezone, so the numbers
line up with the calendar days people actually worked. Counts on an offset
install will shift slightly — that shift is the correction. No data changed:
the stored events were always site-local, this fixes how they are read.
