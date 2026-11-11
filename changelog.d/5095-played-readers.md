# Goals, the minutes audit and the activity lists read the one played rule (#5095)

The goals-and-assists figures (the Statistics tab's scorers and assists, the
goals drill-down, the player's goals), the minutes audit and the activities
list's "played" drill now count the matches every other report counts as
played, through the one rule. Activity lists and planner counts read "not
cancelled" and "still to do" through the shared lifecycle clauses.

Counts that change:

- Goals and assists logged on a match dated after today no longer count until
  that match is played.
- The minutes audit no longer lists a match that has not been played yet (a
  match today before kick-off, or one dated after today) as a row of missing
  minutes.
- A session cancelled through the team planner (its plan state) is now left
  out of the coach's activities for a week window, as it already was
  everywhere else; before, only a status of cancelled left it out.
