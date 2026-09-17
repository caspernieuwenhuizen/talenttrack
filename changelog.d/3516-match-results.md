# Monthly report: a match results and statistics section (#3516)

Bump: minor

The monthly report said a great deal about development and nothing about
results, so the score got read off someone's phone in the meeting. The data was
already recorded; the report simply never read it.

The new **Matches** section has three parts, each with its own tick box: the
record over the period (played, won, drawn, lost, goals for and against), goals
and assists per player, and — off by default, because it is the longest part and
repeats the minutes section — who played in each match and for how long. Each
match is listed with its date, the opponent, home or away, and the score, so
there is no separate results table repeating them.

Two deliberate gaps, both stated on the page rather than hidden. A match with no
score recorded is listed but not counted in won, drawn or lost: treating it as a
goalless draw would make the record quietly wrong. And tournaments are left out,
because a tournament is a multi-game day that one score line cannot describe —
when any fall in the period the section says so, so the record never silently
disagrees with what the coach remembers.

Existing saved reports are unaffected: a saved view that names its sections does
not gain this one.
