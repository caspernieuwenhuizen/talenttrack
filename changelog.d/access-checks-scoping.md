# Tighter access checks on player, team, report and trial data

Bump: patch

Several reads and writes now follow the access matrix more closely.
- **Player record:** guardian contact details and custom fields come back to staff only, and reading them is logged.
- **Player report snapshots:** saving, sharing and adding notes need the right to create reports, and each is logged.
- **Trial cases:** the list shows the cases the user has a reason to see: the cases whose panel they sit on, the cases of players a scout is linked to, and, for a head coach, the cases on their own teams, trialists included. The screen and the API show the same list.
- **Archiving and restoring:** archiving or restoring a team, and restoring a player, check that the user may manage that team or edit that player, as editing already did.
- **Player administration:** season rollover, player login accounts, custom-field definitions and deleting players stay with the Head of Development and the Club Admin in every authorization setting, including staff accounts on installs that do not use the access matrix.

Staff-only notes on a team also count only on that team's own threads.
