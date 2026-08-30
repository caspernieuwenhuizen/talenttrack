# Match-day surfaces now check whose match it is (#3151)

Match preparation, the live match screen and match analysis each read the
match id out of the address bar and rendered whatever came back. The only
permission involved was "this user coaches", which every coach holds
academy-wide — so editing the address bar opened another team's squad, and
in the case of match analysis, the notes written about those players by
name. The REST routes behind the same screens had the same gap, which let
a coach silently rewrite another team's plan.

All five now ask the same question: does this person coach the team playing
this match? Academy Admin and Head of Development still see every team.
Anyone else gets *You do not coach this activity's team.*
