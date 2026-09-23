# Print sheets and file exports check which record they were asked for (#4000)

Every capability guarding a print route or a file exporter is held club-wide,
so it answered whether the caller prints team sheets and never whose. Ten
surfaces now resolve the record first: the match plan, the match-day team
sheet and the match analysis through the activity's team, the weekly planner
and the training-plan sheet through the team, the season goals intake through
the player — and, for a whole-squad batch, through the squad and then each
player in it — the team calendar feed, the team schedule PDF and the activity
brief through the team, and the subject-access archive through the player it
names.

A print sheet refuses by printing exactly what it prints for a record that
does not exist, so a print address with a number in it is no longer a way to
find out who is on the academy's books. An export refuses with a message
rather than an empty file, the line `docs/exports.md` already took for the
scoped bulk exports, and answers a record that is not there the same way. The
subject-access archive now records the refused attempt in the audit log beside
the delivered one, and the goals intake is club-scoped again on both its
player and its squad queries.
