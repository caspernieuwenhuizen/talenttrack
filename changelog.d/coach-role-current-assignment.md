# The coach role follows the person's current assignment on that team

Bump: patch

A coach is now head coach or assistant coach per team, read from their
assignment on that team that runs today. Somebody who heads one team and
assists another has the head coach's access on the first and the assistant
coach's on the second, rather than the head coach's on both. A head-coach
assignment that has ended, or one that starts later, no longer makes
somebody a head coach today; one that ends today still counts. A coach with
no assignment running today keeps the kind of role of the most recent one
that ended. Approving a goal a player proposed follows the same dates. A
coach with no assignment, whose team access an administrator granted
directly, is unchanged. For a coach who does hold an assignment, team access
follows the assignments: a role granted on another team under Access
Control, Roles & Permissions no longer gives the coach role's access there.
To give such a coach access to another team, assign them to it under People,
Functional roles.
