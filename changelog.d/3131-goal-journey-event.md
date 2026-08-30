# Goals reach the player's journey whichever screen wrote them (#3131)

`tt_goals` had six write paths and exactly one of them — the REST endpoint —
announced the new goal, so only goals created that way reached the player's
journey. A goal set in the new-goal wizard was invisible on the timeline while
the identical goal set through the API was not, and the wizard is the path the
product steers a coach onto. The wp-admin form, the season rollover and the
development-idea spawner were silent for the same reason.

The insert, the demo tagging and the announcement now live in
`GoalsRepository::create()`, and every path goes through it. A journey entry
says which one wrote it, because they do not mean the same thing: *Goal set* for
a decision somebody took, *Goal carried over* for the season rollover, *Goal
opened from a development idea* for a spawned one. Carried-over entries are
dated to the start of the new season rather than to the day the rollover ran, so
a rollover done three weeks late still reads as the season's start.

Goals created before this stay off the timeline until the journey is rebuilt,
and a rebuild cannot tell how an old goal came to exist — every backfilled entry
reads as *Goal set*.
