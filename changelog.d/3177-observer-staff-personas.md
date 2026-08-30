# Read-Only Observer and Staff accounts can see their work again (#3177)

Bump: minor

Two roles had quietly stopped working as the permission model moved onto
the authorization matrix.

A **Read-Only Observer** — the board member, sponsor or auditor seat — was
being narrowed to the teams it was assigned to, and it is never assigned
any. The result was an empty team list, empty pickers and no academy-wide
reports: an account that could sign in and see almost nothing. It now
reads the academy's teams, players, people, evaluations, activities, goals
and reports, and the configuration screens, exactly as the role always
promised. It still cannot change a single thing, and it deliberately does
not reach safeguarding notes, injuries, coaches' private notes on a player,
parents' contact details, photographs, private messages or the audit log —
the documentation now lists that boundary where you decide whether to hand
the role to somebody outside the academy.

A **Staff** account — physio, kit manager, general club staff — had it
worse: on installs using the new permission model it was denied everything
its role granted, because the role reached no persona at all. It now works
again, scoped to the squads that person is attached to.

Staff deliberately do not get the player-management surface, which carries
season rollover, creating player login accounts and deleting player
records. That belongs with coaches and administrators.

Existing installs are updated automatically.
