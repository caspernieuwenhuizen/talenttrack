# The monthly report can carry the month's results (#3516)

Bump: minor

The report said a great deal about how players were developing and nothing
about how the team was doing, so the score got read off somebody's phone in the
meeting.

A new **Results** section prints the record over the period — played, won,
drawn, lost, goals for and against, clean sheets — then every match with its
date, opponent, home or away, and score, and the scorers and assists from goals
already recorded. Optionally the squad and minutes under each match, though
that starts switched off: it is the longest part of the report and says much
the same as Minutes share.

Two things it says out loud rather than hiding. **A match nobody typed a result
into** is listed as exactly that and counts towards none of the record — a
silent 0–0 would make every figure above it wrong. **Tournament days stay out
of the record**, with a line saying how many there were, because a tournament
is several games and one scoreline cannot describe it.

If the goals have not been attributed to players yet, the section says so
instead of printing an empty scorers list — an empty list reads as "nobody
scored", which is usually untrue and is the sort of thing that gets reported
as a bug.

Every figure comes from the same reader the team statistics tab uses, so the
report's record and the tab's record cannot drift apart.

Existing saved reports and monthly schedules are untouched: they name their
sections explicitly, so none of them gains this one until you tick it.
