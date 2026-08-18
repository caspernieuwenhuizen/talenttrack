# Attendance reports count only activities marked completed (#2521, #2522, #2523)

Bump: patch

Attendance statistics counted sessions that had not been held. An activity
reading **Status: Planned** on screen still reached the reports, because the
reports gated on an internal planner column that arrives set to "completed" on
every activity the planner did not create — which is every Spond import and
every activity added from the form or the wizard. The team and player
attendance reports, the leaderboard, the at-risk panel, the daily
attendance-flag notification, the team KPI tiles and the **Activities** badge on
a player's file now all read the status shown on the activity page, so a
planned session contributes nothing until it is marked completed and the
figures can be checked against what is on screen.

Because recording who was there is itself the statement that a session took
place, **saving the attendance grid now marks the past-dated planned activities
it wrote to as completed**, and the save bar reports how many. Future-dated
sessions and activities marked Cancelled are never completed this way. This
replaces the previous behaviour, where grid entry deliberately left completion
to a separate click — under the new gate that would have meant a coach's entry
never reaching the reports.

An activity's **Attendance** card and the **Present** figure in its stat strip
counted the planned roster on top of the recorded register, so an activity with
both could report more players present than the squad holds — "28 / 15
present". Both now count recorded attendance only. The **Present** figure also
waits for the activity to be completed, matching the Attendance card below it,
rather than stating a turnout for a session that has not happened.
