# Tighter access checks on player, team, report and trial data (#4412)

Bump: patch

Several reads and writes now follow the access matrix more closely.
- **Player record:** guardian contact details and custom fields come back to staff only, and reading them is logged.
- **Player report snapshots:** saving, sharing and adding notes need the right to create reports, and each is logged.
- **Trial cases:** the list shows the cases the user has a reason to see: the cases whose panel they sit on, the cases of players a scout is linked to, and, for a head coach, the cases on their own teams, trialists included. The screen and the API show the same list.
- **Archiving and restoring:** archiving or restoring a team, and restoring a player, check that the user may manage that team or edit that player, as editing already did.
- **Planned attendance:** an activity's expected roster is read by the staff of that activity's team; a player or parent sees only their own child's row, on an activity that child is part of. An activity's principles and result follow the same read as the activity itself.
- **Team activity and planner exports:** cover the teams the user may export, like the other team exports.
- **KPI snapshot export:** the academy-wide snapshot is for people who read all teams' reports, and its per-player potential sheet only for those who also read potential bands across the academy.
- **Player administration:** season rollover, player login accounts, custom-field definitions and deleting players stay with the Head of Development and the Club Admin in every authorization setting, including staff accounts on installs that do not use the access matrix.

Staff-only notes on a team also count only on that team's own threads.
