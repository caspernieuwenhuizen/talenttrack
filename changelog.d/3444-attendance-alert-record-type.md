# The "attendance not recorded" alert now reads the register, not the plan (#3444)

Bump: patch

`tt_attendance` holds the roster you plan ahead of an activity and the register
you take on the day in the same table, separated only by a record type — and the
planned rows carry real statuses, since "expected" is stored as "present". The
alert built for exactly one failure ("this activity is completed and nobody
recorded who was there") counted a planned roster as a register, so the most
common version of that failure was invisible to it: tick the expected roster at
creation, never take the register, mark the activity completed, and the alert
stayed silent. It now looks only at non-guest rows from the register itself. A
guest row is somebody else's player turning out and says nothing about whether
this squad was registered.

The same query gated completion on `plan_state`, which is `completed` by default
on every create path except the team planner — so the alert also fired on
activities nobody had completed, which is the "past activity still planned"
alert's subject, not this one's. It now uses the shared lifecycle predicate that
reads the status the coach actually set.

Both directions are covered by tests, including that an alert already raised on
a plan-only activity resolves itself on the next sweep once the register is
taken. No wording changed and nothing is stored differently; existing open
occurrences are re-evaluated on the next hourly sweep.
