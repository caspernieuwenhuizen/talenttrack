# Completing an activity with nobody on the register now asks first (#3446)

Five paths reached "completed" and none of them checked whether any
attendance had been recorded, so an activity could close with every
player's participation for that date missing — and read "Completed" on
every screen afterwards, which is why nobody went back for it.

Completing an activity with an empty register now raises a dialog that
names the gap and offers the remedy beside the override: **Record
attendance** goes to that team's attendance grid on the activity's own
date and leaves the activity planned, **Complete anyway** completes it,
and Cancel or Escape means don't. It fires on the wizard's Skip branch
and on the wizard-off **Mark completed** button.

It warns rather than forbids, because empty registers are sometimes
correct — an imported fixture, a club-wide activity with no squad, a match
played with a borrowed team. A *partly* recorded register raises nothing:
marking the eight players you were sure about is a real answer, and a
dialog that fires on it is one coaches learn to tap past. Meetings, *other*
activities and activities with no team complete in silence — there is no
roster whose participation could go missing.

The wizard's "Rate now?" step also stops claiming "Attendance is saved."
on an activity that has none.

For integrators: `POST /activities/{id}/status` now returns
`register_state` (`none` / `partial` / `complete` / `not_applicable`), so a
non-WordPress client can raise the same warning instead of inventing its
own definition of an empty register.
