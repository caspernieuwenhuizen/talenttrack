# A team Manager sees the schedule, takes the register and reads player availability (#3567)

The Manager functional role granted nothing, so a Staff account assigned as
Manager of a team got "not authorised" on the team's activities, attendance
and player-status board. A Kit manager on the same team could read the
schedule. On the team where they hold the role, Manager now reads the squad,
the people around it and the activity calendar, records attendance (including
through the attendance grid) and reads the status traffic light. It still
doesn't create or edit activities. It gets no injury access: a manager who
also does first aid is given Physio as a second role on the team. A Staff
account that isn't on any team yet now sees a dashboard notice saying so,
instead of empty tiles.
