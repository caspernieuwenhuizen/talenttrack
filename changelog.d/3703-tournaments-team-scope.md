# Coaches and team managers can run their own team's tournament (#3703)

The tournament planner shipped admin-only, which meant that on the day a
tournament is played the people who pick the squad and share out the minutes
could not open it. They logged the day as a plain activity of type
"tournament" instead, so its matches, its squad and its fair-share minutes
never reached the module the feature was built for.

Head coaches, assistant coaches and team managers now see and run the
tournaments of the teams they are assigned to, creating included. The Head of
Development sees every tournament in the academy; academy admins are
unchanged. There is no scout grant.

A tournament's squad can be drawn from more than one team, and access follows
the whole set rather than the anchor team alone. You can open and plan a
tournament when any of its teams is one of yours; you can delete it only when
all of them are, because deleting takes the fixture away from every squad in
it. A delete that reaches past your teams is refused with a message that says
so, rather than a bare "not authorized" you cannot tell from a bug. The
tournaments list narrows to your own teams in the query, so you are never
handed another age group's squad. Existing installs get the new grants from a
top-up migration that never overwrites a matrix row an operator has edited.
