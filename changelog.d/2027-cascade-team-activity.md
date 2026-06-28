# Teams and activities can now be permanently deleted, player data preserved (#2027)

Bump: patch

Permanently deleting a team or an activity used to be refused outright while
anything still referenced it, so a trashed team or activity could never be
purged and accumulated in the recycle bin forever. Both now have a complete,
player-centric delete plan.

Deleting a **team** removes only pure team configuration (formations, playing
styles, chemistry, blueprints, staff assignments, per-team exercise overrides
and the VCT periodization stack). The team's players, their team history, the
team's activities (with their attendance and evaluations), tournaments and
measurement sessions are all kept and re-homed to "unassigned" rather than
deleted. Open invitations, workflow tasks and staged ideas pointing at the
team simply have the link cleared.

Deleting an **activity** removes the execution data that only lives inside it
(attendance, planned exercises, principles, and the match-prep and
match-execution trees) plus its journey events, while evaluations, behaviour
ratings and tournament/VCT bindings survive with their link cleared.

A development record is never destroyed by deleting a team or activity — worst
case it is left unassigned. The deletion framework gains a "reset to
unassigned" disposition for required links that can't be emptied, and a
fail-closed completeness check guarantees a future schema change can't quietly
make teams or activities un-deletable again.
