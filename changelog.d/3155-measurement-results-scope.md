# Test results and their Excel export now stop at the teams you coach (#3155)

The Test results screen showed a coach only their own teams' players, as it
always said it did. The API behind the same screen did not: asked without a
team, it answered with every player in the academy who has a result for that
test — name, team, age group and the measured value. The Excel export of the
same data was a second door to it, and needed nothing but a login and a
coaching role somewhere in the club.

Both now answer the same question the screen does, through the same team
filter. A coach with no team assignments gets nothing rather than the club,
and asking for a team you do not coach is refused instead of quietly
widened. Head of Development and Academy Admin see the academy, as before.

Growth and physical testing is longitudinal data about a child's body. It
should reach the coaches responsible for that child and no one else.
