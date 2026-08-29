# The second dashboard in the WordPress admin is retired (#2979)

TalentTrack had two dashboards. The real one is the app; the other was a
**TalentTrack → Dashboard** page in the WordPress admin showing a grid of links
to the admin screens plus five counters with a "+5 this week" figure. Nobody
tested it against the one people actually use, so it was one screen that could
quietly disagree with another.

It is gone. The old bookmark redirects to the app's dashboard rather than
breaking, and the menu entry no longer appears.

The five weekly counters are not moved anywhere first — they simply stop
existing. An at-a-glance count is worth less than not having two dashboards that
can contradict each other, and the same numbers are available from the reports.

**The Account page is unchanged.** It was in the original scope for this
clean-up and was taken back out: it carries your two-factor set-up and the plan
information, and there is no equivalent in the app yet.
