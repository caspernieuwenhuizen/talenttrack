# Team monthly report: sessions nobody closed are now reported (#3746)

The team monthly report was built entirely from completed activities, so a
session that came and went and was never marked completed did not appear
anywhere in it. A month with eight scheduled sessions and one closed one
printed "1 activity" and certified the register coverage as complete. The
report's whole job is to say what is missing before the board reads it, and
that was the one gap it could not name.

The activity count in the letterhead and the Activities headline now count
everything on the team's calendar for the period, cancelled sessions excluded —
the same figure the coach sees on the activities list. Data coverage splits the
period three ways: completed with a register, completed without one, and past
its date but never marked completed. The last of those reads on its own line,
in the coverage block and in Data quality, because it sends the coach to a
different screen than a missing register does. Future-dated sessions count
towards the total and towards neither gap. A period holding only unclosed
sessions no longer reads as an empty report.

Stored report snapshots are unaffected: a snapshot freezes the rendered report,
so reopening one shows the numbers the meeting saw, and a scheduled report keeps
its own composition. Only newly composed reports use the new definition, which
means the same team and month can show a higher activity count than a snapshot
taken before this release.
