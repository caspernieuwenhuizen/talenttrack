# Past activity still planned: the alert now sees the activities it exists for (#3452)

The "Past activity still planned" alert asked the wrong column. It gated on
`plan_state`, which was added `DEFAULT 'completed'` and which only the team
planner ever sets — so an activity created by the activity wizard, the flat
form or the Spond import read as completed in the database however it looked
on screen, and the one alert built to catch an unmarked activity could not
see it. In practice it fired only for activities the planner happened to
create.

That mattered more from this release on: the previous release tightened the
"Attendance not recorded" alert to fire only on genuinely completed
activities, which was right, and which left a past, unfinished activity
raising neither alert. Nobody was told.

The gate now reads `activity_status_key` — the status the coach actually
sets, and the axis all reporting has used since the same class of bug was
fixed there — through a shared `ActivityLifecycle::outstandingClause()` so
the two alert definitions cannot drift apart about what the lifecycle means
again. A cancelled activity still raises nothing: it never happened and never
will, so there is nothing to chase. The alert remains state-derived, and
marking the activity completed or cancelled clears it on the next sweep with
nothing to dismiss.
