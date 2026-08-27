# Team attendance: the drill-down no longer contradicts the row above it (#2893)

Bump: patch

On the team attendance report a team could show real figures — 12
activities, 92.9% present — while expanding that same row said there was
no player attendance in the window. Two statements on one screen, and no
way to tell which was true.

Two things caused it and both are fixed. The report and the expansion
disagreed about who may read a team, so an academy admin could see every
team listed and be refused every expansion. And a refusal was being
reported as an empty result, so the screen said "no attendance" when it
meant "not yours to see" — it now says the latter.

Also fixes the row counter on that report, which counted each team twice
by including the hidden row its drill-down loads into.
