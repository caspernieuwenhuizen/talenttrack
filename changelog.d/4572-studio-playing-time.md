# Report Studio: playing time, minutes per match, goals and assists, and minutes share blocks (#4572)

Bump: minor

The Report Studio gets its playing-time blocks, each on the read of the report it comes from. Playing time per player now reads the team monthly report's minutes section: every squad player, the ones who never got on at 0, with their share of the minutes available against the academy's target. Those minutes follow each match's own length, not a fixed norm. Minutes per match shows one player's minutes in each match, as the minutes report does. Goals and assists come from the match goal log, for a team as the monthly report's scorers and for a player as their own. Share of available minutes reads the minutes-share report. A family or a scout sees only the player's own figures, never another child's minutes or a group average. Tests hold every block to the report it comes from.
