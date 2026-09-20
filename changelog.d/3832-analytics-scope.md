# A team-scoped analytics grant stays inside its teams (#3832)

`tt_view_analytics` bridges to `analytics: read` and is answered with "any
scope", so a grant at **team** scope made the capability true on every analytics
surface — including the ones that have no team to narrow to. Nobody could reach
that while the only holders were the head of development and the academy admin,
both club-wide; the team manager added in v4.126 is the first team-scoped holder,
and that grant is explicitly for their own squads.

The rule is now narrow-where-you-can, refuse-where-you-can't. Evaluation
coverage, the dimension explorer and scheduled reports ask for club-wide
analytics access through one shared helper and refuse a team-scoped reader **with
a message** rather than an empty page. The attendance reports, the minutes audit,
the minutes team report, the monthly report, the cohort board and the potential
overview narrow to the reader's own teams as they already did.

The analytics hub sits between the two and narrows: a team manager gets their own
squads, players and activities in the left rail, and the academy-wide KPI grid is
replaced by a line saying what would be needed to see it. Opening an entity the
rail would not offer, by typing its id, is refused.

Six standard reports answered an out-of-scope team with "No data for this
selection" — a statement about that team's month, on a team the reader may not
open. They now say it is outside the reader's access, the same distinction
#2893 drew for the attendance drill-down.

Nothing changes for the head of development or the academy admin, who are the
only holders of club-wide analytics access, and `tt_view_analytics` is granted by
no WordPress role directly.
