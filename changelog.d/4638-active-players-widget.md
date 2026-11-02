# Active players widget leaves out released players (#4638)

The "Active players" source for custom widgets counted every player who was not archived by status, so released, trial and inactive players showed up as active. A club-wide count on the demo read 83 instead of 67. The source now uses the same rule as the team roster and the players list's active filter: status active, not archived, not in the recycle bin. The widget's count matches the list's active count again.
