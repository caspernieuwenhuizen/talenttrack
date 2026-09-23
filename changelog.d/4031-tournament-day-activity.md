# Tournaments: the day is on the team's calendar from the moment you plan it (#4031)

A tournament used to leave no trace on the team's activity list until somebody
tapped **Kick off** on one of its fixtures, on the morning itself. A tournament
day planned three weeks ahead was therefore invisible to everybody who works
from that list — the team manager sorting transport and kit, the assistant
coach, the parents reading the calendar — and there was no activity to register
availability against.

Creating a tournament now puts the day itself on the calendar, as a planned
tournament activity carrying the tournament's name and start date. Rename the
tournament or move it and the entry follows. Attendance is registered once, for
the day, which is what a multi-game day means.

Kick-off reuses that entry rather than adding a second one, and still promotes
the fixture to an activity of its own: the day is a read-only roll-up of what
its fixtures hold, and the fixture is where its score and its minutes are
recorded. One day, however many fixtures.

Tournaments created before this get their calendar entry the next time they are
edited or a match is added to them — there is nothing to re-run.

Also fixed on the way past: `POST /tournaments` accepted a tournament with no
start date at all. The check compared a value that had already been turned into
`null`, so it never fired.
