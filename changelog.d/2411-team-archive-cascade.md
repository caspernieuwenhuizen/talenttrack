# Archiving a team can archive its activities too (#2411)

Bump: patch

Archiving a team used to leave its trainings and matches fully active, so a
retired age group's sessions kept turning up on planners, dashboards and
reports long after the team was gone.

The confirmation dialog now offers **"Also archive this team's N activities"**,
ticked by default, with the count taken from the team's still-active
activities. Untick it to archive the team on its own.

**Players are deliberately left alone.** A player outlives their team — they
move up an age group or transfer the same week — so their record stays active
and simply has no team until you assign one.

**Restoring the team brings those activities back**, but only the ones this
cascade archived: anything you had archived by hand beforehand stays archived,
so restoring a team never revives something you deliberately put away.

Upgrading also sweeps up the activities of teams archived *before* this
shipped, so they stop cluttering live views.
