# A session cancelled in the planner is no longer upcoming or chased (#5116)

A session the coach called off in the team planner keeps its status as
planned and sets its plan state to cancelled. It used to stay on the schedule:
in a team's coming-up list and next matches, in the per-team upcoming count,
and in the alerts that ask a coach to mark an activity or assign a coach. It
now leaves all of them, as a session cancelled through its status already did.
A session with no plan state at all is still on the schedule.
