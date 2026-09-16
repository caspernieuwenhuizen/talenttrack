# A coach whose child plays in the academy keeps their coach dashboard (#3479)

Linking a staff member as a guardian replaced their whole dashboard with the
child-scoped parent rail. They got eight tiles about their kid and none of
their coaching work, and on the default chrome — which has no sidebar — that
left them with no route to any coaching surface at all.

The dashboard decided "this is a parent" by asking one question: does this
person have their own player record? A coach does not, so a single guardian
link was enough. Coaches being parents of players in the same club is ordinary
in youth football, not an edge case.

A staff seat now wins. Coaches, heads of development, scouts, team managers,
staff and admins keep their own dashboard and reach their child through the
Players list like any other player. Guardians who are only guardians are
unaffected, and nobody's access to their own child's data changes — that runs
through the guardian link, which is untouched.
