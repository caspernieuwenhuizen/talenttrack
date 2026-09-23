# An empty teams list says why it is empty (#4029)

The Teams list is scoped to the teams you are assigned to, but it had one
empty state: the fresh-install card, *"No teams yet — create your first team
to build the academy structure."* So a coach nobody had assigned to a team
opened **My teams**, was told the academy had no teams, and was invited to
create one — while four squads sat there. In the pilot a coach account read
zero teams for five days before anyone worked out that the answer was a
missing assignment, not a missing team.

The list now chooses its empty state by why it is empty:

- **Sees every team, and there are none** — the guided fresh-install card,
  with **Create your first team**. Unchanged.
- **Scoped, with no assignment** — *"You are not linked to a team yet"*, and
  ask your academy administrator to assign you to one. No create button:
  the academy is not empty, and offering to create a team sends the coach
  further from the fix.
- **No staff record at all** — the same message plus the part an
  administrator needs to hear, so the account gets added under People before
  anyone hunts for the team.

The page heading stays **Teams** in every case: the same screen serves the
academy admin's full list, and the dashboard tile already reads *My teams*.
