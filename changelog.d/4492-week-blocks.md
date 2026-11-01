# This week panel: set up per role and per device (#4492)

Bump: minor

The coach's "This week" panel can now be set up per role, under Configuration → Default dashboard → This week panel. For each role an academy chooses:

- whether the panel shows, and whether it shows on phones as well;
- which week it covers: the calendar week or the next seven days;
- which teams it covers: the user's own teams, or all academy teams for staff who may see every team;
- whether past days are shown, folded into one line, or hidden.

Every part of a row (type, times, location, attendance, expected players, training plan, opponent, availability, line-up) and every button has its own Desktop and Phone switch. A team-name switch and a peek at the first two activities of next week are new. **Reset to defaults** restores a role's starting settings.

An academy that never opens the settings keeps the panel it had on a desktop, for the same roles, team managers included. On a phone the default panel is shorter: no location and no training plan. Buttons now follow the edit right for each row's team. Scouts and read-only observers see availability counts only when the academy switches that on for them. An academy that already shows the panel to scouts or observers will see those counts disappear for them after the update, until an admin switches availability on for that role. A part whose module is switched off has no switch. `GET me/week` now returns the blocks that are on per device, along with the window, the past-days setting and the next-week peek, so another front end shows the same thing. The settings are stored in `tt_config` as `coach_week.config`, with no database change. The block registry under `Modules/Activities/CoachWeek` is what the next blocks (training, match, week summary, attention and follow-up lists) build on.
