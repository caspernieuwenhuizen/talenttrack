# Team attendance report: the player drill-down now lists players (#3279)

Expanding a team row on **Reports → Team attendance** always said "no player
attendance in this window", even where the same row reported 15 activities and
93,4% present. The accordion read the player rows off the wrong level of the
REST response, so it received nothing and printed the empty state — on every
install, since the drill-down shipped.

The rows were always there. Expanding a team now lists its players worst-first
with their present percentage, and the at-risk badge appears on flagged
players — which, being fed from the same rows, had never been seen either.
