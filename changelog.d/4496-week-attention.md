# This week panel: players who need attention (#4496)

Bump: minor

The This week panel gains a section below the list that points at individual players on your teams. It has four lines, each switchable per role and device under **Players who need attention** in the panel's settings:

- **Unavailable and returning this week.** The planning screens' neutral "Unavailable", for the teams you plan with. Anyone who may read the team's injuries also sees when the player is expected back, and that read is recorded in the audit log. Scouts and read-only observers get this line only when the academy switches it on for them.
- **Missed several trainings.** Players who missed each of their team's last three recorded trainings, counted the way the attendance report counts.
- **Trial players this week.** Prospects on trial with the team and the days it has an activity, for prospect readers only and within their prospect scope.
- **Birthdays.** A first name and a day.

These are minors' data. A player is named only when the viewer may open their profile, and is otherwise counted. On Birthdays they are left out altogether, so a count never reveals a hidden child's birthday. No injury detail, age or date of birth reaches the page or `GET /me/week`.
