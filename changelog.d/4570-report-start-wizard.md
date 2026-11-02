# Report Studio, phase 2: the start wizard and its rules (#4570)

**+ New report** opens a six-step wizard, one or two questions per page, for the seven starting choices of a Report Studio report or dashboard:
1. What, and about whom.
2. The subject: which player, team or group. The builder is offered only the subjects they build reports about. The step is skipped for a report about themselves or the academy, and when the wizard is opened from a player's or team's page.
3. Who reads it.
4. When and how.
5. Sharing.
6. Name and review. The report is created here and opens in the Studio; nothing is stored before this step.

An option that does not fit the earlier choices stays visible, switched off, with the reason under it. If the builder goes back and changes an earlier answer, a later choice that no longer fits moves to the first option that does, and the wizard says which and why.

One rules service gives the same answer to three callers: the wizard, the new `GET /reports/start-options`, and `POST /reports`. `POST /reports` never moves a value a client sent. It answers 400 naming the later field, with the reason the wizard shows.

The rules now also cover these:
- A scout report is a report, about one player, written by someone who publishes.
- A parent reads a family report only through a shared snapshot.
- A report about me always follows the viewer, and one about the academy is always fixed.
- A grid is for staff.
- A scheduled mail needs a report in rows over a period it can choose.
- Sharing with the family needs a family report.

The wizard is offered only where the Studio is switched on and in the plan.
