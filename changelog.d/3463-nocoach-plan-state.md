# The "nobody is running this activity" alert now sees the activities nobody planned in the planner (#3463)

An activity in the next week with no coach assigned is the one problem the
alerts engine can still prevent rather than report — there is time to fix it
before a squad loses a training to a late cancellation.

It was reading the planner's workflow column. That column arrives set to
`completed` on every create path except the team planner itself, so an
uncoached activity made by the activity wizard, the flat form or the Spond
import failed the check however plainly it read "Planned" on screen. The alert
fired only for activities the team planner happened to create.

It now reads the status the coach actually sets, through the shared lifecycle
predicate the previous two fixes in this area introduced. A cancelled activity
still raises nothing — it never needs a coach — and neither does one somebody
has already finished with.

This is the third bug from the same column and the last alert definition that
read it. Nothing is stored differently; open occurrences are re-evaluated on
the next hourly sweep.
