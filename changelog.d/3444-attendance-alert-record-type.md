# The "Attendance not recorded" alert now catches the case it was built for (#3444)

The alert exists to flag an activity that was marked completed with nobody
recorded as present. It was missing the two most common versions of that,
and firing on activities nobody had completed.

`tt_attendance` stores the planned roster and the recorded register in the
same table, separated only by `record_type`, and planned rows carry real
statuses — "Expected" is stored as Present. So a coach who ticked the
expected roster before the session left rows behind that made the activity
look recorded while the register was still empty. Guest rows had the same
effect. The alert now counts only non-guest rows from the recorded
register, matching how the attendance reports and team KPIs already scope.

It also gated on `plan_state`, a column that carries a default of
'completed' and that only the team planner ever sets, so it fired on
activities that were still planned — work that "Activity still planned"
already reports. It now reads the activity's own status, so each of those
two alerts covers its own case.

Coaches who ticked an expected roster and never recorded the register will
see alerts appear for those past activities. That is the gap becoming
visible, not new breakage.
