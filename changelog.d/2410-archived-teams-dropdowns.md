# Archived teams no longer appear in team pickers and dashboard tabs (#2410)

Bump: patch

Archiving a team is supposed to take it out of day-to-day use, but until now it
only greyed the team out on the Teams list: the team kept appearing in every
team dropdown in the app — creating an activity, the coach dashboard's team
tabs, the planner picker, measurement and test-result pickers, PDP, match
execution, the role-grant scope picker and every analytics team filter. A team
sitting in the **recycle bin** showed up in all of them too.

Both shared team helpers now exclude archived and trashed teams by default, and
the hand-rolled team dropdowns were moved onto the same lifecycle vocabulary, so
a retired team disappears from all of these at once. Restoring the team brings
it back everywhere. Unchanged on purpose: the Teams list's own Archived tab, and
the team's own detail page, which must still open for a retired team.
